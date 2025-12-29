# BlitzCDN Zip Migration

This document explains how the zip-based migration works between the WordPress plugin and the BlitzCDN middleware, and how to configure, run, and troubleshoot it.

## What the flow does
1) WordPress packages selected media into a zip (originals + generated sizes) along with `metadata.json` (attachment map, webhook URL, secret, site URL, migration ID).
2) WordPress uploads the zip to the middleware (`/api/migrate`). Keep the tab open during this upload.
3) Middleware validates the zip and immediately sends a `status: received` callback to WordPress so the UI can show “safe to quit” once the upload finished.
4) Middleware uploads media to Appwrite in batches of 10 attachments. After each batch it POSTs a `status: batch` payload back to WordPress with the file IDs/CDN URLs needed for postmeta + URL rewriting.
5) Middleware sends a final `status: completed | partial | failed` summary. WordPress continues to store postmeta per batch and rewrites URLs from the callbacks.

## Prerequisites
- WordPress site reachable by the middleware (the webhook URL must be accessible from the middleware host).
- Plugin settings completed: Account Email, Middleware URL, Webhook Secret (auto-generates), Appwrite env vars or settings in place.
- Zip migration UI enabled (Fast Migration section).

## Key settings (Settings → BlitzCDN)
- **Middleware URL**: Base URL of the middleware (e.g., `https://middleware.example.com`).
- **Middleware API Key** (optional): Bearer token the middleware expects.
- **Webhook Secret**: Shared secret; middleware sends it in headers; WordPress verifies.
- **Zip Batch Size**: Max attachments per zip (1–10000). Note: each attachment can include several files (original + sizes).
- **Hard cap**: A zip will never include more than 10,000 files (originals + sizes). Attachments beyond the cap are excluded from that zip.

## End-to-end flow details
- **Packaging**: `ZipMigrator::create_migration_zip()` builds `metadata.json` and adds media files. It stops adding files after 10,000 files; only attachments that contributed at least one file are included in the metadata sent to middleware.
- **Upload to middleware**: WordPress POSTs the zip to `/<middleware>/api/migrate` with multipart form data (`file`, `migration_id`). Keep the tab open until the middleware sends back the receipt.
- **Receipt**: Middleware immediately POSTs `status: received` to `https://your-site/wp-json/blitzcdn/v1/migration-webhook`. The admin UI shows a safe-to-quit alert; browser can be closed after this point.
- **Processing in middleware**: The Bun/TypeScript server extracts the zip, then processes 10 attachments per batch. Each batch callback (`status: batch`) contains the Appwrite file IDs/CDN URLs for those attachments so WordPress can update postmeta and rewrite URLs server-side.
- **Final callback**: Middleware POSTs a summary `status: completed | partial | failed` with totals. Postmeta is already written per batch; the final call finalizes status and cleanup.

## Operational notes
- After the middleware sends the `received` callback you can close the browser; all remaining work is server-to-server. Ensure the site is reachable from the middleware (tunnel if needed).
- Keep PHP/webserver timeouts high enough for the initial upload/receipt, or offload heavy work to a background queue (see “Background processing” below).
- Safe-delete (if enabled) removes local files only after successful uploads.

## Background processing (optional)
Callbacks already run server-side (no browser required after the receipt alert). If you still want to defer the per-batch updates, consider enqueueing inside the webhook handler:
- **Action Scheduler**: enqueue a job in the webhook handler; let a runner/cron process it.
- **WP-Cron single event**: schedule a one-off task; requires real cron or traffic to trigger.
- **External worker**: store payload and process via `wp-cli`/system cron.

## Limits and batching
- UI batch size: up to 10,000 attachments per zip.
- File cap: 10,000 files per zip; attachments beyond the cap are skipped for that zip.
- Middleware callback batch size: 10 attachments per callback (`BATCH_SIZE` env, default 10). Each callback contains the IDs/URLs for that batch only.
- Parallel uploads: handled by middleware; configurable via middleware `.env` (`PARALLEL_UPLOADS`).

## Webhook callbacks
- `received`: Acknowledges the zip upload, sets `safe_to_quit: true`, and includes `total_batches`.
- `batch`: Contains `batch_number`, `total_batches`, `processed`, `failed`, plus `results`/`errors` for that batch of 10 attachments.
- `completed | partial | failed`: Summary counts; most postmeta work is already handled by the batch callbacks.

**Example batch payload:**
```json
{
  "migration_id": "uuid",
  "status": "batch",
  "batch_number": 2,
  "total_batches": 5,
  "processed": 20,
  "failed": 1,
  "results": [
    {
      "attachment_id": 123,
      "file_id": "file-id",
      "cdn_url": "https://cdn.example.com/...",
      "sizes": {
        "thumbnail": {
          "file_id": "file-id-2",
          "url": "https://cdn.example.com/..."
        }
      }
    }
  ],
  "errors": []
}
```

## Security
- Webhook Secret is required and validated against `Authorization: Bearer <secret>` or `X-Webhook-Secret`.
- Use HTTPS for middleware and site.
- Do not expose middleware API key publicly.

