#!/usr/bin/env bun

/**
 * BlitzCDN Middleware Server
 * 
 * This Bun-powered server handles zip-based media migrations from WordPress.
 * It receives zip files containing media files and metadata, processes them
 * in parallel, uploads to Appwrite Storage, and sends results back via webhook.
 */

import { Client, Storage, Databases, type Models } from 'node-appwrite';
import { mkdir, rm, readFile, writeFile } from 'fs/promises';
import { existsSync } from 'fs';
import { join, basename } from 'path';

// Configuration from environment
const config = {
    port: parseInt(process.env.PORT || '3000'),
    host: process.env.HOST || '0.0.0.0',
    apiKey: process.env.API_KEY || '',
    appwrite: {
        endpoint: process.env.APPWRITE_ENDPOINT || 'https://cloud.appwrite.io/v1',
        projectId: process.env.APPWRITE_PROJECT_ID || '',
        apiKey: process.env.APPWRITE_API_KEY || '',
        bucketId: process.env.APPWRITE_BUCKET_ID || '',
        dbId: process.env.APPWRITE_DB_ID || '',
        collectionId: process.env.APPWRITE_COLLECTION_ID || '',
        cdnDomain: process.env.APPWRITE_CDN_DOMAIN || '',
    },
    parallelUploads: parseInt(process.env.PARALLEL_UPLOADS || '5'),
    maxRetries: parseInt(process.env.MAX_RETRIES || '3'),
};

// Validate configuration
function validateConfig(): string[] {
    const errors: string[] = [];
    if (!config.appwrite.projectId) errors.push('APPWRITE_PROJECT_ID is required');
    if (!config.appwrite.apiKey) errors.push('APPWRITE_API_KEY is required');
    if (!config.appwrite.bucketId) errors.push('APPWRITE_BUCKET_ID is required');
    return errors;
}

// Initialize Appwrite client
const client = new Client()
    .setEndpoint(config.appwrite.endpoint)
    .setProject(config.appwrite.projectId)
    .setKey(config.appwrite.apiKey);

const storage = new Storage(client);
const databases = new Databases(client);

// Types
interface AttachmentSize {
    name: string;
    file: string;
    local_url: string;
    width?: number;
    height?: number;
}

interface AttachmentMetadata {
    attachment_id: number;
    original_file: string;
    local_url: string;
    sizes: AttachmentSize[];
}

interface MigrationMetadata {
    version: string;
    account_email: string;
    webhook_url: string;
    webhook_secret: string;
    site_url: string;
    migration_id: string;
    created_at: string;
    attachments: AttachmentMetadata[];
}

interface UploadResult {
    attachment_id: number;
    file_id: string;
    cdn_url: string;
    sizes: Record<string, { file_id: string; url: string }>;
}

interface MigrationError {
    attachment_id: number;
    file: string;
    error: string;
}

interface WebhookPayload {
    migration_id: string;
    status: 'completed' | 'partial' | 'failed';
    results: UploadResult[];
    errors: MigrationError[];
}

// Utility functions
function getCdnUrl(fileId: string): string {
    const base = (config.appwrite.cdnDomain && config.appwrite.cdnDomain.trim().length > 0)
        ? config.appwrite.cdnDomain.replace(/\/$/, '')
        : config.appwrite.endpoint.replace(/\/$/, '');
    return `${base}/storage/buckets/${config.appwrite.bucketId}/files/${fileId}/view?project=${config.appwrite.projectId}`;
}

async function extractZip(zipPath: string, extractDir: string): Promise<void> {
    // Bun has native zip support via unzip command or we can use JSZip
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

async function uploadFileToAppwrite(
    filePath: string,
    fileName: string,
    accountEmail: string,
    originalUrl: string
): Promise<string> {
    // Read file as buffer and create a proper File object
    const fileBuffer = await readFile(filePath);
    const fileBlob = new Blob([fileBuffer]);
    
    // Create a File object that Appwrite SDK can handle
    const fileObject = new File([fileBlob], fileName, {
        type: 'application/octet-stream'
    });

    const file = await storage.createFile(
        config.appwrite.bucketId,
        'unique()',
        fileObject as any
    );

    // Track upload in Database if configured
    if (config.appwrite.dbId && config.appwrite.collectionId) {
        try {
            await databases.createDocument(
                config.appwrite.dbId,
                config.appwrite.collectionId,
                'unique()',
                {
                    email: accountEmail,
                    fileId: file.$id,
                    originalUrl: originalUrl,
                    createdAt: new Date().toISOString(),
                }
            );
        } catch (error) {
            console.warn(`Failed to track upload in database: ${error}`);
        }
    }

    return file.$id;
}

async function uploadWithRetry(
    filePath: string,
    fileName: string,
    accountEmail: string,
    originalUrl: string,
    retries: number = config.maxRetries
): Promise<string> {
    let lastError: Error | null = null;
    
    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            return await uploadFileToAppwrite(filePath, fileName, accountEmail, originalUrl);
        } catch (error) {
            lastError = error as Error;
            console.warn(`Upload attempt ${attempt}/${retries} failed for ${fileName}: ${lastError.message}`);
            
            if (attempt < retries) {
                // Exponential backoff
                await new Promise(resolve => setTimeout(resolve, Math.pow(2, attempt) * 1000));
            }
        }
    }
    
    throw lastError || new Error('Upload failed after retries');
}

