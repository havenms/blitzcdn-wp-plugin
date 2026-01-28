/**
 * HTTP Request Handlers
 */

import { mkdir, rm, readFile, writeFile } from 'fs/promises';
import { existsSync } from 'fs';
import { join } from 'path';
import { config, validateConfig } from './config';
import { queueService, sendWebhook, extractZip, startWorker, isWorkerRunning } from './services';
import type { MigrationMetadata, MigrationJob, MigrationState } from './types';

/**
 * Verify API key from request
 */
export function verifyApiKey(request: Request): boolean {
    if (!config.apiKey) {
        return true; // No API key configured, allow all
    }

    const authHeader = request.headers.get('Authorization');
    if (authHeader && authHeader.startsWith('Bearer ')) {
        const token = authHeader.substring(7);
        return token === config.apiKey;
    }

    return false;
}

/**
 * Handle migration zip upload
 */
export async function handleMigrate(request: Request): Promise<Response> {
    if (!verifyApiKey(request)) {
        return new Response(JSON.stringify({ error: 'Unauthorized' }), {
            status: 401,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    const formData = await request.formData();
    const file = formData.get('file') as File | null;
    const migrationId = formData.get('migration_id') as string || '';

    if (!file) {
        return new Response(JSON.stringify({ error: 'No file provided' }), {
            status: 400,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    // Create temporary directories
    const jobId = `job-${migrationId}-${Date.now()}`;
    const tempDir = join(config.dataDir, 'jobs', jobId);
    const zipPath = join(tempDir, 'migration.zip');
    const extractDir = join(tempDir, 'extracted');

    try {
        await mkdir(tempDir, { recursive: true });
        await mkdir(extractDir, { recursive: true });

        // Save uploaded zip with size validation
        console.log(`\n📥 RECEIVING ZIP`);
        const arrayBuffer = await file.arrayBuffer();
        const zipSizeMB = arrayBuffer.byteLength / (1024 * 1024);

        console.log(`   📦 Size: ${zipSizeMB.toFixed(2)} MB`);

        if (zipSizeMB > config.maxZipSizeMB) {
            throw new Error(`Zip file too large (${zipSizeMB.toFixed(2)} MB). Maximum allowed: ${config.maxZipSizeMB} MB.`);
        }

        console.log(`   💾 Writing to disk...`);
        await writeFile(zipPath, Buffer.from(arrayBuffer));

        // Extract zip
        console.log(`\n📂 EXTRACTING ZIP`);
        await extractZip(zipPath, extractDir);
        console.log(`   ✅ Extraction complete`);

        // Remove zip file to save space
        await rm(zipPath, { force: true });

        // Read metadata.json
        const metadataPath = join(extractDir, 'metadata.json');
        if (!existsSync(metadataPath)) {
            throw new Error('metadata.json not found in zip');
        }

        const metadataContent = await readFile(metadataPath, 'utf-8');
        const metadata: MigrationMetadata = JSON.parse(metadataContent);

        console.log(`\n📋 MIGRATION METADATA`);
        console.log(`   Migration ID: ${metadata.migration_id}`);
        console.log(`   Attachments: ${metadata.attachments.length}`);
        console.log(`   From: ${metadata.site_url}`);
        console.log(`   Account: ${metadata.account_email}`);

        // Count total files (originals + sizes)
        let totalFiles = 0;
        for (const attachment of metadata.attachments) {
            totalFiles += 1 + attachment.sizes.length;
        }

        // Get or create migration state
        let migration = await queueService.getMigration(metadata.migration_id);
        let zipBatchNumber = 1;

        if (migration) {
            // Existing migration - this is an additional zip batch
            zipBatchNumber = migration.zip_batches.length + 1;
            migration = await queueService.updateMigration(metadata.migration_id, {
                zip_batches: [...migration.zip_batches, jobId],
                totals: {
                    ...migration.totals,
                    total_attachments: migration.totals.total_attachments + metadata.attachments.length,
                    total_files: migration.totals.total_files + totalFiles,
                },
            });
        } else {
            // New migration
            const newMigration: MigrationState = {
                migration_id: metadata.migration_id,
                site_url: metadata.site_url,
                account_email: metadata.account_email,
                webhook_url: metadata.webhook_url,
                webhook_secret: metadata.webhook_secret,
                status: 'active',
                created_at: new Date().toISOString(),
                updated_at: new Date().toISOString(),
                zip_batches: [jobId],
                totals: {
                    total_attachments: metadata.attachments.length,
                    processed_attachments: 0,
                    total_files: totalFiles,
                    uploaded_files: 0,
                    failed_files: 0,
                },
            };
            await queueService.createMigration(newMigration);
            migration = newMigration;
        }

        // Create job
        const job: MigrationJob = {
            id: jobId,
            migration_id: metadata.migration_id,
            zip_batch_number: zipBatchNumber,
            status: 'pending',
            metadata,
            extract_dir: extractDir,
            created_at: new Date().toISOString(),
            progress: {
                total_attachments: metadata.attachments.length,
                processed_attachments: 0,
                total_files: totalFiles,
                uploaded_files: 0,
                failed_files: 0,
            },
        };

        await queueService.createJob(job);
        await queueService.enqueueJob(job.id);

        console.log(`\n✅ JOB QUEUED: ${job.id}`);
        console.log(`   Zip batch: ${zipBatchNumber}`);
        console.log(`   Queue position: ${await queueService.getQueueLength()}`);

        const totalBatches = Math.max(1, Math.ceil(metadata.attachments.length / Math.max(1, config.batchSize || 10)));

        // Send confirmation webhook IMMEDIATELY so user can close browser
        console.log(`\n🔔 SENDING CONFIRMATION WEBHOOK`);
        console.log(`   Status: RECEIVED (safe-to-quit confirmed)`);

        try {
            const confirmSent = await sendWebhook(metadata.webhook_url, metadata.webhook_secret, {
                migration_id: metadata.migration_id,
                status: 'received',
                results: [],
                errors: [],
                total_batches: totalBatches,
                processed: 0,
                failed: 0,
                safe_to_quit: true,
                total_files_uploaded: 0,
                message: 'Zip uploaded successfully. Processing will continue in the background. You can safely close this page.',
            });

            if (confirmSent) {
                console.log(`   ✅ User can close browser - processing continues in background`);
            } else {
                console.warn(`   ⚠️  Webhook delivery failed, but processing will continue`);
            }
        } catch (confirmError) {
            console.error(`   ❌ Webhook error: ${confirmError}`);
        }

        // Ensure worker is running
        if (!isWorkerRunning()) {
            startWorker().catch(console.error);
        }

        return new Response(JSON.stringify({
            success: true,
            message: 'Migration queued',
            migration_id: metadata.migration_id,
            job_id: job.id,
            zip_batch_number: zipBatchNumber,
            attachment_count: metadata.attachments.length,
            total_files: totalFiles,
            queue_position: await queueService.getQueueLength(),
        }), {
            status: 202,
            headers: { 'Content-Type': 'application/json' },
        });

    } catch (error) {
        console.error('Migration error:', error);

        // Cleanup on error
        try {
            await rm(tempDir, { recursive: true, force: true });
        } catch { }

        return new Response(JSON.stringify({
            error: 'Migration failed',
            message: (error as Error).message,
        }), {
            status: 500,
            headers: { 'Content-Type': 'application/json' },
        });
    }
}

/**
 * Handle migration cancellation
 */
export async function handleCancel(request: Request): Promise<Response> {
    if (!verifyApiKey(request)) {
        return new Response(JSON.stringify({ error: 'Unauthorized' }), {
            status: 401,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    try {
        const body = await request.json() as { migration_id?: string };
        const migrationId = body.migration_id;

        if (!migrationId) {
            return new Response(JSON.stringify({ error: 'migration_id is required' }), {
                status: 400,
                headers: { 'Content-Type': 'application/json' },
            });
        }

        console.log(`\n🚫 CANCELLATION REQUEST`);
        console.log(`   Migration ID: ${migrationId}`);

        const result = await queueService.cancelMigration(migrationId);

        // Send cancellation webhook to WordPress
        const migration = await queueService.getMigration(migrationId);
        if (migration) {
            await sendWebhook(migration.webhook_url, migration.webhook_secret, {
                migration_id: migrationId,
                status: 'cancelled',
                results: [],
                errors: [],
                processed: migration.totals.processed_attachments,
                failed: migration.totals.failed_files,
                total_files_uploaded: migration.totals.uploaded_files,
                message: 'Migration cancelled by user',
            });
        }

        console.log(`   ✅ ${result.message}`);

        return new Response(JSON.stringify({
            success: true,
            ...result,
        }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });

    } catch (error) {
        console.error('Cancel error:', error);
        return new Response(JSON.stringify({
            error: 'Cancel failed',
            message: (error as Error).message,
        }), {
            status: 500,
            headers: { 'Content-Type': 'application/json' },
        });
    }
}

/**
 * Handle migration status request
 */
export async function handleStatus(request: Request): Promise<Response> {
    if (!verifyApiKey(request)) {
        return new Response(JSON.stringify({ error: 'Unauthorized' }), {
            status: 401,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    const url = new URL(request.url);
    const migrationId = url.searchParams.get('migration_id');

    if (migrationId) {
        // Get specific migration status
        const migration = await queueService.getMigration(migrationId);
        if (!migration) {
            return new Response(JSON.stringify({ error: 'Migration not found' }), {
                status: 404,
                headers: { 'Content-Type': 'application/json' },
            });
        }

        const jobs = await queueService.getJobsByMigration(migrationId);

        return new Response(JSON.stringify({
            migration,
            jobs: jobs.map(j => ({
                id: j.id,
                zip_batch_number: j.zip_batch_number,
                status: j.status,
                progress: j.progress,
                created_at: j.created_at,
                started_at: j.started_at,
                completed_at: j.completed_at,
            })),
        }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    // Get overall queue status
    const stats = await queueService.getStats();
    const activeMigrations = await queueService.getActiveMigrations();

    return new Response(JSON.stringify({
        stats,
        activeMigrations: activeMigrations.map(m => ({
            migration_id: m.migration_id,
            site_url: m.site_url,
            status: m.status,
            totals: m.totals,
            created_at: m.created_at,
        })),
        worker_running: isWorkerRunning(),
    }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

/**
 * Handle health check
 */
export async function handleHealth(): Promise<Response> {
    const errors = validateConfig();
    
    let redisStatus = 'unknown';
    let queueStats = { pendingJobs: 0, processingJobs: 0 };
    
    try {
        queueStats = await queueService.getStats();
        redisStatus = 'connected';
    } catch (e) {
        redisStatus = 'disconnected';
        errors.push('Redis not connected');
    }

    return new Response(JSON.stringify({
        status: errors.length === 0 ? 'ok' : 'degraded',
        timestamp: new Date().toISOString(),
        config: {
            appwrite: {
                endpoint: config.appwrite.endpoint,
                projectId: config.appwrite.projectId ? '***configured***' : 'not configured',
                bucketId: config.appwrite.bucketId ? '***configured***' : 'not configured',
            },
            parallelUploads: config.parallelUploads,
            batchSize: config.batchSize,
            maxRetries: config.maxRetries,
        },
        redis: redisStatus,
        queue: queueStats,
        worker_running: isWorkerRunning(),
        errors,
    }), {
        status: errors.length === 0 ? 200 : 503,
        headers: { 'Content-Type': 'application/json' },
    });
}

/**
 * Handle root endpoint
 */
export function handleRoot(): Response {
    return new Response(JSON.stringify({
        name: 'BlitzCDN Middleware',
        version: '2.0.0',
        endpoints: {
            '/api/migrate': 'POST - Upload zip for migration',
            '/api/cancel': 'POST - Cancel a migration by ID',
            '/api/status': 'GET - Get migration/queue status',
            '/health': 'GET - Health check',
        },
    }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}
