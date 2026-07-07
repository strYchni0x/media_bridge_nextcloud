# strychni0x Media Bridge for Nextcloud & ownCloud

A WordPress plugin that connects your Nextcloud or ownCloud servers to the WordPress media library. Browse the folders of one or more configured cloud accounts directly from the WordPress media dialog, preview images and import them into the media library with a single click — no more downloading from the cloud and re-uploading to WordPress.

- **WordPress Plugin Directory:** https://wordpress.org/plugins/strychni0x-media-bridge-for-nextcloud
- **License:** GPL-2.0-or-later

## What it does

The plugin adds a dedicated **"Cloud" tab** to the WordPress media dialog ("Add Media"). From there an administrator can browse the folders of one or more configured cloud accounts, preview the file names and import images into the WordPress media library with a single click. Imported images become regular WordPress attachments (a copy is downloaded from the cloud server), so they work with every theme, block and page builder without any further integration.

## Supported clouds

WebDAV-based servers of the Nextcloud family that support app passwords:

- **Nextcloud**
- **ownCloud**

Connect several accounts of mixed types at the same time and switch between them with a dropdown in the media dialog.

## Features

- Adds a dedicated **"Cloud" tab** to the media modal.
- Adds an **"Import from cloud"** button to the Media Library and the "Add New Media File" screen.
- **Multiple accounts** (Nextcloud / ownCloud) usable at once, with an account picker.
- Browse cloud folders via **WebDAV**.
- **Thumbnail previews** with a per-account selectable source: use the server's native preview/thumbnail endpoint (default), or have WordPress generate and cache the thumbnails itself (a reliable fallback for any server).
- **Paginated** image listing for folders with many photos.
- **Multi-select** that persists across pages, including "select whole folder".
- Import images as standard WordPress attachments — one at a time or several at once.
- Access restricted to **administrators** (`manage_options`); filterable via `ncmb_required_capability`.
- Per-account connection test on the settings screen.

## Security & privacy

- Access is limited to administrators (`manage_options`).
- Uses a **cloud app password** (revocable at any time) instead of your real login.
- The app password is stored **encrypted** at rest (libsodium, with an OpenSSL+HMAC fallback). Optionally define your own key via `NCMB_ENCRYPTION_KEY` in `wp-config.php`.
- No data is sent anywhere other than the server URLs you configure. All requests originate from your WordPress server.

## Requirements

- WordPress 6.0 or newer
- PHP 7.4 or newer
- A reachable Nextcloud or ownCloud instance with an app password

## Installation

1. Install the plugin from the WordPress plugin directory (or upload the plugin folder to `/wp-content/plugins/`) and activate it.
2. In your cloud (Nextcloud/ownCloud) go to **Settings → Security → "Create new app password"** and copy the generated password.
3. In WordPress go to **Settings → Cloud Media**, choose the cloud type and enter the server URL, the username, the app password and (optionally) a start folder. Click "Check connection" to verify. Use **"Add cloud"** to connect more accounts.
4. Open the "Add Media" dialog in the editor or media library; a new **"Cloud" tab** is now available.

## License

Released under the [GNU General Public License v2.0 or later](https://www.gnu.org/licenses/gpl-2.0.html).
