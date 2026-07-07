=== strychni0x Media Bridge for Nextcloud & ownCloud ===
Contributors: strychni0x
Tags: media, nextcloud, owncloud, webdav, media-library
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Browse images on Nextcloud or ownCloud servers from the WordPress media modal and import them into the media library. Administrators only.

== Description ==

strychni0x Media Bridge adds a "Cloud" tab to the WordPress media modal
(the "Add Media" dialog). From there an administrator can browse the folders of
one or more configured cloud accounts, preview the file names and import images
into the WordPress media library with a single click.

The plugin works with WebDAV-based clouds of the Nextcloud family:

* Nextcloud
* ownCloud

You can connect several accounts of mixed types at the same time and switch
between them with a dropdown in the media dialog.

Imported images become regular WordPress attachments (a copy is downloaded from
the cloud server), so they work with every theme, block and page builder without
any further integration.

= Features =

* Adds a dedicated "Cloud" tab to the media modal.
* Adds an "Import from cloud" button to the Media Library and the
  "Add New Media File" screen, opening the browser in its own dialog.
* Connect multiple Nextcloud / ownCloud accounts at once and pick
  which one to browse from a dropdown.
* Browse cloud folders via WebDAV.
* Thumbnail previews with a per-account selectable source: use the server's
  native preview/thumbnail endpoint (default), or have WordPress generate and
  cache the thumbnails itself (useful when the server has preview generation
  disabled – and a reliable fallback for servers whose preview API differs).
* Paginated image listing for folders with many photos.
* Import images as standard WordPress attachments – one at a time or several at
  once via multi-select.
* Access restricted to administrators (the `manage_options` capability).
* Per-account connection test on the settings screen.

= Access control =

Every part of the plugin – the settings screen, the REST endpoints and the
JavaScript that renders the "Cloud" tab – requires the `manage_options`
capability. Non-administrators never receive the script and cannot call the
REST endpoints. The required capability can be changed with the
`ncmb_required_capability` filter.

== External services ==

This plugin connects to the Nextcloud or ownCloud servers that **you** configure
on the plugin settings screen. It is not a third-party hosted service operated by
the plugin author; you point it at your own (or your organisation's) cloud
instances.

What is sent, and when:

* When an administrator opens the "Cloud" tab or clicks the connection test,
  the plugin sends a WebDAV `PROPFIND` request to the configured server URL
  to list folder contents. The request includes the configured username and
  app password as an HTTP Basic authentication header.
* When the "Cloud" tab shows images, the plugin requests a thumbnail for each
  image from the server's native preview/thumbnail endpoint (Nextcloud:
  `/index.php/core/preview`; ownCloud: the files thumbnail API – both
  authenticated) and proxies it to the browser.
  If that returns no preview, or if the account is set to "Generate in
  WordPress", the plugin downloads the image via WebDAV once, generates a
  thumbnail on the WordPress server and caches it.
* When an administrator imports an image, the plugin sends a WebDAV `GET`
  request (again authenticated) to download that single file.

No data is sent anywhere other than the server URLs you configure. All requests
originate from your WordPress server, not from the visitor's browser.

The data handling of each cloud instance is governed by its own operator. Please
refer to the documentation and privacy policy of your provider:

* Nextcloud: https://nextcloud.com/ – Privacy: https://nextcloud.com/privacy/
* ownCloud: https://owncloud.com/ – Privacy: https://owncloud.com/privacy-policy/

== Installation ==