## Troubleshooting
- **Webhook 404/401**: Ensure `ZipMigrator` is instantiated (plugin activated), permalink/REST works, and the secret matches.
- **“Bucket not found” when viewing URLs**: Confirm URLs include `?project=<APPWRITE_PROJECT_ID>` (middleware already fixed), and bucket IDs match.
- **Uploads stall/time out**: Increase PHP `max_execution_time` / webserver timeout, or shift to background processing.
- **No webhook hits**: Verify middleware can reach the site (DNS/firewall/tunnel) and check middleware logs.
- **Partial zip**: Hitting the 10k file cap will exclude remaining attachments; run another migration for the rest.

## Middleware environment hints
- `APPWRITE_ENDPOINT`, `APPWRITE_PROJECT_ID`, `APPWRITE_API_KEY`, `APPWRITE_BUCKET_ID`, `APPWRITE_DB_ID`, `APPWRITE_COLLECTION_ID`, `APPWRITE_CDN_DOMAIN`, `PARALLEL_UPLOADS`, `BATCH_SIZE`, `MAX_RETRIES`.
- CDN URL builder always appends `?project=<APPWRITE_PROJECT_ID>` even when `APPWRITE_CDN_DOMAIN` is set.

## Quick run steps
1) Configure settings in WordPress (Account Email, Middleware URL, Webhook Secret).
2) Set `Zip Batch Size` as desired (<=10k attachments).
3) Start Fast Migration from the admin UI.
4) Watch middleware logs for processing; results arrive via webhook.
5) Verify attachment metadata/CDN URLs and site content rewrites.

## Running the Middleware (local & production)

The middleware is a Bun TypeScript server located in the `middleware/` folder. It exposes `/api/migrate` and `/health` endpoints.

1) Install dependencies (from the `middleware` directory):

```bash
cd middleware
# Use Bun to install (recommended)
bun install
# Or with npm/yarn if you prefer (not required for Bun runtime)
```

2) Configure environment variables by copying `.env.example` to `.env` and editing values:

```bash
cp .env.example .env
# Edit .env and set APPWRITE_ENDPOINT, APPWRITE_PROJECT_ID, APPWRITE_API_KEY, APPWRITE_BUCKET_ID, etc.
```

3) Run the server:

```bash
# Development (watch):
bun run dev

# Production / simple run:
bun run start
```

4) Confirm the service is healthy:

```bash
curl http://localhost:3000/health
```

The `/health` response will indicate whether required Appwrite config values are present.

## Testing the full zip migration flow

1) From WordPress admin, configure the `Middleware URL` to point to your middleware instance (for local testing you can use `http://localhost:3000`). If your WordPress site is not reachable from the middleware (e.g. Local by Flywheel), use a tunnel like `ngrok` or a publicly reachable host.

2) Start a small migration from the BlitzCDN admin UI (choose a small `Zip Batch Size`) and observe the middleware logs.

3) Manual endpoint tests:

- Test health:
```bash
curl -sS http://localhost:3000/health | jq
```

- Simulate uploading a zip (quick test using existing small zip):
```bash
curl -X POST \
	-H "Authorization: Bearer <MIDDLEWARE_API_KEY>" \
	-F "file=@path/to/test-migration.zip" \
	-F "migration_id=test-123" \
	http://localhost:3000/api/migrate
```

The server will accept the file and immediately return an acceptance response while it processes the zip asynchronously.

4) Testing webhook delivery:

- If your WordPress site is reachable, the middleware will POST results to the `webhook_url` embedded in the zip's `metadata.json` (this is generated by the plugin). If you need to test webhook delivery locally, you can:
	- Use `ngrok` to expose your local WordPress site and set the `Middleware URL` to the tunnel address.
	- Or, simulate the middleware's webhook POST with `curl` directly against your WordPress REST endpoint:

```bash
curl -X POST \
	-H "Content-Type: application/json" \
	-H "Authorization: Bearer <WEBHOOK_SECRET>" \
	-d '{"migration_id":"test-123","status":"completed","results":[],"errors":[]}' \
	https://your-site/wp-json/blitzcdn/v1/migration-webhook
```

If the webhook is reachable and the secret matches, WordPress will process the payload and update attachment metadata.

## Verifying results

- Check plugin migration status in the settings page (Migration Status REST endpoint).
- Inspect an attachment's postmeta keys: `_blitzcdn_file_id`, `_blitzcdn_cdn_url`, `_blitzcdn_sizes`.
- Visit the CDN URLs (they include `?project=<APPWRITE_PROJECT_ID>`) to confirm the files are served.

## Troubleshooting tips for middleware

- If uploads fail with `Storage bucket with the requested ID could not be found`, confirm `APPWRITE_BUCKET_ID` and `APPWRITE_PROJECT_ID` in `.env` match the Appwrite instance and that the CDN/file URLs include `?project=<projectId>`.
- If middleware cannot reach WordPress webhooks, ensure network connectivity or use a tunnel.
- If Bun-specific errors appear, ensure Bun is installed (`bun --version`) and you run `bun install` before starting.

---