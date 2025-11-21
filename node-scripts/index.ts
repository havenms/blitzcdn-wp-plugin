#!/usr/bin/env bun

import { Client, Storage, Query, type Models } from 'node-appwrite';

// Configuration
const APPWRITE_PROJECT_ID = 'blitz-cdn';
const APPWRITE_API_KEY = 'standard_0915a1c42d339dcbdcf4fb16e8b1d56580f444b22e136ce28b3db1d304162ea936f9b11ef4f12ca57c04a37d63fad24254e7d7252a8b946b909c4eba077ba9a2e75ecded23cf91d796a6f1eb2c46ae0940936065960040881a13f766c66340def569aeb767d31800ba23d9b0920381a2024d5a03f3a985e585a73601f6414428';
const APPWRITE_BUCKET_ID = 'blob';
const APPWRITE_ENDPOINT = 'https://apw.havenmediasolutions.com/v1';

// Initialize Appwrite client
const client = new Client()
    .setEndpoint(APPWRITE_ENDPOINT)
    .setProject(APPWRITE_PROJECT_ID)
    .setKey(APPWRITE_API_KEY);

const storage = new Storage(client);

interface FileInfo {
    $id: string;
    name: string;
    $createdAt: string;
    sizeOriginal: number;
}

async function promptConfirmation(message: string): Promise<boolean> {
    process.stdout.write(`${message} (y/n): `);
    
    for await (const line of console) {
        const answer = line.trim().toLowerCase();
        if (answer === 'y' || answer === 'yes') {
            return true;
        } else if (answer === 'n' || answer === 'no') {
            return false;
        } else {
            process.stdout.write('Please enter y or n: ');
        }
    }
    
    return false;
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleString();
}

async function getAllFilesToDelete(cutoffDate: string): Promise<FileInfo[]> {
    const allFiles: FileInfo[] = [];
    let hasMore = true;
    let offset = 0;
    const limit = 100;

    while (hasMore) {
        const response = await storage.listFiles(
            APPWRITE_BUCKET_ID,
            [
                Query.greaterThan('$createdAt', cutoffDate), // Changed to greaterThan for newer files
                Query.limit(limit),
                Query.offset(offset)
            ]
        );

        allFiles.push(...response.files as FileInfo[]);

        if (response.files.length < limit) {
            hasMore = false;
        } else {
            offset += limit;
        }
    }

    return allFiles;
}

function displayFilePreview(files: FileInfo[], title: string) {
    console.log(`\n${title}`);
    console.log('─'.repeat(80));
    
    files.forEach((file, idx) => {
        console.log(`${idx + 1}. ${file.name}`);
        console.log(`   ID: ${file.$id}`);
        console.log(`   Created: ${formatDate(file.$createdAt)}`);
        console.log(`   Size: ${formatFileSize(file.sizeOriginal)}`);
        console.log('');
    });
}

async function deleteRecentFiles() {
    try {
        // Calculate date 2 days ago
        const twoDaysAgo = new Date();
        twoDaysAgo.setDate(twoDaysAgo.getDate() - 2);
        const cutoffDate = twoDaysAgo.toISOString();

        console.log('\n🔍 Searching for files created IN the last 2 days...');
        console.log(`   Cutoff date: ${formatDate(cutoffDate)}`);
        console.log(`   Current time: ${formatDate(new Date().toISOString())}\n`);

        // Get all files to delete
        const filesToDelete = await getAllFilesToDelete(cutoffDate);

        if (filesToDelete.length === 0) {
            console.log('✓ No files found created in the last 2 days. Nothing to delete.');
            return;
        }

        console.log(`\n📊 Found ${filesToDelete.length} file(s) to delete`);

        // Calculate total size
        const totalSize = filesToDelete.reduce((sum, file) => sum + file.sizeOriginal, 0);
        console.log(`   Total size: ${formatFileSize(totalSize)}`);

        // Show first 10 files
        const firstTen = filesToDelete.slice(0, 10);
        displayFilePreview(firstTen, '📄 FIRST 10 FILES:');

        // Show last 10 files if there are more than 10
        if (filesToDelete.length > 10) {
            const lastTen = filesToDelete.slice(-10);
            displayFilePreview(lastTen, '📄 LAST 10 FILES:');
        }

        if (filesToDelete.length > 20) {
            console.log(`\n... and ${filesToDelete.length - 20} more files\n`);
        }

        // Ask for confirmation
        const confirmed = await promptConfirmation(
            `\n⚠️  Do you want to DELETE all ${filesToDelete.length} files created in the last 2 days?`
        );

        if (!confirmed) {
            console.log('\n❌ Deletion cancelled by user.');
            return;
        }

        // Delete files
        console.log('\n🗑️  Starting deletion...\n');
        let deletedCount = 0;
        let failedCount = 0;

        for (const file of filesToDelete) {
            try {
                await storage.deleteFile(APPWRITE_BUCKET_ID, file.$id);
                deletedCount++;
                process.stdout.write(`\r✓ Deleted ${deletedCount}/${filesToDelete.length} files`);
            } catch (error) {
                failedCount++;
                console.error(`\n✗ Failed to delete ${file.name}: ${error}`);
            }
        }

        console.log('\n\n=== Deletion Complete ===');
        console.log(`✓ Successfully deleted: ${deletedCount} files`);
        if (failedCount > 0) {
            console.log(`✗ Failed: ${failedCount} files`);
        }
        console.log(`📦 Space freed: ${formatFileSize(totalSize)}`);

    } catch (error) {
        console.error('\n❌ Error during file deletion:', error);
        throw error;
    }
}

// Run the script
deleteRecentFiles()
    .then(() => {
        console.log('\n✓ Script finished successfully\n');
        process.exit(0);
    })
    .catch((error) => {
        console.error('\n✗ Script failed:', error);
        process.exit(1);
    });
