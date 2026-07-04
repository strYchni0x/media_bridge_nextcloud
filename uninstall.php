<?php
/**
 * Uninstall cleanup: removes the stored settings.
 *
 * @package NextcloudMediaBridge
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ncmb_settings' );
delete_transient( 'ncmb_no_previews' );

// Remove the thumbnail cache.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-ncmb-thumbnails.php';
NCMB_Thumbnails::clear_cache();
