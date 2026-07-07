<?php
/**
 * Supported cloud providers.
 *
 * Nextcloud and ownCloud both speak WebDAV, so folder listing and download are
 * identical across them. Only two things differ per provider:
 *
 *   1. The WebDAV base path (the "files" endpoint for the user).
 *   2. How a native thumbnail/preview is requested.
 *
 * Everything else (the "generate in WordPress" thumbnail fallback, importing,
 * pagination) is provider-agnostic and works everywhere.
 *
 * @package NextcloudMediaBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCMB_Providers {

	const DEFAULT_ID = 'nextcloud';

	/**
	 * Returns the definitions of all supported providers.
	 *
	 * dav_path uses a single "%s" placeholder for the (raw) username.
	 *
	 * thumb_strategy determines how {@see NCMB_WebDAV::fetch_thumbnail()} builds
	 * the native preview request:
	 *   - 'preview_fileid'       Nextcloud: /index.php/core/preview?fileId=…
	 *   - 'files_thumbnail_path' ownCloud:  /index.php/apps/files/api/v1/thumbnail/x/y/path
	 *
	 * @return array<string,array{label:string,dav_path:string,thumb_strategy:string,thumb_hint:string}>
	 */
	public static function all() {
		return array(
			'nextcloud' => array(
				'label'          => 'Nextcloud',
				'dav_path'       => '/remote.php/dav/files/%s',
				'thumb_strategy' => 'preview_fileid',
				'thumb_hint'     => __( 'Uses the Nextcloud preview endpoint.', 'strychni0x-media-bridge-for-nextcloud' ),
			),
			'owncloud'  => array(
				'label'          => 'ownCloud',
				'dav_path'       => '/remote.php/dav/files/%s',
				'thumb_strategy' => 'files_thumbnail_path',
				'thumb_hint'     => __( 'Uses the ownCloud files thumbnail API.', 'strychni0x-media-bridge-for-nextcloud' ),
			),
		);
	}

	/**
	 * Whether the given id is a known provider.
	 *
	 * @param string $id Provider id.
	 * @return bool
	 */
	public static function is_valid( $id ) {
		return array_key_exists( (string) $id, self::all() );
	}

	/**
	 * Returns a single provider definition, falling back to the default.
	 *
	 * @param string $id Provider id.
	 * @return array{label:string,dav_path:string,thumb_strategy:string,thumb_hint:string}
	 */
	public static function get( $id ) {
		$all = self::all();
		$id  = (string) $id;
		return isset( $all[ $id ] ) ? $all[ $id ] : $all[ self::DEFAULT_ID ];
	}

	/**
	 * Human-readable label for a provider id.
	 *
	 * @param string $id Provider id.
	 * @return string
	 */
	public static function label( $id ) {
		$p = self::get( $id );
		return $p['label'];
	}
}
