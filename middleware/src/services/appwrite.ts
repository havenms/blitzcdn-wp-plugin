/**
 * Appwrite Storage Service
 */

import { Client, Storage, Databases } from 'node-appwrite';
import { readFile } from 'fs/promises';
import { config } from '../config';

// Initialize Appwrite client
const client = new Client()
    .setEndpoint(config.appwrite.endpoint)
    .setProject(config.appwrite.projectId)
    .setKey(config.appwrite.apiKey);

export const storage = new Storage(client);
export const databases = new Databases(client);

export function getCdnUrl(fileId: string): string {
    const base = (config.appwrite.cdnDomain && config.appwrite.cdnDomain.trim().length > 0)
        ? config.appwrite.cdnDomain.replace(/\/$/, '')
        : config.appwrite.endpoint.replace(/\/$/, '');
    return `${base}/storage/buckets/${config.appwrite.bucketId}/files/${fileId}/view?project=${config.appwrite.projectId}`;
}

export async function uploadFileToAppwrite(
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

export async function uploadWithRetry(
    filePath: string,
    fileName: string,
    accountEmail: string,
    originalUrl: string,
    retries: number = config.maxRetries
): Promise<string> {
    let lastError: Error | null = null;

    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            const fileId = await uploadFileToAppwrite(filePath, fileName, accountEmail, originalUrl);
            if (attempt > 1) {
                console.log(`  ✅ ${fileName.substring(fileName.lastIndexOf('/') + 1)} uploaded (retry ${attempt})`);
            }
            return fileId;
        } catch (error) {
            lastError = error as Error;
            const waitSeconds = Math.pow(2, attempt);

            if (attempt < retries) {
                console.warn(`  ⚠️  Upload attempt ${attempt}/${retries} failed: ${fileName}`);
                console.warn(`      Reason: ${lastError.message}`);
                console.warn(`      Retrying in ${waitSeconds}s...`);
                await new Promise(resolve => setTimeout(resolve, waitSeconds * 1000));
            } else {
                console.error(`  ❌ Upload failed (all ${retries} retries exhausted): ${fileName}`);
                console.error(`      Last error: ${lastError.message}`);
            }
        }
    }

    throw lastError || new Error('Upload failed after retries');
}