async function processAttachment(
    attachment: AttachmentMetadata,
    extractDir: string,
    accountEmail: string
): Promise<{ result?: UploadResult; errors: MigrationError[] }> {
    const errors: MigrationError[] = [];
    const result: UploadResult = {
        attachment_id: attachment.attachment_id,
        file_id: '',
        cdn_url: '',
        sizes: {},
    };

    // Upload original file
    const originalPath = join(extractDir, attachment.original_file);
    if (existsSync(originalPath)) {
        try {
            const fileId = await uploadWithRetry(
                originalPath,
                basename(attachment.original_file),
                accountEmail,
                attachment.local_url
            );
            result.file_id = fileId;
            result.cdn_url = getCdnUrl(fileId);
        } catch (error) {
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

    // Upload sizes in parallel batches
    const sizePromises = attachment.sizes.map(async (size) => {
        const sizePath = join(extractDir, size.file);
        if (existsSync(sizePath)) {
            try {
                const fileId = await uploadWithRetry(
                    sizePath,
                    basename(size.file),
                    accountEmail,
                    size.local_url
                );
                return { name: size.name, fileId, success: true };
            } catch (error) {
                errors.push({
                    attachment_id: attachment.attachment_id,
                    file: size.file,
                    error: (error as Error).message,
                });
                return { name: size.name, fileId: '', success: false };
            }
        } else {
            errors.push({
                attachment_id: attachment.attachment_id,
                file: size.file,
                error: 'Size file not found in zip',
            });
            return { name: size.name, fileId: '', success: false };
        }
    });

    const sizeResults = await Promise.all(sizePromises);
    
    for (const sizeResult of sizeResults) {
        if (sizeResult.success && sizeResult.fileId) {
            result.sizes[sizeResult.name] = {
                file_id: sizeResult.fileId,
                url: getCdnUrl(sizeResult.fileId),
            };
        }
    }

    // Only return result if we successfully uploaded the original file
    return {
        result: result.file_id ? result : undefined,
        errors,
    };
}

async function processMigration(
    metadata: MigrationMetadata,
    extractDir: string
): Promise<WebhookPayload> {
    const results: UploadResult[] = [];
    const errors: MigrationError[] = [];

    console.log(`Processing migration ${metadata.migration_id} with ${metadata.attachments.length} attachments`);

    // Process attachments in parallel batches
    const batchSize = config.parallelUploads;
    const attachments = metadata.attachments;

    for (let i = 0; i < attachments.length; i += batchSize) {
        const batch = attachments.slice(i, i + batchSize);
        console.log(`Processing batch ${Math.floor(i / batchSize) + 1}/${Math.ceil(attachments.length / batchSize)}`);

        const batchPromises = batch.map(attachment =>
            processAttachment(attachment, extractDir, metadata.account_email)
        );

        const batchResults = await Promise.all(batchPromises);

        for (const { result, errors: attachmentErrors } of batchResults) {
            if (result) {
                results.push(result);
            }
            errors.push(...attachmentErrors);
        }
    }

    // Determine overall status
    let status: 'completed' | 'partial' | 'failed';
    if (errors.length === 0) {
        status = 'completed';
    } else if (results.length > 0) {
        status = 'partial';
    } else {
        status = 'failed';
    }

    return {
        migration_id: metadata.migration_id,
        status,
        results,
        errors,
    };
}

async function sendWebhook(
    webhookUrl: string,
    webhookSecret: string,
    payload: WebhookPayload,
    retries: number = 3
): Promise<boolean> {
    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            const response = await fetch(webhookUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': `Bearer ${webhookSecret}`,
                    'X-Webhook-Secret': webhookSecret,
                },
                body: JSON.stringify(payload),
            });

            if (response.ok) {
                console.log(`Webhook sent successfully to ${webhookUrl}`);
                return true;
            }

            const text = await response.text();
            console.warn(`Webhook attempt ${attempt}/${retries} failed: ${response.status} ${text}`);
        } catch (error) {
            console.warn(`Webhook attempt ${attempt}/${retries} error: ${error}`);
        }

        if (attempt < retries) {
            await new Promise(resolve => setTimeout(resolve, Math.pow(2, attempt) * 1000));
        }
    }

    return false;
}

