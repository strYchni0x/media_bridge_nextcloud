<?php
/**
 * Enqueues the browser script in the media library / editor context — administrators only.
 *
 * @package NextcloudMediaBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCMB_Media {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( $hook ) {
		// Only administrators receive the script at all.
		if ( ! current_user_can( ncmb_required_capability() ) ) {
			return;
		}

		// Make sure the media views are available.
		wp_enqueue_media();

		wp_enqueue_style(
			'ncmb-media',
			NCMB_URL . 'assets/css/media.css',
			array(),
			NCMB_VERSION
		);

		wp_enqueue_script(
			'ncmb-media',
			NCMB_URL . 'assets/js/media-tab.js',
			array( 'jquery', 'media-views', 'wp-i18n' ),
			NCMB_VERSION,
			true
		);

		wp_localize_script(
			'ncmb-media',
			'NCMB',
			array(
				'restUrl' => esc_url_raw( rest_url( NCMB_REST::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'tabTitle'       => __( 'Nextcloud', 'strychni0x-media-bridge-for-nextcloud' ),
					'loading'        => __( 'Loading…', 'strychni0x-media-bridge-for-nextcloud' ),
					'importing'      => __( 'Importing…', 'strychni0x-media-bridge-for-nextcloud' ),
					'import'         => __( 'Import', 'strychni0x-media-bridge-for-nextcloud' ),
					'up'             => __( 'Up one level', 'strychni0x-media-bridge-for-nextcloud' ),
					'empty'          => __( 'No images in this folder.', 'strychni0x-media-bridge-for-nextcloud' ),
					'error'          => __( 'Error', 'strychni0x-media-bridge-for-nextcloud' ),
					'imported'       => __( 'Imported to the media library.', 'strychni0x-media-bridge-for-nextcloud' ),
					'prev'           => __( 'Previous', 'strychni0x-media-bridge-for-nextcloud' ),
					'next'           => __( 'Next', 'strychni0x-media-bridge-for-nextcloud' ),
					/* translators: %1$s: current page, %2$s: total pages. */
					'pageOf'         => __( 'Page %1$s of %2$s', 'strychni0x-media-bridge-for-nextcloud' ),
					'selectAll'      => __( 'Select page', 'strychni0x-media-bridge-for-nextcloud' ),
					'selectFolder'   => __( 'Select whole folder', 'strychni0x-media-bridge-for-nextcloud' ),
					'selectNone'     => __( 'Clear selection', 'strychni0x-media-bridge-for-nextcloud' ),
					/* translators: %s: number of selected images. */
					'importSelected' => __( 'Import selection (%s)', 'strychni0x-media-bridge-for-nextcloud' ),
					/* translators: %1$s: current item, %2$s: total items. */
					'batchProgress'  => __( 'Importing %1$s of %2$s…', 'strychni0x-media-bridge-for-nextcloud' ),
					/* translators: %1$s: succeeded count, %2$s: failed count. */
					'batchDone'      => __( '%1$s imported, %2$s failed.', 'strychni0x-media-bridge-for-nextcloud' ),
					'openImporter'   => __( 'Import from Nextcloud', 'strychni0x-media-bridge-for-nextcloud' ),
					'importerTitle'  => __( 'Import from Nextcloud', 'strychni0x-media-bridge-for-nextcloud' ),
					'close'          => __( 'Close', 'strychni0x-media-bridge-for-nextcloud' ),
				),
			)
		);
	}
}
