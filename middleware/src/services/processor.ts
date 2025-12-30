/**
 * Migration Processor Service
 * 
 * Handles the actual processing of migration jobs:
 * - Extracting zip files
 * - Uploading files to Appwrite
 * - Sending webhook updates
 */

import { existsSync } from 'fs';
import { mkdir, rm, readFile, writeFile } from 'fs/promises';
import { join, basename } from 'path';
import { config } from '../config';
import { queueService } from './queue';
import { uploadWithRetry, getCdnUrl } from './appwrite';
import { sendWebhook } from './webhook';
import type { 
    MigrationJob, 
    MigrationMetadata, 
    AttachmentMetadata, 
    UploadResult, 
    MigrationError,
    WebhookPayload 
} from '../types';

/**
 * Extract zip file to directory
 */
export async function extractZip(zipPath: string, extractDir: string): Promise<void> {
    const proc = Bun.spawn(['unzip', '-o', zipPath, '-d', extractDir], {
        stdout: 'pipe',
        stderr: 'pipe',
    });

    const exitCode = await proc.exited;
    if (exitCode !== 0) {
        const stderr = await new Response(proc.stderr).text();
        throw new Error(`Failed to extract zip: ${stderr}`);
    }
}

/**
 * Process a single attachment
 */
async function processAttachment(
    attachment: AttachmentMetadata,
    extractDir: string,
    accountEmail: string,
    checkCancelled: () => Promise<boolean>
): Promise<{ result?: UploadResult; errors: MigrationError[]; filesUploaded: number }> {
    const errors: MigrationError[] = [];
    let filesUploaded = 0;
    const result: UploadResult = {
        attachment_id: attachment.attachment_id,
        file_id: '',
        cdn_url: '',
        sizes: {},
    };

    // Check if cancelled before processing
    if (await checkCancelled()) {
        return { result: undefined, errors: [], filesUploaded: 0 };
    }

    // Helper to abort if cancelled
    const throwIfCancelled = async () => {
        if (await checkCancelled()) {
            throw new Error('MIGRATION_CANCELLED');
        }
    };

    // Upload original file
    const originalPath = join(extractDir, attachment.original_file);
    if (existsSync(originalPath)) {
        try {
            // Check for cancellation before upload
            await throwIfCancelled();
            
            const fileId = await uploadWithRetry(
                originalPath,
                basename(attachment.original_file),
                accountEmail,
                attachment.local_url
            );
            result.file_id = fileId;
            result.cdn_url = getCdnUrl(fileId);
            filesUploaded++;
        } catch (error) {
            // Don't treat cancellation as an error
            if ((error as Error).message === 'MIGRATION_CANCELLED') {
                return { result: undefined, errors: [], filesUploaded };
            }
            errors.push({
                attachment_id: attachment.attachment_id,
                file: attachment.original_file,
                error: (error as Error).message,
            });
        }
    } else {
        errors.push({
            attachment_id: attachment.attachment_id,
            file: attachment.original_file,
            error: 'File not found in zip',
        });
    }

    // Upload sizes
    for (const size of attachment.sizes) {
        // Check for cancellation between each size
        try {
            await throwIfCancelled();
        } catch {
            // Cancellation detected, return partial result
            return { result: result.file_id ? result : undefined, errors, filesUploaded };
        }

        const sizePath = join(extractDir, size.file);
        if (existsSync(sizePath)) {
            try {
                const fileId = await uploadWithRetry(
                    sizePath,
                    basename(size.file),
                    accountEmail,
                    size.local_url
                );
                result.sizes[size.name] = {
                    file_id: fileId,
                    url: getCdnUrl(fileId),
                };
                filesUploaded++;
            } catch (error) {
                // Don't treat cancellation as an error
                if ((error as Error).message === 'MIGRATION_CANCELLED') {
                    return { result: result.file_id ? result : undefined, errors, filesUploaded };
                }
                errors.push({
                    attachment_id: attachment.attachment_id,
                    file: size.file,
                    error: (error as Error).message,
                });
            }
        } else {
            errors.push({
                attachment_id: attachment.attachment_id,
                file: size.file,
                error: 'Size file not found in zip',
            });
        }
    }

    return {
        result: result.file_id ? result : undefined,
        errors,
        filesUploaded,
    };
}

/**
 * Process a migration job
 */
