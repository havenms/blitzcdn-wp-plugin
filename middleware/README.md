# BlitzCDN Middleware Service

A high-performance Bun-powered middleware service for processing zip-based media migrations from WordPress to Appwrite Storage.

## Overview

This middleware service receives zip archives containing WordPress media files and metadata, processes them in parallel, uploads to Appwrite Storage, and sends results back to WordPress via webhook callback.

## Prerequisites

- [Bun](https://bun.sh) v1.0 or later
- Appwrite instance with Storage enabled
- Network access to both WordPress site and Appwrite

## Installation

```bash
cd middleware
bun install
```

## Configuration

Copy `.env.example` to `.env` and configure:

```bash
cp .env.example .env
```

### Understanding Batch Settings

The middleware uses two different "batch" concepts:

1. **BATCH_SIZE** (default: 10) - Number of attachments processed per webhook callback
   - After processing this many attachments, the middleware sends a webhook to WordPress
   - WordPress updates postmeta for these attachments immediately
   - Example: 2000 attachments = 200 webhook callbacks (2000 / 10)

2. **PARALLEL_UPLOADS** (default: 5) - Concurrent file uploads within a batch
   - While processing each batch, this many files upload simultaneously to Appwrite
   - Higher values = faster uploads but more memory/connection usage
   - Example: Processing batch of 10 attachments (~40 files with sizes) = 8 parallel upload rounds

**Note**: The WordPress plugin's "Zip Batch Size" setting is different - it controls how many attachments to include in one zip file (separate from middleware batching).

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `PORT` | Server port (default: 3000) | No |
| `HOST` | Server host (default: 0.0.0.0) | No |
| `API_KEY` | API key for authenticating requests | No |
| `APPWRITE_ENDPOINT` | Appwrite API endpoint | Yes |
| `APPWRITE_PROJECT_ID` | Appwrite project ID | Yes |
| `APPWRITE_API_KEY` | Appwrite API key with Storage write permissions | Yes |
| `APPWRITE_BUCKET_ID` | Storage bucket ID | Yes |
| `APPWRITE_DB_ID` | Database ID for tracking uploads (optional) | No |
| `APPWRITE_COLLECTION_ID` | Collection ID for tracking uploads (optional) | No |
| `APPWRITE_CDN_DOMAIN` | Custom CDN domain for file URLs | No |
| `BATCH_SIZE` | Attachments per webhook callback batch (default: 10) | No |
| `PARALLEL_UPLOADS` | Concurrent file uploads within each batch (default: 5) | No |
| `MAX_RETRIES` | Max retry attempts for failed uploads (default: 3) | No |
| `NODE_TLS_REJECT_UNAUTHORIZED` | Set to `0` to disable SSL verification (local dev only) | No |

## Running

### Development

```bash
bun run dev
```

This runs the server with hot-reload enabled.

**Local Development with Self-Signed SSL**: If your WordPress site uses a self-signed certificate (e.g., Local by Flywheel, Laravel Valet), add to `.env`:

```bash
NODE_TLS_REJECT_UNAUTHORIZED=0
```

⚠️ **Never use this in production** - it disables SSL certificate verification.

### Production

```bash
bun run start
```

Or run directly:

```bash
bun run server.ts
```

## API Endpoints

### `POST /api/migrate`

Upload a zip file for migration processing.

**Headers:**
- `Authorization: Bearer <API_KEY>` (if API_KEY is configured)
- `Content-Type: multipart/form-data`

**Form Data:**
- `file` - The zip archive containing media files and metadata.json
- `migration_id` - Optional migration identifier

**Response (202 Accepted):**
```json
{
  "success": true,
  "message": "Migration started",
  "migration_id": "uuid",
  "attachment_count": 100
}
```

### `GET /health`

Health check endpoint.

**Response:**
```json
{
  "status": "ok",
  "timestamp": "2024-01-01T00:00:00.000Z",
  "config": {
    "appwrite": {
      "endpoint": "https://cloud.appwrite.io/v1",
      "projectId": "***configured***",
      "bucketId": "***configured***"
    },
    "parallelUploads": 5,
    "maxRetries": 3
  },
  "errors": []
}
```

## Zip File Structure

The middleware expects zip files with the following structure:

```
migration.zip
├── metadata.json
├── 2024/
│   └── 01/
│       ├── image.jpg
│       ├── image-150x150.jpg
│       ├── image-300x200.jpg
│       └── image-1024x768.jpg
└── 2024/
    └── 02/
        └── another-image.png
```

### metadata.json Format

```json
{
  "version": "1.0",
  "account_email": "user@example.com",
  "webhook_url": "https://site.com/wp-json/blitzcdn/v1/migration-webhook",
  "webhook_secret": "secure-token",
  "site_url": "https://site.com",
  "migration_id": "uuid",
  "created_at": "2024-01-01T00:00:00+00:00",
  "attachments": [
    {
      "attachment_id": 123,
      "original_file": "2024/01/image.jpg",
      "local_url": "https://site.com/wp-content/uploads/2024/01/image.jpg",
      "sizes": [
        {
          "name": "thumbnail",
          "file": "2024/01/image-150x150.jpg",
          "local_url": "https://site.com/wp-content/uploads/2024/01/image-150x150.jpg",
          "width": 150,
          "height": 150
        }
      ]
    }
  ]
}
```

## Webhook Callback

After processing, the middleware sends a POST request to the `webhook_url` specified in metadata.json.

**Headers:**
- `Content-Type: application/json`
- `Authorization: Bearer <webhook_secret>`
- `X-Webhook-Secret: <webhook_secret>`

**Payload:**
```json
{
  "migration_id": "uuid",
  "status": "completed",
  "results": [
    {
      "attachment_id": 123,
      "file_id": "appwrite-file-id",
      "cdn_url": "https://cdn.example.com/...",
      "sizes": {
        "thumbnail": {
          "file_id": "appwrite-file-id-2",
          "url": "https://cdn.example.com/..."
        }
      }
    }
  ],
  "errors": []
}
```

**Status Values:**
- `completed` - All files processed successfully
- `partial` - Some files processed, some failed
- `failed` - No files processed successfully

## Deployment

### Docker

```dockerfile
FROM oven/bun:1

WORKDIR /app
COPY package.json bun.lockb ./
RUN bun install --production

COPY . .

EXPOSE 3000
CMD ["bun", "run", "server.ts"]
```

### Docker Compose

```yaml
version: '3.8'
services:
  blitzcdn-middleware:
    build: .
    ports:
      - "3000:3000"
    environment:
      - APPWRITE_ENDPOINT=${APPWRITE_ENDPOINT}
      - APPWRITE_PROJECT_ID=${APPWRITE_PROJECT_ID}
      - APPWRITE_API_KEY=${APPWRITE_API_KEY}
      - APPWRITE_BUCKET_ID=${APPWRITE_BUCKET_ID}
    restart: unless-stopped
```

### Systemd Service

```ini
[Unit]
Description=BlitzCDN Middleware
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/blitzcdn-middleware
ExecStart=/usr/local/bin/bun run server.ts
Restart=on-failure
EnvironmentFile=/opt/blitzcdn-middleware/.env

[Install]
WantedBy=multi-user.target
```

## Security Considerations

1. **API Key Protection**: Always configure `API_KEY` in production to prevent unauthorized uploads.

2. **Webhook Secret**: The WordPress plugin sends a webhook secret that must be verified by the callback endpoint.

3. **HTTPS**: Deploy behind a reverse proxy (nginx, Caddy) with SSL termination.

4. **File Validation**: The middleware validates zip structure and sanitizes file paths.

5. **Rate Limiting**: Consider adding rate limiting at the reverse proxy level.

## Monitoring

Monitor the service using the `/health` endpoint. Set up alerts for:
- HTTP 5xx responses
- High response times
- Disk space (for temporary zip extraction)

## Troubleshooting

### Common Issues

1. **"metadata.json not found in zip"**
   - Ensure the WordPress plugin is generating valid zip files
   - Check that metadata.json is at the root level of the zip

2. **"Failed to extract zip"**
   - Ensure `unzip` command is available in the system
   - Check file permissions in /tmp directory

3. **"Webhook failed"**
   - Verify webhook URL is accessible from middleware server
   - Check webhook secret matches between WordPress and middleware
   - Ensure WordPress REST API is enabled

4. **Upload failures**
   - Verify Appwrite credentials and permissions
   - Check bucket exists and allows file creation
   - Review Appwrite logs for specific errors

## License

MIT License - see main repository for details.
