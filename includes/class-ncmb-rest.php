<?php
/**
 * REST endpoints. All endpoints are restricted to administrators.
 *
 * Every data endpoint is scoped to a specific account via the `account`
 * parameter (an account id). When omitted, the first configured account is used.
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
		$account_arg = array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		);

		register_rest_route(
			self::NS,
			'/accounts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_accounts' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::NS,
			'/list',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_list' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'account'  => $account_arg,
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
					'account' => $account_arg,
					'path'    => array(
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
					'account' => $account_arg,
					'file_id' => array(
						'type'              => 'integer',
						'default'           => 0,
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
					'account' => $account_arg,
					'path'    => array(
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
	 * Resolves the account for a request, or a WP_Error when it does not exist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function resolve_account( WP_REST_Request $request ) {
		$id      = (string) $request->get_param( 'account' );
		$account = NCMB_Settings::get_account( $id );
		if ( null === $account ) {
			return new WP_Error( 'ncmb_no_account', __( 'The selected cloud account does not exist.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 404 ) );
		}
		return $account;
	}

	/**
	 * Lists the configured accounts (no secrets). Used by the settings test and
	 * as a fallback source for the media browser.
	 */
	public function handle_accounts() {
		$out = array();
		foreach ( NCMB_Settings::get_accounts() as $account ) {
			$out[] = array(
				'id'        => $account['id'],
				'label'     => '' !== $account['label'] ? $account['label'] : NCMB_Providers::label( $account['provider'] ),
				'provider'  => $account['provider'],
				'root_path' => $account['root_path'],
			);
		}
		return rest_ensure_response( array( 'accounts' => $out ) );
	}

	/**
	 * Fetches the full folder listing (briefly cached) so that paging and
	 * "whole folder" do not trigger another PROPFIND. Cached per account + path.
	 *
	 * @param array  $account The account.
	 * @param string $path    Path relative to the user root.
	 * @return array|WP_Error
	 */
	private function get_full_listing( array $account, $path ) {
		$cache_key = 'ncmb_list_' . md5( $account['id'] . '|' . $path );
		$full      = get_transient( $cache_key );
		if ( false === $full ) {
			$webdav = new NCMB_WebDAV( $account );
			$full   = $webdav->list_directory( $path );
			if ( is_wp_error( $full ) ) {
				return $full;
			}
			set_transient( $cache_key, $full, 30 );
		}
		return $full;
	}

	public function handle_list( WP_REST_Request $request ) {
		$account = $this->resolve_account( $request );
		if ( is_wp_error( $account ) ) {
			return $account;
		}

		$path = $request->get_param( 'path' );
		if ( '' === $path || '/' === $path ) {
			$path = $account['root_path'];
		}

		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page < 1 ? 40 : min( 100, $per_page );

		$full = $this->get_full_listing( $account, $path );
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
				'account'    => $account['id'],
				'path'       => $full['path'],
				'root_path'  => $account['root_path'],
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
		$account = $this->resolve_account( $request );
		if ( is_wp_error( $account ) ) {
			return $account;
		}

		$path = $request->get_param( 'path' );
		if ( '' === $path || '/' === $path ) {
			$path = $account['root_path'];
		}

		$full = $this->get_full_listing( $account, $path );
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
				'account' => $account['id'],
				'path'    => $full['path'],
				'paths'   => $paths,
				'total'   => count( $paths ),
			)
		);
	}

	/**
	 * Streams a thumbnail from the cloud server straight to the browser.
	 * Outputs binary image data and ends the request (no JSON).
	 */
	public function handle_thumb( WP_REST_Request $request ) {
		$account = $this->resolve_account( $request );
		if ( is_wp_error( $account ) ) {
			return $account;
		}

		$file_id = (int) $request->get_param( 'file_id' );
		$path    = $request->get_param( 'path' );
		$size    = (int) $request->get_param( 'size' );
		$bytes   = (int) $request->get_param( 'bytes' );

		if ( $file_id <= 0 && ( '' === $path || '/' === $path ) ) {
			return new WP_Error( 'ncmb_bad_id', __( 'Invalid image reference.', 'strychni0x-media-bridge-for-nextcloud' ), array( 'status' => 400 ) );
		}

		$thumb = NCMB_Thumbnails::get( $account, $file_id, $path, $size > 0 ? $size : 256, $bytes );
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
		$account = $this->resolve_account( $request );
		if ( is_wp_error( $account ) ) {
			return $account;
		}

		$path     = $request->get_param( 'path' );
		$importer = new NCMB_Importer();
		$result   = $importer->import( $account, $path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}
}
