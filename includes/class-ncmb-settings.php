<?php
/**
 * Settings page and storage of the Nextcloud credentials (single fixed account).
 *
 * @package NextcloudMediaBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NCMB_Settings {

	const OPTION = 'ncmb_settings';

	private static $instance = null;

	/**
	 * Hook suffix of the settings page, used to scope asset enqueuing.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Returns the stored settings.
	 *
	 * @return array{base_url:string,username:string,app_password:string,root_path:string,thumb_mode:string}
	 */
	public static function get() {
		$defaults = array(
			'base_url'     => '',
			'username'     => '',
			'app_password' => '',
			'root_path'    => '/', // Start folder relative to the user root in Nextcloud.
			'thumb_mode'   => 'nextcloud', // 'nextcloud' = preview endpoint, 'generate' = create in WP.
		);
		$opt = get_option( self::OPTION, array() );
		$opt = wp_parse_args( is_array( $opt ) ? $opt : array(), $defaults );

		// The app password is stored encrypted and transparently decrypted here.
		$opt['app_password'] = NCMB_Crypto::decrypt( $opt['app_password'] );

		return $opt;
	}

	public function add_menu() {
		$this->hook_suffix = add_options_page(
			__( 'strychni0x Media Bridge for Nextcloud', 'strychni0x-media-bridge-for-nextcloud' ),
			__( 'Nextcloud Media', 'strychni0x-media-bridge-for-nextcloud' ),
			ncmb_required_capability(),
			'ncmb-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues the connection-test script on the settings screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'ncmb-settings',
			NCMB_URL . 'assets/js/settings.js',
			array(),
			NCMB_VERSION,
			true
		);

		wp_localize_script(
			'ncmb-settings',
			'ncmbSettings',
			array(
				'restUrl' => esc_url_raw( rest_url( 'ncmb/v1/list' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'checking' => __( 'Checking…', 'strychni0x-media-bridge-for-nextcloud' ),
					'ok'       => __( 'OK – Connection successful.', 'strychni0x-media-bridge-for-nextcloud' ),
					'error'    => __( 'Error', 'strychni0x-media-bridge-for-nextcloud' ),
				),
			)
		);
	}

	public function register_settings() {
		register_setting(
			'ncmb_settings_group',
			self::OPTION,
			array( $this, 'sanitize' )
		);
	}

	/**
	 * Sanitizes the input. The app password is only overwritten when a new value
	 * was entered (otherwise the existing one is kept).
	 */
	public function sanitize( $input ) {
		$current = self::get();
		$out     = array();

		$out['base_url']  = isset( $input['base_url'] ) ? esc_url_raw( untrailingslashit( trim( $input['base_url'] ) ) ) : '';
		$out['username']  = isset( $input['username'] ) ? sanitize_text_field( $input['username'] ) : '';
		$out['root_path'] = isset( $input['root_path'] ) ? '/' . ltrim( sanitize_text_field( $input['root_path'] ), '/' ) : '/';

		$mode              = isset( $input['thumb_mode'] ) ? sanitize_key( $input['thumb_mode'] ) : 'nextcloud';
		$out['thumb_mode'] = in_array( $mode, array( 'nextcloud', 'generate' ), true ) ? $mode : 'nextcloud';

		$new_pw   = isset( $input['app_password'] ) ? trim( $input['app_password'] ) : '';
		$plain_pw = ( '' !== $new_pw ) ? $new_pw : $current['app_password']; // $current is already decrypted.

		// Always store encrypted.
		$out['app_password'] = NCMB_Crypto::encrypt( $plain_pw );

		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( ncmb_required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'strychni0x-media-bridge-for-nextcloud' ) );
		}

		$opt    = self::get();
		$has_pw = '' !== $opt['app_password'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'strychni0x Media Bridge for Nextcloud', 'strychni0x-media-bridge-for-nextcloud' ); ?></h1>

			<form action="options.php" method="post">
				<?php settings_fields( 'ncmb_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ncmb_base_url"><?php esc_html_e( 'Nextcloud URL', 'strychni0x-media-bridge-for-nextcloud' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[base_url]" id="ncmb_base_url" type="url" class="regular-text" placeholder="https://cloud.example.com" value="<?php echo esc_attr( $opt['base_url'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Base URL without a trailing slash.', 'strychni0x-media-bridge-for-nextcloud' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ncmb_username"><?php esc_html_e( 'Username', 'strychni0x-media-bridge-for-nextcloud' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION ); ?>[username]" id="ncmb_username" type="text" class="regular-text" value="<?php echo esc_attr( $opt['username'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ncmb_app_password"><?php esc_html_e( 'App password', 'strychni0x-media-bridge-for-nextcloud' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[app_password]" id="ncmb_app_password" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_pw ? '••••••••••••' : ''; ?>" value="" />
							<p class="description">
								<?php esc_html_e( 'In Nextcloud go to Settings → Security → "Create new app password". Do not use your normal login password.', 'strychni0x-media-bridge-for-nextcloud' ); ?>
								<?php if ( $has_pw ) { echo '<br><strong>' . esc_html__( 'A password is stored. Leave the field empty to keep it.', 'strychni0x-media-bridge-for-nextcloud' ) . '</strong>'; } ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ncmb_root_path"><?php esc_html_e( 'Start folder', 'strychni0x-media-bridge-for-nextcloud' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[root_path]" id="ncmb_root_path" type="text" class="regular-text" value="<?php echo esc_attr( $opt['root_path'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Path relative to the user root, e.g. /Photos. Default: /', 'strychni0x-media-bridge-for-nextcloud' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Thumbnails', 'strychni0x-media-bridge-for-nextcloud' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><span><?php esc_html_e( 'Thumbnails', 'strychni0x-media-bridge-for-nextcloud' ); ?></span></legend>
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[thumb_mode]" value="nextcloud" <?php checked( $opt['thumb_mode'], 'nextcloud' ); ?> />
									<?php esc_html_e( 'Thumbnails from Nextcloud', 'strychni0x-media-bridge-for-nextcloud' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( self::OPTION ); ?>[thumb_mode]" value="generate" <?php checked( $opt['thumb_mode'], 'generate' ); ?> />
									<?php esc_html_e( 'Generate in WordPress', 'strychni0x-media-bridge-for-nextcloud' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Default: from Nextcloud (uses its preview endpoint). If your Nextcloud does not generate previews, you can have them generated here in WordPress – this downloads each image once and stores a cached thumbnail.', 'strychni0x-media-bridge-for-nextcloud' ); ?>
								</p>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Test connection', 'strychni0x-media-bridge-for-nextcloud' ); ?></h2>
			<p>
				<button type="button" class="button" id="ncmb-test-connection"><?php esc_html_e( 'Check connection', 'strychni0x-media-bridge-for-nextcloud' ); ?></button>
				<span id="ncmb-test-result" style="margin-left:8px;"></span>
			</p>
		</div>
		<?php
	}
}
