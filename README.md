# strychni0x Media Bridge for Nextcloud

A WordPress plugin that connects your Nextcloud to the WordPress media library. Browse the folders of a configured Nextcloud account directly from the WordPress media dialog, preview images and import them into the media library with a single click — no more downloading from Nextcloud and re-uploading to WordPress.

- **WordPress Plugin Directory:** https://wordpress.org/plugins/strychni0x-media-bridge-for-nextcloud
- **License:** GPL-2.0-or-later

## What it does

The plugin adds a dedicated **"Nextcloud" tab** to the WordPress media dialog ("Add Media"). From there an administrator can browse the folders of a configured Nextcloud account, preview the file names and import images into the WordPress media library with a single click. Imported images become regular WordPress attachments (a copy is downloaded from Nextcloud), so they work with every theme, block and page builder without any further integration.

## Features

- Adds a dedicated **"Nextcloud" tab** to the media modal.
- Adds an **"Import from Nextcloud"** button to the Media Library and the "Add New Media File" screen.
- Browse Nextcloud folders via **WebDAV**.
- **Thumbnail previews** with a selectable source: use the Nextcloud preview endpoint (default), or have WordPress generate and cache the thumbnails itself.
- **Paginated** image listing for folders with many photos.
- **Multi-select** that persists across pages, including "select whole folder".
- Import images as standard WordPress attachments — one at a time or several at once.
- Access restricted to **administrators** (`manage_options`); filterable via `ncmb_required_capability`.
- Connection test on the settings screen.

## Security & privacy

- Access is limited to administrators (`manage_options`).
- Uses a **Nextcloud app password** (revocable at any time) instead of your real login.
- The app password is stored **encrypted** at rest (libsodium, with an OpenSSL+HMAC fallback). Optionally define your own key via `NCMB_ENCRYPTION_KEY` in `wp-config.php`.
- No data is sent anywhere other than the Nextcloud URL you configure. All requests originate from your WordPress server.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- A reachable Nextcloud instance with an app password

## Installation

1. Install "Media Bridge for Nextcloud" from the WordPress plugin directory (or upload the plugin folder to `/wp-content/plugins/`) and activate it.
2. In Nextcloud go to **Settings → Security → "Create new app password"** and copy the generated password.
3. In WordPress go to **Settings → Nextcloud Media** and enter the Nextcloud URL, the username, the app password and (optionally) a start folder. Click "Check connection" to verify.
4. Open the "Add Media" dialog in the editor or media library; a new **"Nextcloud" tab** is now available.

## License

Released under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
