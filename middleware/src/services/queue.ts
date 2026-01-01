/**
 * Redis Queue Service
 * 
 * Handles persistent job queue with Redis for migration jobs.
 * Supports job resumption after container restarts.
 */

import { createClient, type RedisClientType } from 'redis';
import { config } from '../config';
import type { MigrationJob, MigrationState } from '../types';

const JOBS_KEY = 'blitzcdn:jobs';
const MIGRATIONS_KEY = 'blitzcdn:migrations';
const QUEUE_KEY = 'blitzcdn:queue';
const PROCESSING_KEY = 'blitzcdn:processing';
const FAILED_WEBHOOKS_KEY = 'blitzcdn:failed_webhooks';

class QueueService {
    private client: RedisClientType | null = null;
    private isConnected = false;
    private reconnectAttempts = 0;
    private maxReconnectAttempts = 10;

    async connect(): Promise<void> {
        if (this.isConnected && this.client) {
            return;
        }

        try {
            this.client = createClient({
                url: config.redis.url,
                socket: {
                    reconnectStrategy: (retries) => {
                        if (retries > this.maxReconnectAttempts) {
                            console.error('❌ Redis: Max reconnection attempts reached');
                            return new Error('Max reconnection attempts reached');
                        }
                        const delay = Math.min(retries * 100, 3000);
                        console.log(`🔄 Redis: Reconnecting in ${delay}ms (attempt ${retries})`);
                        return delay;
                    },
                },
            });

            this.client.on('error', (err) => {
                console.error('❌ Redis error:', err.message);
                this.isConnected = false;
            });

            this.client.on('connect', () => {
                console.log('✅ Redis: Connected');
                this.isConnected = true;
                this.reconnectAttempts = 0;
            });

            this.client.on('disconnect', () => {
                console.log('⚠️  Redis: Disconnected');
                this.isConnected = false;
            });

            await this.client.connect();
        } catch (error) {
            console.error('❌ Redis connection failed:', (error as Error).message);
            throw error;
        }
    }

    async disconnect(): Promise<void> {
        if (this.client) {
            await this.client.quit();
            this.client = null;
            this.isConnected = false;
        }
    }

    private ensureConnected(): void {
        if (!this.client || !this.isConnected) {
            throw new Error('Redis not connected');
        }
    }

    // ============ Migration State Management ============

    async createMigration(state: MigrationState): Promise<void> {
        this.ensureConnected();
        await this.client!.hSet(MIGRATIONS_KEY, state.migration_id, JSON.stringify(state));
    }

    async getMigration(migrationId: string): Promise<MigrationState | null> {
        this.ensureConnected();
        const data = await this.client!.hGet(MIGRATIONS_KEY, migrationId);
        return data ? JSON.parse(data) : null;
    }

    async updateMigration(migrationId: string, updates: Partial<MigrationState>): Promise<MigrationState | null> {
        this.ensureConnected();
        const current = await this.getMigration(migrationId);
        if (!current) return null;
        
        const updated: MigrationState = {
            ...current,
            ...updates,
            updated_at: new Date().toISOString(),
        };
        await this.client!.hSet(MIGRATIONS_KEY, migrationId, JSON.stringify(updated));
        return updated;
    }

    async deleteMigration(migrationId: string): Promise<void> {
        this.ensureConnected();
        await this.client!.hDel(MIGRATIONS_KEY, migrationId);
    }

    async getAllMigrations(): Promise<MigrationState[]> {
        this.ensureConnected();
        const data = await this.client!.hGetAll(MIGRATIONS_KEY);
        return Object.values(data).map(v => JSON.parse(v));
    }

    async getActiveMigrations(): Promise<MigrationState[]> {
        const all = await this.getAllMigrations();
        return all.filter(m => m.status === 'active');
    }

    // ============ Job Management ============

    async createJob(job: MigrationJob): Promise<void> {
        this.ensureConnected();
        await this.client!.hSet(JOBS_KEY, job.id, JSON.stringify(job));
    }

    async getJob(jobId: string): Promise<MigrationJob | null> {
        this.ensureConnected();
        const data = await this.client!.hGet(JOBS_KEY, jobId);
        return data ? JSON.parse(data) : null;
    }

    async updateJob(jobId: string, updates: Partial<MigrationJob>): Promise<MigrationJob | null> {
        this.ensureConnected();
        const current = await this.getJob(jobId);
        if (!current) return null;
        
        const updated: MigrationJob = {
            ...current,
            ...updates,
        };
        await this.client!.hSet(JOBS_KEY, jobId, JSON.stringify(updated));
        return updated;
    }

    async deleteJob(jobId: string): Promise<void> {
        this.ensureConnected();
        await this.client!.hDel(JOBS_KEY, jobId);
        // Also remove from queue if present
        await this.client!.lRem(QUEUE_KEY, 0, jobId);
        await this.client!.lRem(PROCESSING_KEY, 0, jobId);
    }

    async getJobsByMigration(migrationId: string): Promise<MigrationJob[]> {
        this.ensureConnected();
        const allJobs = await this.client!.hGetAll(JOBS_KEY);
        return Object.values(allJobs)
            .map(v => JSON.parse(v) as MigrationJob)
            .filter(job => job.migration_id === migrationId);
    }

    // ============ Queue Operations ============

    async enqueueJob(jobId: string): Promise<void> {
        this.ensureConnected();
        await this.client!.rPush(QUEUE_KEY, jobId);
    }

    async dequeueJob(): Promise<string | null> {
        this.ensureConnected();
        // Move from queue to processing
        const jobId = await this.client!.lMove(QUEUE_KEY, PROCESSING_KEY, 'LEFT', 'RIGHT');
        return jobId;
    }

