#!/usr/bin/env bun

import { Client, Storage, Databases, Query, type Models } from 'node-appwrite';
import React, { useState, useEffect, useRef } from 'react';
import { render, Text, Box, useApp, useInput } from 'ink';

// Configuration
const APPWRITE_PROJECT_ID = 'blitz-cdn';
const APPWRITE_API_KEY = 'standard_0915a1c42d339dcbdcf4fb16e8b1d56580f444b22e136ce28b3db1d304162ea936f9b11ef4f12ca57c04a37d63fad24254e7d7252a8b946b909c4eba077ba9a2e75ecded23cf91d796a6f1eb2c46ae0940936065960040881a13f766c66340def569aeb767d31800ba23d9b0920381a2024d5a03f3a985e585a73601f6414428';
const APPWRITE_BUCKET_ID = 'blob';
const APPWRITE_ENDPOINT = 'https://apw.havenmediasolutions.com/v1';
const APPWRITE_DB_ID = 'db';
const APPWRITE_COLLECTION_ID = 'uploads';

// Initialize Appwrite client
const client = new Client()
    .setEndpoint(APPWRITE_ENDPOINT)
    .setProject(APPWRITE_PROJECT_ID)
    .setKey(APPWRITE_API_KEY);

const storage = new Storage(client);
const databases = new Databases(client);

interface UploadDocument extends Models.Document {
    email: string;
    fileId: string;
    originalUrl?: string;
    createdAt?: string;
}

interface FileInfo {
    $id: string;
    name: string;
    $createdAt: string;
    sizeOriginal: number;
}

interface DeletionStats {
    total: number;
    deleted: number;
    failed: number;
    totalSize: number;
    errors: Array<{ fileId: string; error: string }>;
    processed: number;
}

// Parse CLI arguments
function parseArgs(): { email: string | null } {
    const args = process.argv.slice(2);
    const emailIndex = args.findIndex(arg => arg === '--email' || arg === '-e');

    if (emailIndex === -1 || emailIndex === args.length - 1) {
        return { email: null };
    }

    return { email: args[emailIndex + 1] || null };
}

// Query all documents for a given email
async function getDocumentsByEmail(email: string): Promise<UploadDocument[]> {
    const allDocuments: UploadDocument[] = [];
    let hasMore = true;
    let offset = 0;
    const limit = 100;

    while (hasMore) {
        const response = await databases.listDocuments<UploadDocument>(
            APPWRITE_DB_ID,
            APPWRITE_COLLECTION_ID,
            [
                Query.equal('email', email),
                Query.limit(limit),
                Query.offset(offset)
            ]
        );

        allDocuments.push(...response.documents);

        if (response.documents.length < limit) {
            hasMore = false;
        } else {
            offset += limit;
        }
    }

    return allDocuments;
}

// Get file info for a fileId (for size calculation)
async function getFileInfo(fileId: string): Promise<FileInfo | null> {
    try {
        const file = await storage.getFile(APPWRITE_BUCKET_ID, fileId);
        return file as FileInfo;
    } catch (error) {
        return null;
    }
}

// Delete file by fileId
async function deleteFile(fileId: string): Promise<{ success: boolean; error?: string }> {
    try {
        await storage.deleteFile(APPWRITE_BUCKET_ID, fileId);
        return { success: true };
    } catch (error) {
        return {
            success: false,
            error: error instanceof Error ? error.message : String(error)
        };
    }
}

// Format file size
function formatFileSize(bytes: number): string {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
    if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
}

// Progress Bar Component
function ProgressBar({ current, total, width = 40 }: { current: number; total: number; width?: number }) {
    const percentage = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0;
    const filled = Math.round((percentage / 100) * width);
    const empty = width - filled;

    return (
        <Box>
            <Text>
                <Text color="cyan">[</Text>
                <Text color="green">{'█'.repeat(filled)}</Text>
                <Text color="gray">{'░'.repeat(empty)}</Text>
                <Text color="cyan">]</Text>
                <Text> {percentage}%</Text>
            </Text>
        </Box>
    );
}