export async function processJob(job: MigrationJob): Promise<void> {
    const { metadata, extract_dir: extractDir } = job;
    
    console.log(`\n📦 PROCESSING JOB: ${job.id}`);
    console.log(`   Migration ID: ${job.migration_id}`);
    console.log(`   Zip Batch: ${job.zip_batch_number}`);
    console.log(`   Attachments: ${metadata.attachments.length}`);

    // Update job status
    await queueService.updateJob(job.id, {
        status: 'processing',
        started_at: new Date().toISOString(),
    });

    // Helper to check cancellation
    const checkCancelled = async () => {
        return await queueService.isMigrationCancelled(job.migration_id);
    };

    const results: UploadResult[] = [];
    const errors: MigrationError[] = [];
    let totalFilesUploaded = 0;

    const batchSize = Math.max(1, config.batchSize || 10);
    const attachments = metadata.attachments;
    const totalBatches = Math.ceil(attachments.length / batchSize) || 1;

    for (let i = 0; i < attachments.length; i += batchSize) {
        // Check for cancellation at start of each batch
        if (await checkCancelled()) {
            console.log(`   🚫 Migration cancelled, stopping processing`);
            break;
        }

        const batch = attachments.slice(i, i + batchSize);
        const batchNumber = Math.floor(i / batchSize) + 1;
        console.log(`\n   📥 BATCH ${batchNumber}/${totalBatches} (${batch.length} attachments)`);

        const batchPromises = batch.map(attachment =>
            processAttachment(attachment, extractDir, metadata.account_email, checkCancelled)
        );

        const batchResults = await Promise.all(batchPromises);

        // Check again after batch completes
        if (await checkCancelled()) {
            console.log(`   🚫 Migration cancelled during batch, will skip remaining batches`);
        }

        const batchPayloadResults: UploadResult[] = [];
        const batchPayloadErrors: MigrationError[] = [];
        let batchSuccessCount = 0;
        let batchFailCount = 0;
        let batchFilesUploaded = 0;

        for (const { result, errors: attachmentErrors, filesUploaded } of batchResults) {
            if (result) {
                results.push(result);
                batchPayloadResults.push(result);
                batchSuccessCount++;
            } else {
                batchFailCount++;
            }
            batchFilesUploaded += filesUploaded;

            if (attachmentErrors.length > 0) {
                errors.push(...attachmentErrors);
                batchPayloadErrors.push(...attachmentErrors);
            }
        }

        totalFilesUploaded += batchFilesUploaded;

        const successEmoji = batchFailCount === 0 ? '✅' : '⚠️';
        console.log(`      ${successEmoji} Batch complete: ${batchSuccessCount} passed, ${batchFailCount} failed`);
        console.log(`      📊 Running total: ${results.length} passed, ${errors.length} errors, ${totalFilesUploaded} files uploaded`);

        // Update job progress
        await queueService.updateJob(job.id, {
            progress: {
                ...job.progress,
                processed_attachments: i + batch.length,
                uploaded_files: totalFilesUploaded,
                failed_files: errors.length,
            },
        });

        // Update migration totals
        const migration = await queueService.getMigration(job.migration_id);
        if (migration) {
            await queueService.updateMigration(job.migration_id, {
                totals: {
                    ...migration.totals,
                    processed_attachments: migration.totals.processed_attachments + batchSuccessCount,
                    uploaded_files: migration.totals.uploaded_files + batchFilesUploaded,
                    failed_files: migration.totals.failed_files + batchPayloadErrors.length,
                },
            });
        }

        // Send batch webhook
        console.log(`      📤 Sending batch webhook...`);
        await sendWebhook(metadata.webhook_url, metadata.webhook_secret, {
            migration_id: metadata.migration_id,
            status: 'batch',
            results: batchPayloadResults,
            errors: batchPayloadErrors,
            batch_number: batchNumber,
            total_batches: totalBatches,
            processed: results.length,
            failed: errors.length,
            total_files_uploaded: totalFilesUploaded,
        });

        // Check for cancellation after webhook before proceeding
        if (await checkCancelled()) {
            console.log(`   🚫 Stopping after batch due to cancellation request`);
            break;
        }
    }

    // Check if cancelled
    const wasCancelled = await checkCancelled();
    
    // Determine final status
    let status: 'completed' | 'partial' | 'failed' | 'cancelled';
    if (wasCancelled) {
        status = 'cancelled';
    } else if (errors.length === 0) {
        status = 'completed';
    } else if (results.length > 0) {
        status = 'partial';
    } else {
        status = 'failed';
    }

    const statusEmoji = status === 'completed' ? '✅' : 
                       status === 'cancelled' ? '🚫' : 
                       status === 'partial' ? '⚠️' : '❌';

    console.log(`\n🏁 JOB ${job.id} COMPLETE`);
    console.log(`   ${statusEmoji} Status: ${status.toUpperCase()}`);
    console.log(`   ✅ Processed: ${results.length} attachments`);
    console.log(`   📤 Uploaded: ${totalFilesUploaded} files`);
    console.log(`   ❌ Errors: ${errors.length}`);

    // Update job as completed
    await queueService.updateJob(job.id, {
        status: wasCancelled ? 'cancelled' : (errors.length === results.length && errors.length > 0 ? 'failed' : 'completed'),
        completed_at: new Date().toISOString(),
        progress: {
            ...job.progress,
            processed_attachments: metadata.attachments.length,
            uploaded_files: totalFilesUploaded,
            failed_files: errors.length,
        },
    });

    // Mark job complete in queue
    await queueService.completeJob(job.id);

    // Check if this is the last job for the migration
    const migrationJobs = await queueService.getJobsByMigration(job.migration_id);
    const pendingJobs = migrationJobs.filter(j => j.status === 'pending' || j.status === 'processing');
    
    if (pendingJobs.length === 0) {
        // All jobs complete, send final webhook
        const migration = await queueService.getMigration(job.migration_id);
        if (migration) {
            const finalStatus = wasCancelled ? 'cancelled' : 
                              migration.totals.failed_files === 0 ? 'completed' : 
                              migration.totals.processed_attachments > 0 ? 'partial' : 'failed';

            await queueService.updateMigration(job.migration_id, {
                status: finalStatus === 'cancelled' ? 'cancelled' : 
                       finalStatus === 'failed' ? 'failed' : 'completed',
            });

            console.log(`\n🔔 SENDING FINAL WEBHOOK for migration ${job.migration_id}`);
            await sendWebhook(metadata.webhook_url, metadata.webhook_secret, {
                migration_id: job.migration_id,
                status: finalStatus,
                results: [],
                errors: errors,
                processed: migration.totals.processed_attachments,
                failed: migration.totals.failed_files,
                total_files_uploaded: migration.totals.uploaded_files,
            });
        }
    }

    // Cleanup extraction directory
    console.log(`\n🧹 Cleaning up ${extractDir}`);
    try {
        await rm(extractDir, { recursive: true, force: true });
        // Also remove the parent temp directory if empty
        const parentDir = join(extractDir, '..');
        await rm(parentDir, { recursive: true, force: true });
    } catch (e) {
        console.warn(`   ⚠️  Cleanup warning: ${(e as Error).message}`);
    }
}

