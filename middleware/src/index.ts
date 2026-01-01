/**
 * BlitzCDN Middleware Server
 * 
 * A background processing service for handling media migrations
 * from WordPress to Appwrite storage.
 * 
 * Features:
 * - Redis-backed persistent queue for job management
 * - Automatic recovery of interrupted migrations
 * - Cancellation support
 * - Multi-zip batch support per migration
 * - Webhook notifications to WordPress
 */

import { mkdir } from 'fs/promises';
import { existsSync } from 'fs';
import { config, validateConfig } from './config';
import { queueService, startWorker } from './services';
import { handleRoot, handleHealth, handleMigrate, handleCancel, handleStatus, verifyApiKey } from './handlers';

// ASCII Banner
const banner = `
BlitzCDN Middleware Service
===========================
`;

/**
 * CORS headers for browser requests
 */
const corsHeaders = {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization',
};

/**
 * Handle OPTIONS preflight requests
 */
function handleCors(): Response {
    return new Response(null, {
        status: 204,
        headers: corsHeaders,
    });
}

/**
 * Add CORS headers to response
 */
function withCors(response: Response): Response {
    const newHeaders = new Headers(response.headers);
    for (const [key, value] of Object.entries(corsHeaders)) {
        newHeaders.set(key, value);
    }
    return new Response(response.body, {
        status: response.status,
        statusText: response.statusText,
        headers: newHeaders,
    });
}

/**
 * Main request router
 */
async function handleRequest(request: Request): Promise<Response> {
    const url = new URL(request.url);
    const path = url.pathname;
    const method = request.method;

    // Handle CORS preflight
    if (method === 'OPTIONS') {
        return handleCors();
    }

    console.log(`\n═══════════════════════════════════════════════════════════════`);
    console.log(`📨 ${method} ${path}`);
    console.log(`═══════════════════════════════════════════════════════════════`);

    let response: Response;

    try {
        // Route requests
        if (path === '/' && method === 'GET') {
            response = handleRoot();
        } else if (path === '/health' && method === 'GET') {
            response = await handleHealth();
        } else if (path === '/api/migrate' && method === 'POST') {
            response = await handleMigrate(request);
        } else if (path === '/api/cancel' && method === 'POST') {
            response = await handleCancel(request);
        } else if (path === '/api/status' && method === 'GET') {
            response = await handleStatus(request);
        } else {
            response = new Response(JSON.stringify({ error: 'Not found' }), {
                status: 404,
                headers: { 'Content-Type': 'application/json' },
            });
        }
    } catch (error) {
        console.error('Request error:', error);
        response = new Response(JSON.stringify({
            error: 'Internal server error',
            message: (error as Error).message,
        }), {
            status: 500,
            headers: { 'Content-Type': 'application/json' },
        });
    }

    return withCors(response);
}

/**
 * Initialize the server
 */
async function init() {
    console.log(banner);

    // Validate configuration
    const configErrors = validateConfig();
    if (configErrors.length > 0) {
        console.error('\n⚠️  Configuration Issues:');
        configErrors.forEach(err => console.error(`   - ${err}`));
        console.error('\nPlease check your environment variables.\n');
    }

    // Create data directories
    const jobsDir = `${config.dataDir}/jobs`;
    if (!existsSync(jobsDir)) {
        await mkdir(jobsDir, { recursive: true });
        console.log(`📁 Created jobs directory: ${jobsDir}`);
    }

    // Initialize queue service and recover any interrupted jobs
    console.log('\n🔄 Initializing queue service...');
    try {
        await queueService.connect();
        await queueService.recoverProcessingJobs();
        const stats = await queueService.getStats();
        console.log(`   📊 Queue stats: ${stats.pendingJobs} pending, ${stats.processingJobs} processing`);

        if (stats.pendingJobs > 0 || stats.processingJobs > 0) {
            console.log('\n🔧 Starting worker for pending jobs...');
            startWorker().catch(console.error);
        }

        // Start webhook retry worker if there are persisted failed webhooks
        const failedWebhookCount = await queueService.getFailedWebhookCount();
        if (failedWebhookCount > 0) {
            console.log(`\n🔁 Found ${failedWebhookCount} failed webhooks - starting webhook retry worker...`);
        }
        // Always start webhook retry worker so it can pick up new failures
        import('./services/webhookRetry').then(mod => mod.startWebhookRetryWorker().catch(console.error));
    } catch (e) {
        console.error('   ⚠️  Queue initialization failed (Redis may not be available)');
        console.error(`   Error: ${(e as Error).message}`);
    }

    // Start server
    const server = Bun.serve({
        port: config.port,
        fetch: handleRequest,
    });

    console.log(`\n🚀 Server running at http://localhost:${server.port}`);
    console.log('\n📡 Available endpoints:');
    console.log('   POST /api/migrate - Upload migration zip');
    console.log('   POST /api/cancel  - Cancel migration by ID');
    console.log('   GET  /api/status  - Get queue/migration status');
    console.log('   GET  /health      - Health check');

    // Graceful shutdown
    process.on('SIGTERM', async () => {
        console.log('\n⏹️  Received SIGTERM, shutting down gracefully...');
        server.stop();
        process.exit(0);
    });

    process.on('SIGINT', async () => {
        console.log('\n⏹️  Received SIGINT, shutting down gracefully...');
        server.stop();
        process.exit(0);
    });
}

// Start the server
init().catch(console.error);
