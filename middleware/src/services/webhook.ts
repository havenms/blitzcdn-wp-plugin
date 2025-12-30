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
    const { url: transformedUrl, originalHostname } = transformWebhookUrl(webhookUrl);

    for (let attempt = 1; attempt <= retries; attempt++) {
        try {
            // Configure fetch options
            const headers: HeadersInit = {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${webhookSecret}`,
                'X-Webhook-Secret': webhookSecret,
            };

            // Preserve original hostname in Host header for virtual hosts
            if (originalHostname) {
                headers['Host'] = originalHostname;
            }

            const fetchOptions: RequestInit = {
                method: 'POST',
                headers,
                body: JSON.stringify(payload),
            };

            // Disable TLS verification for local dev if configured
            if (!config.tlsRejectUnauthorized) {
                // @ts-expect-error - Bun-specific option not in standard RequestInit
                fetchOptions.tls = { rejectUnauthorized: false };
            }

            const response = await fetch(transformedUrl, fetchOptions);

            if (response.ok) {
                const details = payload.processed !== undefined 
                    ? ` (${payload.processed} processed, ${payload.failed} failed)` 
                    : '';
                console.log(`   ${statusEmoji} ${statusLabel} webhook sent${details}`);
                return true;
            }

            const text = await response.text();
            const waitSeconds = Math.pow(2, attempt);
            if (attempt < retries) {
                console.warn(`   ⚠️  Webhook attempt ${attempt}/${retries} failed: HTTP ${response.status}`);
                console.warn(`       Retrying in ${waitSeconds}s...`);
                await new Promise(resolve => setTimeout(resolve, waitSeconds * 1000));
            } else {
                console.error(`   ❌ Webhook failed (all ${retries} attempts): HTTP ${response.status}`);
                console.error(`       Response: ${text.substring(0, 100)}...`);
            }
        } catch (error) {
            const waitSeconds = Math.pow(2, attempt);
            if (attempt < retries) {
                console.warn(`   ⚠️  Webhook attempt ${attempt}/${retries} error: ${(error as Error).message}`);
                console.warn(`       Retrying in ${waitSeconds}s...`);
                await new Promise(resolve => setTimeout(resolve, waitSeconds * 1000));
            } else {
                console.error(`   ❌ Webhook failed: ${(error as Error).message}`);
            }
        }
    }

    return false;
}