// Main App Component
function App({ email }: { email: string }) {
    const { exit } = useApp();
    const [phase, setPhase] = useState<'querying' | 'fetching' | 'deleting' | 'complete' | 'error'>('querying');
    const [stats, setStats] = useState<DeletionStats>({
        total: 0,
        deleted: 0,
        failed: 0,
        totalSize: 0,
        errors: [],
        processed: 0
    });
    const [errorMessage, setErrorMessage] = useState<string>('');
    const processStarted = useRef(false);

    useEffect(() => {
        // Prevent multiple runs
        if (processStarted.current) return;
        processStarted.current = true;

        async function processDeletion() {
            try {
                // Phase 1: Query database
                setPhase('querying');
                const documents = await getDocumentsByEmail(email);

                if (documents.length === 0) {
                    setErrorMessage(`No documents found for email: ${email}`);
                    setPhase('error');
                    setTimeout(() => exit(), 3000);
                    return;
                }

                const ids = documents.map(doc => doc.fileId).filter(Boolean);
                setStats(prev => ({ ...prev, total: ids.length }));

                // Phase 2: Fetch file info in parallel batches (optional, for size calculation)
                // We'll do this in the background and start deleting immediately
                setPhase('fetching');

                // Start fetching file info in background (non-blocking)
                const fetchFileInfoPromise = (async () => {
                    const FILE_INFO_BATCH_SIZE = 100;
                    let totalSize = 0;

                    for (let i = 0; i < ids.length; i += FILE_INFO_BATCH_SIZE) {
                        const batch = ids.slice(i, i + FILE_INFO_BATCH_SIZE);
                        const fileInfoPromises = batch.map(id => getFileInfo(id));
                        const fileInfos = await Promise.all(fileInfoPromises);

                        totalSize += fileInfos.reduce((sum, info) =>
                            sum + (info?.sizeOriginal || 0), 0
                        );

                        // Update size as we go
                        setStats(prev => ({ ...prev, totalSize }));
                    }
                })();

                // Don't wait for file info - start deleting immediately
                // Phase 3: Delete files in parallel batches
                setPhase('deleting');
                const BATCH_SIZE = 50;
                let deletedCount = 0;
                let failedCount = 0;
                const errors: Array<{ fileId: string; error: string }> = [];

                for (let i = 0; i < ids.length; i += BATCH_SIZE) {
                    const batch = ids.slice(i, i + BATCH_SIZE);

                    // Delete batch in parallel
                    const results = await Promise.all(
                        batch.map(async (fileId) => {
                            const result = await deleteFile(fileId);
                            if (result.success) {
                                deletedCount++;
                            } else {
                                failedCount++;
                                errors.push({ fileId, error: result.error || 'Unknown error' });
                            }
                            return result;
                        })
                    );

                    // Update stats after each batch
                    setStats(prev => ({
                        ...prev,
                        deleted: deletedCount,
                        failed: failedCount,
                        processed: Math.min(ids.length, i + batch.length),
                        errors: [...errors]
                    }));
                }

                // Wait for file info to complete if still running
                await fetchFileInfoPromise;

                setPhase('complete');
                setStats(prev => ({ ...prev, processed: prev.total }));

                // Auto-exit after 5 seconds
                setTimeout(() => {
                    exit();
                }, 5000);

            } catch (error) {
                setErrorMessage(error instanceof Error ? error.message : String(error));
                setPhase('error');
                setTimeout(() => {
                    exit();
                }, 5000);
            }
        }

        processDeletion();
    }, [email]); // Removed exit from deps to prevent re-renders

    useInput((input, key) => {
        if (key.escape || (key.ctrl && input === 'c')) {
            exit();
        }
    });

    const progress = stats.total > 0 ? stats.processed : 0;

    return (
        <Box flexDirection="column" padding={1}>
            <Box marginBottom={1}>
                <Text bold color="cyan">
                    🚀 BlitzCDN File Deletion Tool
                </Text>
            </Box>

            <Box marginBottom={1}>
                <Text>
                    <Text color="gray">Email:</Text> <Text color="white">{email}</Text>
                </Text>
            </Box>

            {phase === 'querying' && (
                <Box flexDirection="column">
                    <Text color="yellow">🔍 Querying database for documents...</Text>
                </Box>
            )}

            {phase === 'fetching' && (
                <Box flexDirection="column">
                    <Text color="yellow">📊 Fetching file information...</Text>
                    <Text color="gray">   Found {stats.total} file(s)</Text>
                    {stats.totalSize > 0 && (
                        <Text color="gray">   Total size: {formatFileSize(stats.totalSize)}</Text>
                    )}
                </Box>
            )}

            {phase === 'deleting' && (
                <Box flexDirection="column">
                    <Text color="yellow" bold>🗑️  Deleting files...</Text>
                    <Box marginTop={1}>
                        <ProgressBar current={progress} total={stats.total} />
                    </Box>
                    <Box marginTop={1} flexDirection="column">
                        <Text>
                            <Text color="green">✓ Deleted:</Text> <Text color="white" bold>{stats.deleted}</Text>
                            <Text color="gray"> / </Text>
                            <Text color="white">{stats.total}</Text>
                        </Text>
                        {stats.failed > 0 && (
                            <Text>
                                <Text color="red">✗ Failed:</Text> <Text color="white" bold>{stats.failed}</Text>
                            </Text>
                        )}
                        {stats.totalSize > 0 && (
                            <Text>
                                <Text color="cyan">📦 Size:</Text> <Text color="white">{formatFileSize(stats.totalSize)}</Text>
                            </Text>
                        )}
                    </Box>
                </Box>
            )}

            {phase === 'complete' && (
                <Box flexDirection="column">
                    <Text color="green" bold>✓ Deletion Complete!</Text>
                    <Box marginTop={1} flexDirection="column">
                        <Text>
                            <Text color="green">✓ Successfully deleted:</Text> <Text color="white" bold>{stats.deleted}</Text> <Text color="gray">files</Text>
                        </Text>
                        {stats.failed > 0 && (
                            <Text>
                                <Text color="red">✗ Failed:</Text> <Text color="white" bold>{stats.failed}</Text> <Text color="gray">files</Text>
                            </Text>
                        )}
                        {stats.totalSize > 0 && (
                            <Text>
                                <Text color="cyan">📦 Space freed:</Text> <Text color="white" bold>{formatFileSize(stats.totalSize)}</Text>
                            </Text>
                        )}
                    </Box>
                    {stats.errors.length > 0 && (
                        <Box marginTop={1} flexDirection="column">
                            <Text color="red" bold>Errors:</Text>
                            {stats.errors.slice(0, 5).map((err, idx) => (
                                <Text key={idx} color="gray">
                                    • {err.fileId}: {err.error}
                                </Text>
                            ))}
                            {stats.errors.length > 5 && (
                                <Text color="gray">... and {stats.errors.length - 5} more errors</Text>
                            )}
                        </Box>
                    )}
                    <Box marginTop={1}>
                        <Text color="gray">Press ESC or Ctrl+C to exit</Text>
                    </Box>
                </Box>
            )}

            {phase === 'error' && (
                <Box flexDirection="column">
                    <Text color="red" bold>❌ Error:</Text>
                    <Text color="red">{errorMessage}</Text>
                    <Box marginTop={1}>
                        <Text color="gray">Press ESC or Ctrl+C to exit</Text>
                    </Box>
                </Box>
            )}
        </Box>
    );
}

// Main execution
const { email } = parseArgs();

if (!email) {
    console.error('❌ Error: Email parameter is required');
    console.error('Usage: bun index.tsx --email <email>');
    console.error('   or: bun index.tsx -e <email>');
    process.exit(1);
}

render(<App email={email} />);
