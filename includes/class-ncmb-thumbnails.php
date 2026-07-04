<?php
/**
 * Thumbnail generation with a fallback.
 *
 * Strategy:
 *   1. Cached thumbnail present? -> serve it.
 *   2. Try the Nextcloud preview endpoint (cheap, no download).
 *   3. Otherwise: download the image via WebDAV, resize it in WordPress, cache it.
 *
 * This way thumbnails work even when the Nextcloud server does not generate
 * previews.
 *
 * @package NextcloudMediaBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCMB_Thumbnails {

	/**
	 * Maximum source file size (bytes) up to which the image is downloaded for
	 * fallback generation. Filterable via 'ncmb_max_thumb_bytes'.
	 */
	public static function max_source_bytes() {
		$default = 20 * MB_IN_BYTES;
		return (int) apply_filters( 'ncmb_max_thumb_bytes', $default );
	}

	/**
	 * Returns a thumbnail, either as a file or as raw bytes.
	 *
	 * @param int    $file_id   Nextcloud file ID (for the preview endpoint).
	 * @param string $path      Path relative to the user root (for the download fallback).
	 * @param int    $size      Edge length in pixels.
	 * @param int    $max_bytes Known source file size (0 = unknown).
	 * @return array{file?:string,body?:string,content_type:string}|WP_Error
	 */
	public static function get( $file_id, $path, $size, $max_bytes = 0 ) {
		$size = max( 32, min( 1024, (int) $size ) );

		$settings = NCMB_Settings::get();
		$mode     = isset( $settings['thumb_mode'] ) ? $settings['thumb_mode'] : 'nextcloud';

		if ( 'generate' === $mode ) {
			return self::generate( $file_id, $path, $size, $max_bytes );
		}

		return self::from_nextcloud( $file_id, $size );
	}

	/**
	 * Mode "from Nextcloud": the preview endpoint only.
	 *
	 * @return array{body:string,content_type:string}|WP_Error
	 */
	private static function from_nextcloud( $file_id, $size ) {
		$webdav  = new NCMB_WebDAV();
		$preview = $webdav->fetch_thumbnail( $file_id, $size );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		if ( '' === $preview['body'] || 0 !== strpos( (string) $preview['content_type'], 'image/' ) ) {
			return new WP_Error( 'ncmb_thumb', __( 'No preview available.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 404 ) );
		}
		return array(
			'body'         => $preview['body'],
			'content_type' => $preview['content_type'],
		);
	}

	/**
	 * Mode "generate in WordPress": download, resize, cache.
	 *
	 * @return array{file:string,content_type:string}|WP_Error
	 */
	private static function generate( $file_id, $path, $size, $max_bytes ) {
		$cache_file = self::cache_path( $file_id, $size );
		if ( file_exists( $cache_file ) ) {
			return array(
				'file'         => $cache_file,
				'content_type' => 'image/jpeg',
			);
		}

		$limit = self::max_source_bytes();
		if ( $max_bytes > 0 && $max_bytes > $limit ) {
			return new WP_Error(
				'ncmb_thumb_too_large',
				__( 'Image too large for thumbnail generation.', 'strychni0x-media-bridge-for-nextcloud' ),
				array( 'status' => 415 )
			);
		}

		$webdav = new NCMB_WebDAV();
		$dl     = $webdav->download_to_temp( $path );
		if ( is_wp_error( $dl ) ) {
			return $dl;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Processing large photos is memory intensive.
		wp_raise_memory_limit( 'image' );

		try {
			$editor = wp_get_image_editor( $dl['tmp'] );
			if ( is_wp_error( $editor ) ) {
				wp_delete_file( $dl['tmp'] );
				return $editor;
			}

			$editor->set_quality( 82 );
			$editor->resize( $size, $size, false ); // Keep aspect ratio.

			self::ensure_cache_dir();
			$saved = $editor->save( $cache_file, 'image/jpeg' );
		} catch ( \Throwable $e ) {
			wp_delete_file( $dl['tmp'] );
			return new WP_Error(
				'ncmb_thumb_failed',
				sprintf( /* translators: %s: error message */ __( 'Thumbnail generation failed: %s', 'strychni0x-media-bridge-for-nextcloud' ), $e->getMessage() ),
				array( 'status' => 500 )
			);
		}

		wp_delete_file( $dl['tmp'] );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'file'         => $saved['path'],
			'content_type' => 'image/jpeg',
		);
	}

	/**
	 * Cache directory inside the uploads folder.
	 */
	private static function cache_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'ncmb-cache';
	}

	/**
	 * Unguessable cache filename (HMAC with the site salt) so thumbnails of
	 * private photos cannot be fetched via guessed URLs.
	 */
	private static function cache_path( $file_id, $size ) {
		$name = hash_hmac( 'sha256', (int) $file_id . '|' . (int) $size, wp_salt( 'nonce' ) );
		return trailingslashit( self::cache_dir() ) . $name . '.jpg';
	}

	/**
	 * Creates the cache directory and protects it against direct listing/access.
	 */
	private static function ensure_cache_dir() {
		$dir = self::cache_dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- static protection stub, written once.
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "Order allow,deny\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- static protection rules, written once.
			@file_put_contents( $htaccess, $rules );
		}
	}

	/**
	 * Removes the entire cache directory (used on uninstall).
	 */
	public static function clear_cache() {
		$dir = self::cache_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( trailingslashit( $dir ) . '*' );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- cleaning up our own cache directory.
	}
}
