/**
 * BlitzCDN Middleware Configuration
 */

import { resolve } from 'path';
import type { Config } from './types';

export const config: Config = {
    port: parseInt(process.env.PORT || '3000'),
    host: process.env.HOST || '0.0.0.0',
    apiKey: process.env.API_KEY || '',
    redis: {
        url: process.env.REDIS_URL || 'redis://redis:6379',
    },
    appwrite: {
        endpoint: process.env.APPWRITE_ENDPOINT || 'https://cloud.appwrite.io/v1',
        projectId: process.env.APPWRITE_PROJECT_ID || '',
        apiKey: process.env.APPWRITE_API_KEY || '',
        bucketId: process.env.APPWRITE_BUCKET_ID || '',
        dbId: process.env.APPWRITE_DB_ID || '',
        collectionId: process.env.APPWRITE_COLLECTION_ID || '',
        cdnDomain: process.env.APPWRITE_CDN_DOMAIN || '',
    },
    batchSize: parseInt(process.env.BATCH_SIZE || '10'),
    parallelUploads: parseInt(process.env.PARALLEL_UPLOADS || '5'),
    maxRetries: parseInt(process.env.MAX_RETRIES || '3'),
    tlsRejectUnauthorized: process.env.NODE_TLS_REJECT_UNAUTHORIZED !== '0',
    maxZipSizeMB: parseInt(process.env.MAX_ZIP_SIZE_MB || '2048'),
    // Resolve to an absolute path so it works both in Docker (/app) and locally
    dataDir: resolve(process.env.DATA_DIR || './data'),
};

export function validateConfig(): string[] {
    const errors: string[] = [];
    if (!config.appwrite.projectId) errors.push('APPWRITE_PROJECT_ID is required');
    if (!config.appwrite.apiKey) errors.push('APPWRITE_API_KEY is required');
    if (!config.appwrite.bucketId) errors.push('APPWRITE_BUCKET_ID is required');
    return errors;
}
