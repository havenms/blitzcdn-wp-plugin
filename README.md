# BlitzCDN Media Offload

Offload WordPress Media Library files to Appwrite Storage and serve them through a CDN.

## Features

- **Offload to Appwrite**: Automatically uploads media to Appwrite Storage.
- **CDN Delivery**: Serves files via your configured CDN domain.
- **Safe Delete**: Optionally deletes local files only after successful verification.
- **Bulk Migration**: Migrate existing media library items.
- **Elementor Support**: Automatically clears Elementor cache after uploads.
- **WooCommerce Support**: Compatible with product images.

## Installation

1. Clone or download this plugin into `wp-content/plugins/blitzcdn`.
2. Run `composer install` in the plugin directory to install dependencies.
3. Activate the plugin in WordPress Admin.
4. Go to **Settings -> BlitzCDN** and configure your Appwrite credentials.

## Configuration

- **Project ID**: Your Appwrite Project ID.
- **API Key**: An API Key with `storage.write` and `storage.read` permissions.
- **Bucket ID**: The Storage Bucket ID where files will be stored.
- **CDN Domain**: The domain to serve files from (e.g., `files.blitzcdn.net`).
- **Appwrite Endpoint**: Your Appwrite API endpoint (default: `https://cloud.appwrite.io/v1`).

## Requirements

- PHP 7.4+
- WordPress 5.0+
- Appwrite 1.0+
