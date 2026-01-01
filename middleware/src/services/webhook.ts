/**
 * Webhook Service
 * 
 * Handles sending webhook notifications to WordPress.
 */

import { config } from '../config';
import type { WebhookPayload } from '../types';

/**
 * Transform local development URLs to be accessible from Docker container.
 */
function transformWebhookUrl(url: string): { url: string; originalHostname?: string } {
    try {
        const urlObj = new URL(url);
        // If it's a .local domain, replace with host.docker.internal
        if (urlObj.hostname.endsWith('.local')) {
            const originalHostname = urlObj.hostname;
            urlObj.hostname = 'host.docker.internal';
            return { url: urlObj.toString(), originalHostname };
        }
        return { url };
    } catch {
        // If URL parsing fails, return as-is
        return { url };
    }
}

import { queueService } from './queue';

export async function sendWebhookOnce(
    webhookUrl: string,
    webhookSecret: string,
    payload: WebhookPayload
): Promise<boolean> {
    const { url: transformedUrl, originalHostname } = transformWebhookUrl(webhookUrl);

    try {
        const headers: HeadersInit = {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${webhookSecret}`,
            'X-Webhook-Secret': webhookSecret,
        };

        if (originalHostname) {
            headers['Host'] = originalHostname;
        }

        const fetchOptions: RequestInit = {
            method: 'POST',
            headers,
            body: JSON.stringify(payload),
        };

        if (!config.tlsRejectUnauthorized) {
            // @ts-expect-error - Bun-specific option
            fetchOptions.tls = { rejectUnauthorized: false };
        }

        const response = await fetch(transformedUrl, fetchOptions);
        if (response.ok) {
            const details = payload.processed !== undefined 
                ? ` (${payload.processed} processed, ${payload.failed} failed)` 
                : '';
            console.log(`   📤 ${payload.status.toUpperCase()} webhook sent${details}`);
            return true;
        }

        const text = await response.text();
        console.warn(`   ⚠️  Webhook failed: HTTP ${response.status} - ${text.substring(0, 100)}...`);
        return false;
    } catch (error) {
        console.warn(`   ⚠️  Webhook error: ${(error as Error).message}`);
        return false;
    }
}

export async function sendWebhook(
    webhookUrl: string,
    webhookSecret: string,
    payload: WebhookPayload,
    retries: number = 3
): Promise<boolean> {
    const statusLabel = payload.status.toUpperCase();
    const statusEmoji = payload.status === 'failed' ? '❌' : 
                       payload.status === 'completed' ? '✅' : 
                       payload.status === 'cancelled' ? '🚫' : '📤';

    // Transform .local URLs to use host.docker.internal
    const { url: transformedUrl } = transformWebhookUrl(webhookUrl);

    for (let attempt = 1; attempt <= retries; attempt++) {
        const ok = await sendWebhookOnce(webhookUrl, webhookSecret, payload);
        if (ok) return true;

        const waitSeconds = Math.pow(2, attempt);
        if (attempt < retries) {
            console.warn(`   ⚠️  Webhook attempt ${attempt}/${retries} failed. Retrying in ${waitSeconds}s...`);
            await new Promise(resolve => setTimeout(resolve, waitSeconds * 1000));
        }
    }

    // Final failure: persist to Redis failed webhook queue for later retries
    try {
        const id = (typeof globalThis !== 'undefined' && (globalThis as any).crypto && (globalThis as any).crypto.randomUUID)
            ? (globalThis as any).crypto.randomUUID()
            : `fh_${Date.now()}`;
        const entry = {
            id,
            webhook_url: transformedUrl,
            webhook_secret: webhookSecret,
            payload,
            attempts: retries,
            created_at: new Date().toISOString(),
        };
        await queueService.pushFailedWebhook(entry);
        console.error(`   ❌ Webhook failed after ${retries} attempts — queued for retry (id=${entry.id})`);
    } catch (e) {
        console.error('   ❌ Failed to persist failed webhook for retry:', (e as Error).message);
    }

    return false;
}
