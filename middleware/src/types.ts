/**
 * BlitzCDN Middleware Types
 */

export interface AttachmentSize {
    name: string;
    file: string;
    local_url: string;
    width?: number;
    height?: number;
}

export interface AttachmentMetadata {
    attachment_id: number;
    original_file: string;
    local_url: string;
    sizes: AttachmentSize[];
}

export interface MigrationMetadata {
    version: string;
    account_email: string;
    webhook_url: string;
    webhook_secret: string;
    site_url: string;
    migration_id: string;
    created_at: string;
    attachments: AttachmentMetadata[];
}

export interface UploadResult {
    attachment_id: number;
    file_id: string;
    cdn_url: string;
    sizes: Record<string, { file_id: string; url: string }>;
}

export interface MigrationError {
    attachment_id: number;
    file: string;
    error: string;
}

export interface WebhookPayload {
    migration_id: string;
    status: 'received' | 'batch' | 'completed' | 'partial' | 'failed' | 'cancelled';
    results: UploadResult[];
    errors: MigrationError[];
    batch_number?: number;
    total_batches?: number;
    processed?: number;
    failed?: number;
    safe_to_quit?: boolean;
    message?: string;
    total_files_uploaded?: number;
}

export interface MigrationJob {
    id: string;
    migration_id: string;
    zip_batch_number: number;
    status: 'pending' | 'processing' | 'completed' | 'failed' | 'cancelled';
    metadata: MigrationMetadata;
    extract_dir: string;
    created_at: string;
    started_at?: string;
    completed_at?: string;
    progress: {
        total_attachments: number;
        processed_attachments: number;
        total_files: number;
        uploaded_files: number;
        failed_files: number;
    };
    error?: string;
}

export interface MigrationState {
    migration_id: string;
    site_url: string;
    account_email: string;
    webhook_url: string;
    webhook_secret: string;
    status: 'active' | 'completed' | 'cancelled' | 'failed';
    created_at: string;
    updated_at: string;
    zip_batches: string[]; // Array of job IDs
    totals: {
        total_attachments: number;
        processed_attachments: number;
        total_files: number;
        uploaded_files: number;
        failed_files: number;
    };
}

export interface Config {
    port: number;
    host: string;
    apiKey: string;
    redis: {
        url: string;
    };
    appwrite: {
        endpoint: string;
        projectId: string;
        apiKey: string;
        bucketId: string;
        dbId: string;
        collectionId: string;
        cdnDomain: string;
    };
    batchSize: number;
    parallelUploads: number;
    maxRetries: number;
    tlsRejectUnauthorized: boolean;
    maxZipSizeMB: number;
    dataDir: string;
}
