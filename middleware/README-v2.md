# BlitzCDN Middleware Service v2.0

A high-performance Bun-powered middleware service for processing zip-based media migrations from WordPress to Appwrite Storage, featuring **Redis-backed persistent queue** for reliable background processing.

## Overview

This middleware service:
1. Receives zip archives containing WordPress media files and metadata
2. Queues jobs in Redis for reliable, persistent processing
3. Processes files in parallel, uploading to Appwrite Storage
4. Sends webhook callbacks to WordPress with progress updates
5. Supports cancellation and automatic recovery after restarts

## Key Features

- **Persistent Queue**: Redis-backed job queue survives container restarts
- **Auto-Recovery**: Interrupted jobs automatically resume on startup
- **Cancellation Support**: Cancel migrations via API endpoint
- **Multi-Zip Migrations**: Single migration can span multiple zip uploads
- **Progress Webhooks**: Real-time progress updates to WordPress

## Prerequisites

- [Bun](https://bun.sh) v1.0 or later
- Redis 7.0+ (for persistent queue)
- Appwrite instance with Storage enabled
- Network access to both WordPress site and Appwrite

## Installation

### Docker (Recommended)

```bash
cd middleware
docker-compose up -d
```

This starts both the middleware service and Redis.

### Manual

```bash
cd middleware
bun install

# Start Redis separately, then:
bun run start
```

## Configuration

Copy `.env.example` to `.env` and configure:

```bash
cp .env.example .env
```

### Environment Variables

| Variable | Description | Required | Default |
|----------|-------------|----------|---------|
| `PORT` | Server port | No | 3000 |
| `HOST` | Server host | No | 0.0.0.0 |
| `API_KEY` | API key for authenticating requests | No | - |
| `REDIS_URL` | Redis connection URL | No | redis://localhost:6379 |
| `DATA_DIR` | Directory for temporary job files | No | ./data |
| `APPWRITE_ENDPOINT` | Appwrite API endpoint | Yes | - |
| `APPWRITE_PROJECT_ID` | Appwrite project ID | Yes | - |
| `APPWRITE_API_KEY` | Appwrite API key with Storage write | Yes | - |
| `APPWRITE_BUCKET_ID` | Storage bucket ID | Yes | - |
| `APPWRITE_CDN_DOMAIN` | Custom CDN domain for file URLs | No | - |
| `BATCH_SIZE` | Attachments per webhook callback | No | 10 |
| `PARALLEL_UPLOADS` | Concurrent file uploads | No | 5 |
| `MAX_RETRIES` | Max retry attempts for uploads | No | 3 |
| `MAX_ZIP_SIZE_MB` | Maximum zip file size in MB | No | 2048 |
| `NODE_TLS_REJECT_UNAUTHORIZED` | Set to `0` for self-signed SSL | No | - |

### Understanding Batch Settings

1. **BATCH_SIZE** (default: 10) - Number of attachments processed per webhook callback
   - After processing this many attachments, middleware sends a webhook
   - WordPress updates postmeta for these attachments immediately

2. **PARALLEL_UPLOADS** (default: 5) - Concurrent file uploads within a batch
   - Higher values = faster uploads but more memory/connection usage

## Running

### Docker Compose

```bash
# Start services
docker-compose up -d

# View logs
docker-compose logs -f middleware

# Stop services
docker-compose down

# Stop and remove data
docker-compose down -v
```

### Development

```bash
bun run dev
```

Runs with hot-reload. Requires Redis running separately.

### Production

```bash
bun run start
```

## API Endpoints

### `POST /api/migrate`

Upload a zip file for migration processing.

**Request:**
- Content-Type: `multipart/form-data`
- Body:
  - `file`: Zip file containing media and metadata.json
  - `migration_id`: (optional) Migration identifier

**Response:**
```json
{
  "success": true,
  "message": "Migration queued",
  "migration_id": "uuid",
  "job_id": "job-uuid-timestamp",
  "zip_batch_number": 1,
  "attachment_count": 100,
  "total_files": 450,
  "queue_position": 1
}
```

### `POST /api/cancel`

Cancel a migration by ID.

**Request:**
```json
{
  "migration_id": "uuid"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Migration cancelled",
  "cancelled_jobs": 2
}
```

### `GET /api/status`

Get queue status or specific migration status.

**Query Parameters:**
- `migration_id` (optional): Get specific migration status

**Response (no migration_id):**
```json
{
  "stats": {
    "pendingJobs": 2,
    "processingJobs": 1
  },
  "activeMigrations": [...],
  "worker_running": true
}
```

**Response (with migration_id):**
```json
{
  "migration": {
    "migration_id": "uuid",
    "status": "active",
    "totals": {
      "total_attachments": 1000,
      "processed_attachments": 450,
      "total_files": 4500,
      "uploaded_files": 2000,
      "failed_files": 5
    }
  },
  "jobs": [...]
}
```

### `GET /health`

Health check endpoint.

**Response:**
```json
{
  "status": "ok",
  "redis": "connected",
  "queue": {
    "pendingJobs": 0,
    "processingJobs": 0
  },
  "worker_running": true
}
```

## Architecture

```
middleware/
├── src/
│   ├── index.ts        # Main entry point
│   ├── config.ts       # Configuration management
│   ├── types.ts        # TypeScript interfaces
│   ├── handlers.ts     # HTTP request handlers
│   └── services/
│       ├── queue.ts    # Redis queue service
│       ├── appwrite.ts # Appwrite storage service
│       ├── webhook.ts  # Webhook notification service
│       └── processor.ts # Job processing logic
├── docker-compose.yml  # Docker orchestration
├── Dockerfile          # Container build
└── package.json
```

### Redis Data Structure

- `blitzcdn:jobs` - Hash of all jobs (job_id -> job JSON)
- `blitzcdn:migrations` - Hash of migrations (migration_id -> state JSON)
- `blitzcdn:queue` - List of pending job IDs
- `blitzcdn:processing` - Set of currently processing job IDs
- `blitzcdn:cancelled:{migration_id}` - Flag for cancelled migrations

### Job Lifecycle

1. **Pending**: Job created, added to queue
2. **Processing**: Worker dequeued job, processing files
3. **Completed/Failed/Cancelled**: Final state

### Recovery Process

On startup:
1. Check for jobs stuck in "processing" state
2. Move them back to pending queue
3. Resume processing

## Webhook Payloads

### Status: `received`
Sent immediately when zip is uploaded. WordPress can show "safe to close" message.

### Status: `batch`
Sent after each batch of attachments is processed.

```json
{
  "migration_id": "uuid",
  "status": "batch",
  "results": [...],
  "errors": [...],
  "batch_number": 5,
  "total_batches": 20,
  "processed": 50,
  "failed": 2,
  "total_files_uploaded": 200
}
```

### Status: `completed` | `partial` | `failed` | `cancelled`
Sent when migration finishes.

## Troubleshooting

### Redis Connection Failed

```bash
# Check Redis is running
docker-compose ps redis

# Check Redis logs
docker-compose logs redis
```

### Jobs Stuck in Processing

Jobs stuck in processing state will be recovered automatically on restart. You can also manually reset:

```bash
# Connect to Redis
docker-compose exec redis redis-cli

# Clear processing set
SMEMBERS blitzcdn:processing
DEL blitzcdn:processing
```

### Large File Upload Fails

Increase `MAX_ZIP_SIZE_MB` in environment variables.

### SSL Certificate Errors

For local development with self-signed certificates:

```bash
NODE_TLS_REJECT_UNAUTHORIZED=0
```

⚠️ **Never use this in production!**

## Migration from v1.0

The v2.0 middleware is backward compatible with existing WordPress plugin. Changes:

1. Add Redis to your infrastructure
2. Update docker-compose.yml (already included)
3. Restart middleware

Existing migrations in progress may need to be restarted.
