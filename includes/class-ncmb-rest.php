<?php
/**
 * REST endpoints. All endpoints are restricted to administrators.
 *
 * @package NextcloudMediaBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCMB_REST {

	const NS = 'ncmb/v1';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Central permission check: administrators only (manage_options).
	 */
	public function permission_check() {
		if ( ! current_user_can( ncmb_required_capability() ) ) {
			return new WP_Error( 'ncmb_forbidden', __( 'Access for administrators only.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/list',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'path'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( $this, 'sanitize_path' ),
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 40,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/paths',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_paths' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'path' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( $this, 'sanitize_path' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/thumb',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_thumb' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'file_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'path'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => array( $this, 'sanitize_path' ),
					),
					'size'    => array(
						'type'              => 'integer',
						'default'           => 256,
						'sanitize_callback' => 'absint',
					),
					'bytes'   => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_import' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'path' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( $this, 'sanitize_path' ),
					),
				),
			)
		);
	}

	/**
	 * Prevents directory traversal and normalizes the path.
	 */
	public function sanitize_path( $value ) {
		$value = (string) $value;
		$value = str_replace( array( '..', "\0" ), '', $value );
		$value = '/' . ltrim( $value, '/' );
		return $value;
	}

	/**
	 * Fetches the full folder listing (briefly cached) so that paging and
	 * "whole folder" do not trigger another PROPFIND.
	 *
	 * @param string $path Path relative to the user root.
	 * @return array|WP_Error
	 */
	private function get_full_listing( $path ) {
		$cache_key = 'ncmb_list_' . md5( $path );
		$full      = get_transient( $cache_key );
		if ( false === $full ) {
			$webdav = new NCMB_WebDAV();
			$full   = $webdav->list_directory( $path );
			if ( is_wp_error( $full ) ) {
				return $full;
			}
			set_transient( $cache_key, $full, 30 );
		}
		return $full;
	}

	public function handle_list( WP_REST_Request $request ) {
		$settings = NCMB_Settings::get();
		$path     = $request->get_param( 'path' );
		if ( '' === $path || '/' === $path ) {
			$path = $settings['root_path'];
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page < 1 ? 40 : min( 100, $per_page );

		$full = $this->get_full_listing( $path );
		if ( is_wp_error( $full ) ) {
			return $full;
		}

		$dirs   = array();
		$images = array();
		foreach ( $full['entries'] as $entry ) {
			if ( $entry['is_dir'] ) {
				$dirs[] = $entry;
			} elseif ( $entry['is_image'] ) {
				$images[] = $entry;
			}
		}

		$total       = count( $images );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( $page, $total_pages );
		$page_images = array_slice( $images, ( $page - 1 ) * $per_page, $per_page );

		return rest_ensure_response(
			array(
				'path'       => $full['path'],
				'root_path'  => $settings['root_path'],
				'dirs'       => array_values( $dirs ),
				'images'     => array_values( $page_images ),
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total'       => $total,
					'total_pages' => $total_pages,
				),
			)
		);
	}

	/**
	 * Returns all image paths of a folder (for "select whole folder").
	 */
	public function handle_paths( WP_REST_Request $request ) {
		$settings = NCMB_Settings::get();
		$path     = $request->get_param( 'path' );
		if ( '' === $path || '/' === $path ) {
			$path = $settings['root_path'];
		}

		$full = $this->get_full_listing( $path );
		if ( is_wp_error( $full ) ) {
			return $full;
		}

		$paths = array();
		foreach ( $full['entries'] as $entry ) {
			if ( empty( $entry['is_dir'] ) && ! empty( $entry['is_image'] ) ) {
				$paths[] = $entry['path'];
			}
		}

		return rest_ensure_response(
			array(
				'path'  => $full['path'],
				'paths' => $paths,
				'total' => count( $paths ),
			)
		);
	}

	/**
	 * Streams a thumbnail from Nextcloud straight to the browser.
	 * Outputs binary image data and ends the request (no JSON).
	 */
	public function handle_thumb( WP_REST_Request $request ) {
		$file_id = (int) $request->get_param( 'file_id' );
		$path    = $request->get_param( 'path' );
		$size    = (int) $request->get_param( 'size' );
		$bytes   = (int) $request->get_param( 'bytes' );

		if ( $file_id <= 0 ) {
			return new WP_Error( 'ncmb_bad_id', __( 'Invalid file ID.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 400 ) );
		}

		$thumb = NCMB_Thumbnails::get( $file_id, $path, $size > 0 ? $size : 256, $bytes );
		if ( is_wp_error( $thumb ) ) {
			return $thumb;
		}

		if ( isset( $thumb['file'] ) ) {
			$body = file_get_contents( $thumb['file'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local cache file.
		} else {
			$body = $thumb['body'];
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: ' . $thumb['content_type'] );
			header( 'Content-Length: ' . strlen( $body ) );
			header( 'Cache-Control: private, max-age=86400' );
			header( 'X-Content-Type-Options: nosniff' );
		}

		// Binary image data – escaping is not applicable here.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $body;
		exit;
	}

	public function handle_import( WP_REST_Request $request ) {
		$path     = $request->get_param( 'path' );
		$importer = new NCMB_Importer();
		$result   = $importer->import( $path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}
}