    async completeJob(jobId: string): Promise<void> {
        this.ensureConnected();
        await this.client!.lRem(PROCESSING_KEY, 0, jobId);
    }

    async requeueJob(jobId: string): Promise<void> {
        this.ensureConnected();
        await this.client!.lRem(PROCESSING_KEY, 0, jobId);
        await this.client!.lPush(QUEUE_KEY, jobId);
    }

    async getQueueLength(): Promise<number> {
        this.ensureConnected();
        return await this.client!.lLen(QUEUE_KEY);
    }

    async getProcessingLength(): Promise<number> {
        this.ensureConnected();
        return await this.client!.lLen(PROCESSING_KEY);
    }

    async getPendingJobIds(): Promise<string[]> {
        this.ensureConnected();
        return await this.client!.lRange(QUEUE_KEY, 0, -1);
    }

    async getProcessingJobIds(): Promise<string[]> {
        this.ensureConnected();
        return await this.client!.lRange(PROCESSING_KEY, 0, -1);
    }

    // ============ Recovery Operations ============

    /**
     * Recover jobs that were processing when container stopped.
     * Re-queues them for processing.
     */
    async recoverProcessingJobs(): Promise<number> {
        this.ensureConnected();
        
        const processingIds = await this.getProcessingJobIds();
        let recovered = 0;

        for (const jobId of processingIds) {
            const job = await this.getJob(jobId);
            if (job && job.status === 'processing') {
                // Reset job status to pending and requeue
                await this.updateJob(jobId, {
                    status: 'pending',
                    started_at: undefined,
                });
                await this.requeueJob(jobId);
                recovered++;
                console.log(`🔄 Recovered job: ${jobId}`);
            }
        }

        return recovered;
    }

    // ============ Cancellation ============

    async cancelMigration(migrationId: string): Promise<{ cancelled: number; message: string }> {
        this.ensureConnected();

        const migration = await this.getMigration(migrationId);
        if (!migration) {
            return { cancelled: 0, message: 'Migration not found' };
        }

        // Mark migration as cancelled
        await this.updateMigration(migrationId, { status: 'cancelled' });

        // Cancel all pending and processing jobs
        const jobs = await this.getJobsByMigration(migrationId);
        let cancelled = 0;

        for (const job of jobs) {
            if (job.status === 'pending' || job.status === 'processing') {
                await this.updateJob(job.id, {
                    status: 'cancelled',
                    completed_at: new Date().toISOString(),
                });
                // Remove from queues
                await this.client!.lRem(QUEUE_KEY, 0, job.id);
                await this.client!.lRem(PROCESSING_KEY, 0, job.id);
                cancelled++;
            }
        }

        return { cancelled, message: `Cancelled ${cancelled} jobs for migration ${migrationId}` };
    }

    /**
     * Check if a migration has been cancelled.
     */
    async isMigrationCancelled(migrationId: string): Promise<boolean> {
        const migration = await this.getMigration(migrationId);
        return migration?.status === 'cancelled';
    }

    // ============ Cleanup ============

    async cleanupCompletedJobs(olderThanHours: number = 24): Promise<number> {
        this.ensureConnected();
        
        const cutoff = new Date(Date.now() - olderThanHours * 60 * 60 * 1000).toISOString();
        const allJobs = await this.client!.hGetAll(JOBS_KEY);
        let cleaned = 0;

        for (const [jobId, data] of Object.entries(allJobs)) {
            const job = JSON.parse(data) as MigrationJob;
            if (
                (job.status === 'completed' || job.status === 'cancelled') &&
                job.completed_at &&
                job.completed_at < cutoff
            ) {
                await this.deleteJob(jobId);
                cleaned++;
            }
        }

        return cleaned;
    }

    async cleanupCompletedMigrations(olderThanHours: number = 24): Promise<number> {
        this.ensureConnected();
        
        const cutoff = new Date(Date.now() - olderThanHours * 60 * 60 * 1000).toISOString();
        const migrations = await this.getAllMigrations();
        let cleaned = 0;

        for (const migration of migrations) {
            if (
                (migration.status === 'completed' || migration.status === 'cancelled') &&
                migration.updated_at < cutoff
            ) {
                // Delete all jobs for this migration
                const jobs = await this.getJobsByMigration(migration.migration_id);
                for (const job of jobs) {
                    await this.deleteJob(job.id);
                }
                await this.deleteMigration(migration.migration_id);
                cleaned++;
            }
        }

        return cleaned;
    }

    // ============ Failed Webhook Persistence ============

    async pushFailedWebhook(entry: Record<string, any>): Promise<void> {
        this.ensureConnected();
        // Add minimal fields: id, webhook_url, webhook_secret, payload, attempts
        await this.client!.rPush(FAILED_WEBHOOKS_KEY, JSON.stringify(entry));
    }

    async popFailedWebhook(): Promise<string | null> {
        this.ensureConnected();
        return await this.client!.lPop(FAILED_WEBHOOKS_KEY);
    }

    async getFailedWebhookCount(): Promise<number> {
        this.ensureConnected();
        return await this.client!.lLen(FAILED_WEBHOOKS_KEY);
    }

    // ============ Stats ============

    async getStats(): Promise<{
        activeMigrations: number;
        pendingJobs: number;
        processingJobs: number;
        totalJobs: number;
    }> {
        this.ensureConnected();
        
        const [migrations, queueLen, processingLen, allJobs] = await Promise.all([
            this.getActiveMigrations(),
            this.getQueueLength(),
            this.getProcessingLength(),
            this.client!.hLen(JOBS_KEY),
        ]);

        return {
            activeMigrations: migrations.length,
            pendingJobs: queueLen,
            processingJobs: processingLen,
            totalJobs: allJobs,
        };
    }
}

export const queueService = new QueueService();
