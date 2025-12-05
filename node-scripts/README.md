# node-scripts

BlitzCDN management scripts for Appwrite.

## Installation

```bash
bun install
```

## Usage

### Delete files by email

Delete all files associated with a specific email address from the Appwrite database and storage bucket.

```bash
bun index.ts --email user@example.com
# or
bun index.ts -e user@example.com
```

The script will:

1. Query the database for all documents matching the email
2. Extract all `fileId`s from those documents
3. Delete all files from the storage bucket in parallel batches (50 files at a time)
4. Display a beautiful real-time progress UI using ink.js


**Features:**

- ⚡ **Fast**: Parallel batch processing (50 files per batch)
- 🎨 **Beautiful UI**: Real-time progress with ink.js
- 📊 **Statistics**: Shows deleted count, failed count, and total space freed
- 🛡️ **Error handling**: Displays errors for failed deletions

## Configuration

Update the constants in `index.ts` if your Appwrite setup differs:

- `APPWRITE_DB_ID`: Database ID (default: `'db'`)
- `APPWRITE_COLLECTION_ID`: Collection ID (default: `'uploads'`)

This project was created using `bun init` in bun v1.3.2. [Bun](https://bun.com) is a fast all-in-one JavaScript runtime.
