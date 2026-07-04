<?php
/**
 * Plugin Name:       strychni0x Media Bridge for Nextcloud
 * Description:        Browse photos stored in a Nextcloud from the WordPress media library and import them as media. Administrators only.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Florian Willnat
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       strychni0x-media-bridge-for-nextcloud
 *
 * @package NextcloudMediaBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NCMB_VERSION', '1.0.0' );
define( 'NCMB_FILE', __FILE__ );
define( 'NCMB_DIR', plugin_dir_path( __FILE__ ) );
define( 'NCMB_URL', plugin_dir_url( __FILE__ ) );

/**
 * Capability required for every access point (settings, REST, media tab).
 * Filterable via 'ncmb_required_capability'.
 */
function ncmb_required_capability() {
	return apply_filters( 'ncmb_required_capability', 'manage_options' );
}

require_once NCMB_DIR . 'includes/class-ncmb-crypto.php';
require_once NCMB_DIR . 'includes/class-ncmb-settings.php';
require_once NCMB_DIR . 'includes/class-ncmb-webdav.php';
require_once NCMB_DIR . 'includes/class-ncmb-thumbnails.php';
require_once NCMB_DIR . 'includes/class-ncmb-importer.php';
require_once NCMB_DIR . 'includes/class-ncmb-rest.php';
require_once NCMB_DIR . 'includes/class-ncmb-media.php';

/**
 * Bootstrap.
 */
function ncmb_init() {
	NCMB_Settings::instance();
	NCMB_REST::instance();
	NCMB_Media::instance();
}
add_action( 'plugins_loaded', 'ncmb_init' );
