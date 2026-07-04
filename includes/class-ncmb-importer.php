<?php
/**
 * Imports a Nextcloud file into the WordPress media library (variant A: copy).
 *
 * @package NextcloudMediaBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCMB_Importer {

	/**
	 * Downloads a file from Nextcloud and creates an attachment.
	 *
	 * @param string $path Path relative to the user root in Nextcloud.
	 * @return array|WP_Error Attachment data on success.
	 */
	public function import( $path ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$webdav = new NCMB_WebDAV();
		$result = $webdav->download_to_temp( $path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$filename = basename( $path );

		// Allow image file types only.
		$check = wp_check_filetype( $filename );
		if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) ) {
			wp_delete_file( $result['tmp'] );
			return new WP_Error( 'ncmb_filetype', __( 'Only image files can be imported.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 415 ) );
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $result['tmp'],
		);

		// Processing large photos is memory intensive.
		wp_raise_memory_limit( 'image' );

		// media_handle_sideload handles moving the file, the attachment and metadata/thumbnails.
		try {
			$attachment_id = media_handle_sideload( $file_array, 0 );
		} catch ( \Throwable $e ) {
			wp_delete_file( $result['tmp'] );
			return new WP_Error(
				'ncmb_import_failed',
				sprintf( /* translators: %s: error message */ __( 'Import failed: %s', 'strychni0x-media-bridge-for-nextcloud' ), $e->getMessage() ),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $result['tmp'] );
			return $attachment_id;
		}

		// Record the origin as metadata (duplicate detection / reference).
		update_post_meta( $attachment_id, '_ncmb_source_path', $path );
		update_post_meta( $attachment_id, '_ncmb_imported_at', current_time( 'mysql' ) );

		return array(
			'id'    => $attachment_id,
			'url'   => wp_get_attachment_url( $attachment_id ),
			'thumb' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'title' => get_the_title( $attachment_id ),
		);
	}
}