// Verify API key
function verifyApiKey(request: Request): boolean {
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

// Request handlers
async function handleMigrate(request: Request): Promise<Response> {
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
    const tempDir = join('/tmp', `blitzcdn-${migrationId || Date.now()}`);
    const zipPath = join(tempDir, 'migration.zip');
    const extractDir = join(tempDir, 'extracted');

    try {
        await mkdir(tempDir, { recursive: true });
        await mkdir(extractDir, { recursive: true });

        // Save uploaded zip
        const arrayBuffer = await file.arrayBuffer();
        await writeFile(zipPath, Buffer.from(arrayBuffer));

        // Extract zip
        console.log(`Extracting zip to ${extractDir}`);
        await extractZip(zipPath, extractDir);

        // Read metadata.json
        const metadataPath = join(extractDir, 'metadata.json');
        if (!existsSync(metadataPath)) {
            throw new Error('metadata.json not found in zip');
        }

        const metadataContent = await readFile(metadataPath, 'utf-8');
        const metadata: MigrationMetadata = JSON.parse(metadataContent);

        console.log(`Migration ${metadata.migration_id}: ${metadata.attachments.length} attachments from ${metadata.site_url}`);
        console.log(`Webhook URL: ${metadata.webhook_url}`);

        // Process migration (async - don't await)
        processMigration(metadata, extractDir)
            .then(async (webhookPayload) => {
                // Send webhook
                const webhookSent = await sendWebhook(
                    metadata.webhook_url,
                    metadata.webhook_secret,
                    webhookPayload
                );

                if (!webhookSent) {
                    console.error(`Failed to send webhook for migration ${metadata.migration_id}`);
                }

                // Cleanup
                await rm(tempDir, { recursive: true, force: true });
            })
            .catch(async (error) => {
                console.error(`Migration ${metadata.migration_id} failed:`, error);
                
                // Send error webhook
                await sendWebhook(metadata.webhook_url, metadata.webhook_secret, {
                    migration_id: metadata.migration_id,
                    status: 'failed',
                    results: [],
                    errors: [{ attachment_id: 0, file: '', error: (error as Error).message }],
                });

                // Cleanup
                await rm(tempDir, { recursive: true, force: true });
            });

        // Return immediately
        return new Response(JSON.stringify({
            success: true,
            message: 'Migration started',
            migration_id: metadata.migration_id,
            attachment_count: metadata.attachments.length,
        }), {
            status: 202, // Accepted
            headers: { 'Content-Type': 'application/json' },
        });

    } catch (error) {
        console.error('Migration error:', error);
        
        // Cleanup on error
        try {
            await rm(tempDir, { recursive: true, force: true });
        } catch {}

        return new Response(JSON.stringify({
            error: 'Migration failed',
            message: (error as Error).message,
        }), {
            status: 500,
            headers: { 'Content-Type': 'application/json' },
        });
    }
}

async function handleHealth(): Promise<Response> {
    const errors = validateConfig();
    
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
            maxRetries: config.maxRetries,
        },
        errors,
    }), {
        status: errors.length === 0 ? 200 : 503,
        headers: { 'Content-Type': 'application/json' },
    });
}

// Main server
const server = Bun.serve({
    port: config.port,
    hostname: config.host,
    
    async fetch(request: Request): Promise<Response> {
        const url = new URL(request.url);
        const path = url.pathname;

        console.log(`${request.method} ${path}`);

        // CORS headers
        const corsHeaders = {
            'Access-Control-Allow-Origin': '*',
            'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers': 'Content-Type, Authorization',
        };

        // Handle CORS preflight
        if (request.method === 'OPTIONS') {
            return new Response(null, { status: 204, headers: corsHeaders });
        }

        try {
            let response: Response;

            switch (path) {
                case '/api/migrate':
                    if (request.method !== 'POST') {
                        response = new Response(JSON.stringify({ error: 'Method not allowed' }), {
                            status: 405,
                            headers: { 'Content-Type': 'application/json' },
                        });
                    } else {
                        response = await handleMigrate(request);
                    }
                    break;

                case '/health':
                case '/api/health':
                    response = await handleHealth();
                    break;

                default:
                    response = new Response(JSON.stringify({
                        name: 'BlitzCDN Middleware',
                        version: '1.0.0',
                        endpoints: {
                            '/api/migrate': 'POST - Upload zip for migration',
                            '/health': 'GET - Health check',
                        },
                    }), {
                        status: 200,
                        headers: { 'Content-Type': 'application/json' },
                    });
            }

            // Add CORS headers to response
            const newHeaders = new Headers(response.headers);
            for (const [key, value] of Object.entries(corsHeaders)) {
                newHeaders.set(key, value);
            }

            return new Response(response.body, {
                status: response.status,
                statusText: response.statusText,
                headers: newHeaders,
            });

        } catch (error) {
            console.error('Server error:', error);
            return new Response(JSON.stringify({
                error: 'Internal server error',
                message: (error as Error).message,
            }), {
                status: 500,
                headers: { 'Content-Type': 'application/json', ...corsHeaders },
            });
        }
    },
});

console.log(`🚀 BlitzCDN Middleware running at http://${config.host}:${config.port}`);
console.log(`   Appwrite: ${config.appwrite.endpoint}`);
console.log(`   Parallel uploads: ${config.parallelUploads}`);

// Validate config on startup
const configErrors = validateConfig();
if (configErrors.length > 0) {
    console.warn('⚠️  Configuration warnings:');
    configErrors.forEach(err => console.warn(`   - ${err}`));
}
