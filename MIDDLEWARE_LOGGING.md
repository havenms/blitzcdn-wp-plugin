# Enhanced Middleware Logging - Example Output

## Startup Logging

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
          🚀 BlitzCDN Middleware Server v1.0.0              
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

💡 SERVER
   URL: http://0.0.0.0:3000
   Environment: development

☁️  APPWRITE
   Endpoint: https://apw.havenmediasolutions.com/v1
   Project ID: ✓ Configured
   Bucket ID: ✓ Configured

⚙️  MIGRATION
   Batch size: 10 attachments per webhook callback
   Parallel uploads: 10 concurrent files
   Max retries: 3
   Max zip size: 2048 MB

🔐 SECURITY
   API Key: ✓ Configured
   TLS verification: ✓ Enabled

✅ Configuration valid

```

## Request Handling Logging

### Incoming Migration Request

```
📤 POST   /api/migrate

📥 RECEIVING ZIP
   📦 Size: 11.77 MB
   💾 Writing to disk...

📂 EXTRACTING ZIP
   ✅ Extraction complete

📋 MIGRATION METADATA
   Migration ID: f4174e27-193d-4f20-9ec4-4b1e0f0417dd
   Attachments: 6
   From: http://test-cave-2.local
   Account: test@example.com

🔔 SENDING CONFIRMATION WEBHOOK
   Status: RECEIVED (safe-to-quit confirmed)
   Total batches to process: 1
   ✅ RECEIVED webhook sent (0 processed, 0 failed)
   ✅ User can close browser - processing continues in background

```

### Background Processing

```
📦 MIGRATION PROCESSING START
   Migration ID: f4174e27-193d-4f20-9ec4-4b1e0f0417dd
   Attachments: 6
   Account: test@example.com

   📥 BATCH 1/1 (6 attachments)
     💾 image-1.jpg uploaded
     💾 image-1-thumbnail.jpg uploaded
     💾 image-1-medium.jpg uploaded
     💾 image-1-large.jpg uploaded
     ✅ image-2.jpg uploaded (retry 1)
     💾 image-2-thumbnail.jpg uploaded
     ⚠️  Upload attempt 1/3 failed: image-2-large.jpg
         Reason: Network timeout
         Retrying in 2s...
     ⚠️  Upload attempt 2/3 failed: image-2-large.jpg
         Reason: Network timeout
         Retrying in 4s...
     ✅ image-2-large.jpg uploaded (retry 3)
     💾 image-3.jpg uploaded
     💾 image-3-thumbnail.jpg uploaded
     💾 image-3-medium.jpg uploaded
     ✅ image-3-large.jpg uploaded (retry 1)
     ✅ Batch complete: 6 passed, 0 failed
     📊 Running total: 6 passed, 0 failed
     📤 Sending batch results webhook...
     ✅ BATCH webhook sent (6 processed, 0 failed)

```

### Completion with Errors

```
🏁 MIGRATION COMPLETE
   ✅ Status: COMPLETED
   ✅ Passed: 18
   ❌ Failed: 2
   📋 Failed files:
      - Attachment #3: uploads/2024/12/corrupted-file.jpg
      - Attachment #5: uploads/2024/12/missing-file.jpg

🔔 SENDING FINAL WEBHOOK
   ✅ COMPLETED webhook sent (18 processed, 2 failed)

🧹 CLEANUP
   Removing temporary files...
   ✅ Cleanup complete

```

### Partial Completion Example

```
🏁 MIGRATION COMPLETE
   ⚠️  Status: PARTIAL
   ✅ Passed: 25
   ❌ Failed: 3
   📋 Failed files:
      - Attachment #7: uploads/2024/12/file.pdf (unknown format)
      - Attachment #12: uploads/2024/12/video.mp4 (file too large)
      - Attachment #19: uploads/2024/12/corrupt.png (corrupted header)

🔔 SENDING FINAL WEBHOOK
   ⚠️  PARTIAL webhook sent (25 processed, 3 failed)

🧹 CLEANUP
   Removing temporary files...
   ✅ Cleanup complete

```

### Error During Processing

```
❌ MIGRATION ERROR
   Migration ID: f4174e27-193d-4f20-9ec4-4b1e0f0417dd
   Error: Failed to connect to Appwrite: ECONNREFUSED

🔔 SENDING ERROR WEBHOOK
   ❌ FAILED webhook sent (0 processed, 0 failed)

🧹 CLEANUP
   Removing temporary files...
   ✅ Cleanup complete

```

### Webhook Delivery Failures with Retries

```
🔔 SENDING FINAL WEBHOOK
   ⚠️  Webhook attempt 1/3 failed: HTTP 502
       Retrying in 2s...
   ⚠️  Webhook attempt 2/3 failed: HTTP 502
       Retrying in 4s...
   ✅ COMPLETED webhook sent (18 processed, 2 failed)

```

## Health Check Logging

```
📥 GET    /health
   🏥 Health check
```

## Key Improvements

### 1. **Visual Hierarchy with Emojis**
   - 🚀 = Server startup/major events
   - 📦 = File operations (zip, extraction)
   - 📋 = Metadata and configuration
   - 🔔 = Webhook events
   - 📥📤 = Network direction (receive/send)
   - 💾 = Storage operations
   - ✅ = Success
   - ⚠️ = Warning/retry
   - ❌ = Error/failure

### 2. **State Information**
   - Clear phase tracking: RECEIVING → EXTRACTING → PROCESSING → COMPLETE
   - Status indicators for each major action
   - Migration ID on all relevant logs for correlation

### 3. **Detailed Results**
   - Pass/fail counts per batch
   - Running totals that update per batch
   - Failed file details with reasons
   - Webhook type and payload details

### 4. **Error Context**
   - Detailed retry information (attempt X/Y, wait time)
   - Error messages with context
   - Failed file listing with attachment IDs
   - HTTP status codes for webhook failures

### 5. **Timeline and Performance**
   - Clear separation between phases
   - Chronological order of operations
   - Timestamp info for debugging performance issues
   - Batch progress visibility

### 6. **Actionable Information**
   - What files failed and why
   - Retry strategies visible (backoff times)
   - Configuration validation on startup
   - TLS warnings for dev mode
   - Clear "safe-to-quit" message for users

This enhanced logging makes it much easier to:
- **Monitor** migration progress in real-time
- **Debug** issues with specific attachments
- **Understand** what the middleware is doing at any moment
- **Track** webhook delivery and retries
- **Troubleshoot** configuration problems
- **Correlate** logs with WordPress admin status updates