/**
 * Job processor worker - continuously processes jobs from queue
 */
let isProcessing = false;
let shouldStop = false;

export async function startWorker(): Promise<void> {
    if (isProcessing) {
        console.log('⚠️  Worker already running');
        return;
    }

    shouldStop = false;
    isProcessing = true;
    console.log('🚀 Job worker started');

    try {
        await queueService.connect();
    } catch (error) {
        console.error('❌ Worker could not connect to Redis:', (error as Error).message);
        isProcessing = false;
        return;
    }

    // First, recover any jobs that were processing when we stopped
    const recovered = await queueService.recoverProcessingJobs();
    if (recovered > 0) {
        console.log(`🔄 Recovered ${recovered} interrupted jobs`);
    }

    while (!shouldStop) {
        try {
            const jobId = await queueService.dequeueJob();
            
            if (!jobId) {
                // No jobs in queue, wait a bit
                await new Promise(resolve => setTimeout(resolve, 1000));
                continue;
            }

            const job = await queueService.getJob(jobId);
            if (!job) {
                console.warn(`⚠️  Job ${jobId} not found, skipping`);
                continue;
            }

            if (job.status === 'cancelled') {
                console.log(`🚫 Job ${jobId} was cancelled, skipping`);
                await queueService.completeJob(jobId);
                continue;
            }

            await processJob(job);
        } catch (error) {
            console.error('❌ Worker error:', (error as Error).message);
            await new Promise(resolve => setTimeout(resolve, 5000));
        }
    }

    isProcessing = false;
    console.log('🛑 Job worker stopped');
}

export function stopWorker(): void {
    shouldStop = true;
}

export function isWorkerRunning(): boolean {
    return isProcessing;
}
