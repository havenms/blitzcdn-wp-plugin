import { queueService } from './queue';
import { sendWebhookOnce } from './webhook';

let shouldStop = false;

function sleep(ms: number) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

export async function startWebhookRetryWorker(): Promise<void> {
    console.log('🔁 Webhook retry worker starting...');
    try {
        await queueService.connect();
    } catch (e) {
        console.error('❌ Webhook retry worker failed to connect to Redis:', (e as Error).message);
        return;
    }

    while (!shouldStop) {
        try {
            const raw = await queueService.popFailedWebhook();
            if (!raw) {
                // No failed webhooks, sleep a bit
                await sleep(5000);
                continue;
            }

            let entry: any;
            try {
                entry = JSON.parse(raw);
            } catch (e) {
                console.warn('⚠️  Invalid failed webhook entry, skipping');
                continue;
            }

            const { id, webhook_url, webhook_secret, payload } = entry;
            const prevAttempts: number = entry.attempts || 0;
            const maxAttempts = 10;

            console.log(`🔁 Retrying failed webhook ${id} (previous attempts=${prevAttempts})`);

            // Try sending once (no internal retry here)
            const ok = await sendWebhookOnce(webhook_url, webhook_secret, payload);
            if (ok) {
                console.log(`   ✅ Webhook retry succeeded for ${id}`);
                continue;
            }

            // Not successful — requeue with incremented attempts and backoff
            const attempts = prevAttempts + 1;
            if (attempts >= maxAttempts) {
                console.error(`   ❌ Webhook ${id} exceeded max attempts (${attempts}). Discarding.`);
                // Optionally persist to dead-letter file or log more details for manual inspection
                continue;
            }

            // Backoff sleep before re-queueing to avoid hot-looping
            const backoffSeconds = Math.min(Math.pow(2, attempts), 3600); // max 1 hour
            console.warn(`   ⚠️  Webhook ${id} failed; will retry after ${backoffSeconds}s (attempt ${attempts}/${maxAttempts})`);
            await sleep(backoffSeconds * 1000);

            entry.attempts = attempts;
            entry.last_attempt_at = new Date().toISOString();
            await queueService.pushFailedWebhook(entry);
        } catch (err) {
            console.error('❌ Webhook retry worker error:', (err as Error).message);
            // Sleep before retrying on unexpected errors
            await sleep(5000);
        }
    }
}

export function stopWebhookRetryWorker() {
    shouldStop = true;
}
