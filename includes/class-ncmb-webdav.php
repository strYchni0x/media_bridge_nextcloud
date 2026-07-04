<?php
/**
 * Minimal WebDAV client for Nextcloud (PROPFIND to list, GET to download).
 *
 * @package NextcloudMediaBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCMB_WebDAV {

	/** @var array */
	private $settings;

	public function __construct() {
		$this->settings = NCMB_Settings::get();
	}

	public function is_configured() {
		return '' !== $this->settings['base_url']
			&& '' !== $this->settings['username']
			&& '' !== $this->settings['app_password'];
	}

	/**
	 * Base URL of the WebDAV endpoint for the configured user.
	 */
	private function dav_base() {
		return $this->settings['base_url'] . '/remote.php/dav/files/' . rawurlencode( $this->settings['username'] );
	}

	private function auth_header() {
		// base64 here is not obfuscation but the encoding required by HTTP Basic Auth.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'Basic ' . base64_encode( $this->settings['username'] . ':' . $this->settings['app_password'] );
	}

	/**
	 * Builds the full URL for a path relative to the user root.
	 * Each path segment is URL-encoded individually.
	 */
	private function build_url( $path ) {
		$path     = '/' . ltrim( (string) $path, '/' );
		$segments = array_map( 'rawurlencode', array_filter( explode( '/', $path ), 'strlen' ) );
		return $this->dav_base() . '/' . implode( '/', $segments );
	}

	/**
	 * Lists a folder (Depth: 1) and returns an array of entries.
	 *
	 * @param string $path Path relative to the user root.
	 * @return array|WP_Error
	 */
	public function list_directory( $path ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'ncmb_not_configured', __( 'Nextcloud is not fully configured.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 400 ) );
		}

		$body = '<?xml version="1.0" encoding="utf-8"?>'
			. '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns">'
			. '<d:prop>'
			. '<d:displayname/><d:getcontenttype/><d:getcontentlength/><d:getlastmodified/><d:resourcetype/><oc:fileid/>'
			. '</d:prop></d:propfind>';

		$response = wp_remote_request(
			$this->build_url( $path ),
			array(
				'method'  => 'PROPFIND',
				'timeout' => 20,
				'headers' => array(
					'Authorization' => $this->auth_header(),
					'Depth'         => '1',
					'Content-Type'  => 'application/xml; charset=utf-8',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 401 === $code ) {
			return new WP_Error( 'ncmb_auth', __( 'Authentication failed. Please check the username and app password.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 401 ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'ncmb_http', sprintf( /* translators: %d: HTTP status code */ __( 'Nextcloud responded with status %d.', 'strychni0x-media-bridge-for-nextcloud' ), $code ), array( 'status' => 502 ) );
		}

		return $this->parse_propfind( wp_remote_retrieve_body( $response ), $path );
	}

	/**
	 * Parses the multistatus XML response.
	 *
	 * @return array{path:string,entries:array}|WP_Error
	 */
	private function parse_propfind( $xml_string, $request_path ) {
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $xml_string );
		libxml_use_internal_errors( $prev );

		if ( false === $xml ) {
			return new WP_Error( 'ncmb_parse', __( 'The response from Nextcloud could not be parsed.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 502 ) );
		}

		$xml->registerXPathNamespace( 'd', 'DAV:' );
		$responses     = $xml->xpath( '//d:response' );
		$entries       = array();
		$dav_base_path = wp_parse_url( $this->dav_base(), PHP_URL_PATH );

		foreach ( (array) $responses as $resp ) {
			$resp->registerXPathNamespace( 'd', 'DAV:' );
			$resp->registerXPathNamespace( 'oc', 'http://owncloud.org/ns' );

			$href_nodes = $resp->xpath( 'd:href' );
			if ( empty( $href_nodes ) ) {
				continue;
			}
			$href = (string) $href_nodes[0];

			// Derive the path relative to the user root.
			$rel = rawurldecode( $href );
			if ( $dav_base_path && 0 === strpos( $rel, $dav_base_path ) ) {
				$rel = substr( $rel, strlen( $dav_base_path ) );
			}
			$rel = '/' . ltrim( $rel, '/' );

			// Skip the requested folder itself.
			if ( untrailingslashit( $rel ) === untrailingslashit( '/' . ltrim( $request_path, '/' ) ) ) {
				continue;
			}

			$is_dir = ! empty( $resp->xpath( 'd:propstat/d:prop/d:resourcetype/d:collection' ) );

			$ct_nodes   = $resp->xpath( 'd:propstat/d:prop/d:getcontenttype' );
			$len_nodes  = $resp->xpath( 'd:propstat/d:prop/d:getcontentlength' );
			$mod_nodes  = $resp->xpath( 'd:propstat/d:prop/d:getlastmodified' );
			$name_nodes = $resp->xpath( 'd:propstat/d:prop/d:displayname' );
			$fid_nodes  = $resp->xpath( 'd:propstat/d:prop/oc:fileid' );

			$content_type = isset( $ct_nodes[0] ) ? (string) $ct_nodes[0] : '';
			$name         = isset( $name_nodes[0] ) && '' !== (string) $name_nodes[0]
				? (string) $name_nodes[0]
				: rawurldecode( basename( untrailingslashit( $href ) ) );

			$entries[] = array(
				'name'         => $name,
				'path'         => untrailingslashit( $rel ),
				'is_dir'       => $is_dir,
				'content_type' => $content_type,
				'size'         => isset( $len_nodes[0] ) ? (int) $len_nodes[0] : 0,
				'modified'     => isset( $mod_nodes[0] ) ? (string) $mod_nodes[0] : '',
				'file_id'      => isset( $fid_nodes[0] ) ? (int) $fid_nodes[0] : 0,
				'is_image'     => ( ! $is_dir && 0 === strpos( $content_type, 'image/' ) ),
			);
		}

		// Folders first, then alphabetical.
		usort(
			$entries,
			function ( $a, $b ) {
				if ( $a['is_dir'] !== $b['is_dir'] ) {
					return $a['is_dir'] ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'path'    => '/' . ltrim( $request_path, '/' ),
			'entries' => $entries,
		);
	}

	/**
	 * Downloads a file to a temporary local file and returns its path.
	 *
	 * @param string $path Path relative to the user root.
	 * @return array{tmp:string,content_type:string}|WP_Error
	 */
	public function download_to_temp( $path ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'ncmb_not_configured', __( 'Nextcloud is not configured.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 400 ) );
		}

		// wp_tempnam() lives in wp-admin/includes/file.php and is not guaranteed
		// to be loaded in the REST context.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam( basename( $path ) );
		if ( ! $tmp ) {
			return new WP_Error( 'ncmb_tmp', __( 'Could not create a temporary file.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 500 ) );
		}

		$response = wp_remote_get(
			$this->build_url( $path ),
			array(
				'timeout'  => 60,
				'stream'   => true,
				'filename' => $tmp,
				'headers'  => array( 'Authorization' => $this->auth_header() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'ncmb_download', sprintf( /* translators: %d: HTTP status code */ __( 'Download failed (status %d).', 'strychni0x-media-bridge-for-nextcloud' ), $code ), array( 'status' => 502 ) );
		}

		return array(
			'tmp'          => $tmp,
			'content_type' => wp_remote_retrieve_header( $response, 'content-type' ),
		);
	}

	/**
	 * Fetches a thumbnail via the Nextcloud preview endpoint.
	 *
	 * @param int $file_id Nextcloud file ID (oc:fileid).
	 * @param int $size    Edge length in pixels.
	 * @return array{body:string,content_type:string}|WP_Error
	 */
	public function fetch_thumbnail( $file_id, $size = 256 ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'ncmb_not_configured', __( 'Nextcloud is not configured.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 400 ) );
		}

		$file_id = (int) $file_id;
		$size    = max( 32, min( 1024, (int) $size ) );

		$url = add_query_arg(
			array(
				'fileId'    => $file_id,
				'x'         => $size,
				'y'         => $size,
				'a'         => 1, // Keep aspect ratio (no cropping).
				'forceIcon' => 0,
			),
			$this->settings['base_url'] . '/index.php/core/preview'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => $this->auth_header() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'ncmb_thumb', __( 'No preview available.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 404 ) );
		}

		$ct = wp_remote_retrieve_header( $response, 'content-type' );
		return array(
			'body'         => wp_remote_retrieve_body( $response ),
			'content_type' => $ct ? $ct : 'image/jpeg',
		);
	}
}