1. Upload the `strychni0x-media-bridge-for-nextcloud` folder to `/wp-content/plugins/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. In your cloud (Nextcloud/ownCloud) go to Settings → Security →
   "Create new app password" and copy the generated password.
4. In WordPress go to Settings → Cloud Media, choose the cloud type and enter the
   server URL, the username, the app password and (optionally) a start folder.
   Click "Check connection" to verify. Use "Add cloud" to connect more accounts.
5. Open the "Add Media" dialog in the editor or media library; a new "Cloud" tab
   is now available (with a dropdown to pick the account when you have several).

== Frequently Asked Questions ==

= Which cloud servers are supported? =

Nextcloud and ownCloud – WebDAV-based servers of the Nextcloud family that
support app passwords. You can connect several accounts of mixed types at the
same time and switch between them in the media dialog.

= Are the images copied or referenced? =

Copied. When you import an image it is downloaded from the cloud server and
stored as a regular WordPress attachment. There is no live link back to the
cloud after the import.

= The thumbnails stay grey – what can I do? =

Some servers do not generate image previews, and preview endpoints differ
between Nextcloud and ownCloud. In that case the default "from the
cloud server" mode has nothing to show. Go to Settings → Cloud Media and switch
that account's "Thumbnails" option to "Generate in WordPress"; WordPress will
then download each image once, build a cached thumbnail and display it. This
mode works with every supported server.

= Who can use the Cloud tab? =

Only administrators (users with the `manage_options` capability). You can change
the required capability with the `ncmb_required_capability` filter.

= Is my cloud password safe? =

Use an app password (Settings → Security in your cloud), never your real login
password, so you can revoke it at any time. The credentials are used only on the
server to authenticate WebDAV requests and are never exposed to the browser.

The app password is stored encrypted in the database (libsodium, or OpenSSL with
an HMAC as a fallback). The encryption key is derived from your WordPress salts
by default. For stronger separation you may define your own key in wp-config.php:

`define( 'NCMB_ENCRYPTION_KEY', 'a-long-random-string' );`

Note: if you change that constant (or your WordPress salts) later, the stored
password can no longer be decrypted and must be re-entered on the settings
screen.

== Screenshots ==

1. The settings screen (Settings → Cloud Media): per-account cloud type, server URL, username, app password, start folder and the thumbnail source option, with "Add cloud" for multiple accounts.
2. The "Cloud" tab in the media dialog: pick an account, browse folders, see thumbnails, select images (Select page / Select whole folder) and import.
3. The "Import from cloud" button on the Media Library screen, opening the importer in its own dialog.

== Changelog ==

= 2.0.0 =
* Added support for ownCloud in addition to Nextcloud (both WebDAV based, using
  an app password). Choose the cloud type per account.
* You can now connect multiple cloud accounts at once and switch between them
  from a dropdown in the media dialog.
* Thumbnail source ("from the cloud server" vs. "generate in WordPress") is now
  a per-account setting; provider-specific native preview endpoints are used
  (Nextcloud preview, ownCloud thumbnail API).
* Settings screen redesigned as a repeatable list of accounts with a per-account
  connection test.
* Existing single-account (Nextcloud) configurations are migrated automatically –
  no action required.

= 1.0.0 =
* First public release.

= 0.9.0 =
* Added "Select whole folder": selects every image in the current folder across
  all pages in one click, ready to import via "Import selection".

= 0.8.0 =
* Multi-select now persists across pages: ticked images stay selected when you
  page through a folder, and "Import selection" imports them all. "Select page"
  adds the current page; "Clear selection" clears everything.

= 0.7.0 =
* Added an "Import from Nextcloud" button to the Media Library grid and the
  "Add New Media File" screen. It opens the Nextcloud browser in a standalone
  dialog, so you can import without going through a post or the block editor.

= 0.6.0 =
* Added multi-select: tick several images and import them all at once with a
  per-image progress indicator. Imports run sequentially to avoid timeouts on
  large photos.

= 0.5.3 =
* Plugin Check compliance: updated "Tested up to" and removed the unused
  "Domain Path" header.

= 0.5.2 =
* Fixed a fatal error (HTTP 500) when generating thumbnails in WordPress: the
  required wp-admin file was not loaded in the REST context.
* Raised the memory limit before processing images and wrapped image handling in
  error handling, so importing/generating from large photos fails with a clear
  message instead of a blank 500 where possible.

= 0.5.1 =
* Added a setting to choose the thumbnail source: "from Nextcloud" (default,
  uses the preview endpoint) or "generate in WordPress". The download/resize
  fallback now only runs when explicitly selected.

= 0.5.0 =
* Thumbnails now have a fallback: when the Nextcloud server provides no preview,
  the plugin downloads the image once, creates a thumbnail in WordPress and
  caches it (with an unguessable filename and a configurable size limit via the
  `ncmb_max_thumb_bytes` filter). The cache is removed on uninstall.

= 0.4.1 =
* Fixed: the Nextcloud tab is now registered as a top router tab (next to
  "Media Library") instead of a left-hand menu item, so it shows up in every
  media modal – including the Featured Image dialog, which has no left menu.
* Improved post-import behaviour so the imported image is selected in dialogs
  that have no dedicated insert state (e.g. Featured Image).

= 0.4.0 =
* Added pagination to the Nextcloud tab so folders with many images load in
  pages instead of all at once. The full folder listing is cached briefly on the
  server so paging does not trigger repeated requests to Nextcloud.

= 0.3.0 =
* The Nextcloud app password is now stored encrypted at rest (libsodium, with an
  OpenSSL+HMAC fallback). Existing plaintext passwords are read transparently and
  re-encrypted on the next save. Optional `NCMB_ENCRYPTION_KEY` constant for a
  dedicated key.

= 0.2.0 =
* Added thumbnail previews in the Nextcloud tab, proxied from the Nextcloud
  preview endpoint so credentials stay on the server. Graceful fallback when no
  preview is available.

= 0.1.0 =
* Initial release: Nextcloud tab in the media modal, folder browsing via WebDAV,
  image import into the media library, administrator-only access.

== Upgrade Notice ==

= 2.0.0 =
Adds ownCloud support and multiple accounts at once. Your existing Nextcloud account is migrated automatically.

= 1.0.0 =
First public release.

= 0.9.0 =
Adds a "Select whole folder" action for importing an entire folder at once.

= 0.8.0 =
Multi-select now works across pagination pages.

= 0.7.0 =
Adds an "Import from Nextcloud" button on the Media Library screens.

= 0.6.0 =
You can now select and import multiple images at once.

= 0.5.2 =
Fixes a 500 error when generating thumbnails in WordPress and hardens importing.

= 0.5.1 =
You can now choose the thumbnail source in the settings (default: from Nextcloud).

= 0.5.0 =
Thumbnails now also work when your Nextcloud server has no preview generation.

= 0.4.1 =
Fixes the Nextcloud tab not appearing in the Featured Image dialog.

= 0.4.0 =
Adds pagination for folders with many images.

= 0.3.0 =
The Nextcloud app password is now encrypted at rest. No action required.

= 0.2.0 =
Adds thumbnail previews in the Nextcloud tab.

= 0.1.0 =
Initial release.
