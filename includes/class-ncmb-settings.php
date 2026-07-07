<?php
/**
 * Settings page and storage of the cloud accounts.
 *
 * Data model (option 'ncmb_settings'):
 *   array(
 *     'accounts' => array(
 *       array(
 *         'id'           => 'ac_xxxxxxxx',   // stable, generated once
 *         'label'        => 'My Nextcloud',
 *         'provider'     => 'nextcloud'|'owncloud',
 *         'base_url'     => 'https://cloud.example.com',
 *         'username'     => 'alice',
 *         'app_password' => '<encrypted>',
 *         'root_path'    => '/Photos',
 *         'thumb_mode'   => 'native'|'generate',
 *       ),
 *       ...
 *     ),
 *   )
 *
 * Any number of accounts of mixed provider types can be configured and used at
 * the same time. A single legacy (flat) account is migrated transparently.
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
	 * Default values for a single account.
	 *
	 * @return array
	 */
	private static function account_defaults() {
		return array(
			'id'           => '',
			'label'        => '',
			'provider'     => NCMB_Providers::DEFAULT_ID,
			'base_url'     => '',
			'username'     => '',
			'app_password' => '',
			'root_path'    => '/',
			'thumb_mode'   => 'native', // 'native' = provider preview, 'generate' = build in WP.
		);
	}

	/**
	 * Generates a stable, unguessable account id.
	 *
	 * @return string
	 */
	private static function new_id() {
		return 'ac_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );
	}

	/**
	 * Returns the normalized settings with all accounts (passwords decrypted).
	 *
	 * @return array{accounts:array<int,array>}
	 */
	public static function get() {
		$raw      = get_option( self::OPTION, array() );
		$raw      = is_array( $raw ) ? $raw : array();
		$accounts = self::normalize_accounts( $raw );

		// Decrypt the app password of every account.
		foreach ( $accounts as &$account ) {
			$account['app_password'] = NCMB_Crypto::decrypt( $account['app_password'] );
		}
		unset( $account );

		return array( 'accounts' => $accounts );
	}

	/**
	 * Returns all configured accounts (passwords decrypted).
	 *
	 * @return array<int,array>
	 */
	public static function get_accounts() {
		$settings = self::get();
		return $settings['accounts'];
	}

	/**
	 * Returns one account by id (decrypted). When $id is empty the first account
	 * is returned. Null when nothing matches / no accounts exist.
	 *
	 * @param string $id Account id.
	 * @return array|null
	 */
	public static function get_account( $id ) {
		$accounts = self::get_accounts();
		if ( empty( $accounts ) ) {
			return null;
		}
		$id = (string) $id;
		if ( '' === $id ) {
			return $accounts[0];
		}
		foreach ( $accounts as $account ) {
			if ( $account['id'] === $id ) {
				return $account;
			}
		}
		return null;
	}

	/**
	 * Turns a raw stored option (either new multi-account or legacy flat) into a
	 * normalized list of accounts. Passwords stay encrypted here.
	 *
	 * @param array $raw Raw option value.
	 * @return array<int,array>
	 */
	private static function normalize_accounts( array $raw ) {
		$defaults = self::account_defaults();

		// New format: an 'accounts' list.
		if ( isset( $raw['accounts'] ) && is_array( $raw['accounts'] ) ) {
			$out = array();
			foreach ( $raw['accounts'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$account = wp_parse_args( $entry, $defaults );
				if ( '' === $account['id'] ) {
					$account['id'] = self::new_id();
				}
				$account['provider']   = NCMB_Providers::is_valid( $account['provider'] ) ? $account['provider'] : NCMB_Providers::DEFAULT_ID;
				$account['thumb_mode'] = in_array( $account['thumb_mode'], array( 'native', 'generate' ), true ) ? $account['thumb_mode'] : 'native';
				$account['root_path']  = '/' . ltrim( (string) $account['root_path'], '/' );
				$out[]                 = $account;
			}
			return $out;
		}

		// Legacy flat format (single Nextcloud account) — migrate it.
		if ( isset( $raw['base_url'] ) || isset( $raw['username'] ) || isset( $raw['app_password'] ) ) {
			$legacy_mode = isset( $raw['thumb_mode'] ) && 'generate' === $raw['thumb_mode'] ? 'generate' : 'native';
			// Deterministic id so it stays stable across requests until the user
			// re-saves the settings (regenerating it per read would break the
			// account reference between the media page and the REST calls).
			$legacy_url = isset( $raw['base_url'] ) ? $raw['base_url'] : '';
			$legacy_usr = isset( $raw['username'] ) ? $raw['username'] : '';
			$account    = wp_parse_args(
				array(
					'id'           => 'ac_' . substr( md5( 'ncmb-legacy|' . $legacy_url . '|' . $legacy_usr ), 0, 12 ),
					'label'        => self::label_from_url( $legacy_url ),
					'provider'     => 'nextcloud',
					'base_url'     => isset( $raw['base_url'] ) ? $raw['base_url'] : '',
					'username'     => isset( $raw['username'] ) ? $raw['username'] : '',
					'app_password' => isset( $raw['app_password'] ) ? $raw['app_password'] : '', // Already encrypted at rest.
					'root_path'    => isset( $raw['root_path'] ) ? $raw['root_path'] : '/',
					'thumb_mode'   => $legacy_mode,
				),
				$defaults
			);
			return array( $account );
		}

		return array();
	}

	/**
	 * Derives a friendly default label from a base URL (its host).
	 *
	 * @param string $url Base URL.
	 * @return string
	 */
	private static function label_from_url( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		return $host ? $host : __( 'Cloud account', 'strychni0x-media-bridge-for-nextcloud' );
	}

	public function add_menu() {
		$this->hook_suffix = add_options_page(
			__( 'strychni0x Media Bridge', 'strychni0x-media-bridge-for-nextcloud' ),
			__( 'Cloud Media', 'strychni0x-media-bridge-for-nextcloud' ),
			ncmb_required_capability(),
			'ncmb-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues the settings script/style on the settings screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'ncmb-admin',
			NCMB_URL . 'assets/css/admin.css',
			array(),
			NCMB_VERSION
		);

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
				'option'  => self::OPTION,
				'i18n'    => array(
					'checking'   => __( 'Checking…', 'strychni0x-media-bridge-for-nextcloud' ),
					'ok'         => __( 'OK – Connection successful.', 'strychni0x-media-bridge-for-nextcloud' ),
					'error'      => __( 'Error', 'strychni0x-media-bridge-for-nextcloud' ),
					'saveFirst'  => __( 'Save the settings before testing this account.', 'strychni0x-media-bridge-for-nextcloud' ),
					'remove'     => __( 'Remove this cloud', 'strychni0x-media-bridge-for-nextcloud' ),
					'confirmDel' => __( 'Remove this cloud account from the list?', 'strychni0x-media-bridge-for-nextcloud' ),
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
	 * Sanitizes the submitted accounts. Existing app passwords are preserved when
	 * the corresponding field is left empty (matched by account id).
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array{accounts:array<int,array>}
	 */
	public function sanitize( $input ) {
		$existing = array();
		foreach ( self::get_accounts() as $acc ) {
			$existing[ $acc['id'] ] = $acc; // Passwords already decrypted here.
		}

		$out = array( 'accounts' => array() );

		$rows = ( is_array( $input ) && isset( $input['accounts'] ) && is_array( $input['accounts'] ) )
			? $input['accounts']
			: array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id       = isset( $row['id'] ) ? sanitize_text_field( $row['id'] ) : '';
			$base_url = isset( $row['base_url'] ) ? esc_url_raw( untrailingslashit( trim( $row['base_url'] ) ) ) : '';
			$username = isset( $row['username'] ) ? sanitize_text_field( $row['username'] ) : '';

			// Skip completely empty rows (e.g. the template or an untouched new row).
			if ( '' === $base_url && '' === $username && ( ! isset( $row['app_password'] ) || '' === trim( (string) $row['app_password'] ) ) ) {
				continue;
			}

			if ( '' === $id || ! preg_match( '/^ac_[a-f0-9]{12}$/', $id ) ) {
				$id = self::new_id();
			}

			$provider = isset( $row['provider'] ) ? sanitize_key( $row['provider'] ) : NCMB_Providers::DEFAULT_ID;
			$provider = NCMB_Providers::is_valid( $provider ) ? $provider : NCMB_Providers::DEFAULT_ID;

			$mode = isset( $row['thumb_mode'] ) ? sanitize_key( $row['thumb_mode'] ) : 'native';
			$mode = in_array( $mode, array( 'native', 'generate' ), true ) ? $mode : 'native';

			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			if ( '' === $label ) {
				$label = self::label_from_url( $base_url );
			}

			$root_path = isset( $row['root_path'] ) ? '/' . ltrim( sanitize_text_field( $row['root_path'] ), '/' ) : '/';

			// Keep the stored password when the field is left blank.
			$new_pw   = isset( $row['app_password'] ) ? trim( $row['app_password'] ) : '';
			$plain_pw = ( '' !== $new_pw )
				? $new_pw
				: ( isset( $existing[ $id ] ) ? $existing[ $id ]['app_password'] : '' );

			$out['accounts'][] = array(
				'id'           => $id,
				'label'        => $label,
				'provider'     => $provider,
				'base_url'     => $base_url,
				'username'     => $username,
				'app_password' => NCMB_Crypto::encrypt( $plain_pw ),
				'root_path'    => $root_path,
				'thumb_mode'   => $mode,
			);
		}

		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( ncmb_required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'strychni0x-media-bridge-for-nextcloud' ) );
		}

		$accounts = self::get_accounts();
		?>
		<div class="wrap ncmb-settings-wrap">
			<h1><?php esc_html_e( 'strychni0x Media Bridge', 'strychni0x-media-bridge-for-nextcloud' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Connect one or more Nextcloud or ownCloud accounts. All configured clouds are available at the same time from the media dialog.', 'strychni0x-media-bridge-for-nextcloud' ); ?>
			</p>

			<form action="options.php" method="post">
				<?php settings_fields( 'ncmb_settings_group' ); ?>

				<div id="ncmb-accounts">
					<?php
					if ( empty( $accounts ) ) {
						// Render one empty starter row.
						$this->render_account_row( 0, wp_parse_args( array( 'id' => self::new_id() ), self::account_defaults() ) );
					} else {
						foreach ( array_values( $accounts ) as $i => $account ) {
							$this->render_account_row( $i, $account );
						}
					}
					?>
				</div>

				<p>
					<button type="button" class="button" id="ncmb-add-account">
						+ <?php esc_html_e( 'Add cloud', 'strychni0x-media-bridge-for-nextcloud' ); ?>
					</button>
				</p>

				<?php submit_button(); ?>
			</form>

			<?php
			// Hidden template used by the JS to append new account rows.
			echo '<template id="ncmb-account-template">';
			$this->render_account_row( '__INDEX__', wp_parse_args( array( 'id' => '' ), self::account_defaults() ), true );
			echo '</template>';
			?>
		</div>
		<?php
	}

	/**
	 * Renders a single account fieldset.
	 *
	 * @param int|string $index    Row index (or the "__INDEX__" template placeholder).
	 * @param array      $account  Account data (password decrypted; empty for new rows).
	 * @param bool       $template Whether this is the (inert) template row.
	 */
	private function render_account_row( $index, $account, $template = false ) {
		$name   = self::OPTION . '[accounts][' . $index . ']';
		$has_pw = ! $template && '' !== $account['app_password'];
		$saved  = ! $template && '' !== $account['id'] && ! empty( $account['base_url'] );
		?>
		<fieldset class="ncmb-account" <?php echo $template ? 'data-template="1"' : ''; ?>>
			<div class="ncmb-account-head">
				<strong class="ncmb-account-title">
					<?php echo esc_html( '' !== $account['label'] ? $account['label'] : __( 'New cloud', 'strychni0x-media-bridge-for-nextcloud' ) ); ?>
				</strong>
				<button type="button" class="button-link ncmb-remove-account" aria-label="<?php esc_attr_e( 'Remove this cloud', 'strychni0x-media-bridge-for-nextcloud' ); ?>">
					<?php esc_html_e( 'Remove', 'strychni0x-media-bridge-for-nextcloud' ); ?>
				</button>
			</div>

			<input type="hidden" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $account['id'] ); ?>" class="ncmb-f-id" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Name', 'strychni0x-media-bridge-for-nextcloud' ); ?></label></th>
					<td>
						<input name="<?php echo esc_attr( $name ); ?>[label]" type="text" class="regular-text ncmb-f-label" value="<?php echo esc_attr( $account['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Team photos', 'strychni0x-media-bridge-for-nextcloud' ); ?>" />
						<p class="description"><?php esc_html_e( 'A label shown in the media dialog. Optional – defaults to the host name.', 'strychni0x-media-bridge-for-nextcloud' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Cloud type', 'strychni0x-media-bridge-for-nextcloud' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>[provider]" class="ncmb-f-provider">
							<?php foreach ( NCMB_Providers::all() as $pid => $pdef ) : ?>
								<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $account['provider'], $pid ); ?>>
									<?php echo esc_html( $pdef['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Server URL', 'strychni0x-media-bridge-for-nextcloud' ); ?></label></th>
					<td>
						<input name="<?php echo esc_attr( $name ); ?>[base_url]" type="url" class="regular-text ncmb-f-url" placeholder="https://cloud.example.com" value="<?php echo esc_attr( $account['base_url'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Base URL without a trailing slash.', 'strychni0x-media-bridge-for-nextcloud' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Username', 'strychni0x-media-bridge-for-nextcloud' ); ?></label></th>
					<td><input name="<?php echo esc_attr( $name ); ?>[username]" type="text" class="regular-text ncmb-f-user" value="<?php echo esc_attr( $account['username'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'App password', 'strychni0x-media-bridge-for-nextcloud' ); ?></label></th>
					<td>
						<input name="<?php echo esc_attr( $name ); ?>[app_password]" type="password" class="regular-text ncmb-f-pw" autocomplete="new-password" placeholder="<?php echo $has_pw ? '••••••••••••' : ''; ?>" value="" />
						<p class="description">
							<?php esc_html_e( 'Create an app password in your cloud (Settings → Security). Do not use your normal login password.', 'strychni0x-media-bridge-for-nextcloud' ); ?>
							<?php if ( $has_pw ) { echo '<br><strong>' . esc_html__( 'A password is stored. Leave the field empty to keep it.', 'strychni0x-media-bridge-for-nextcloud' ) . '</strong>'; } ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Start folder', 'strychni0x-media-bridge-for-nextcloud' ); ?></label></th>
					<td>
						<input name="<?php echo esc_attr( $name ); ?>[root_path]" type="text" class="regular-text ncmb-f-root" value="<?php echo esc_attr( $account['root_path'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Path relative to the user root, e.g. /Photos. Default: /', 'strychni0x-media-bridge-for-nextcloud' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Thumbnails', 'strychni0x-media-bridge-for-nextcloud' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[thumb_mode]" value="native" <?php checked( $account['thumb_mode'], 'native' ); ?> />
								<?php esc_html_e( 'From the cloud server', 'strychni0x-media-bridge-for-nextcloud' ); ?>
							</label><br />
							<label>
								<input type="radio" name="<?php echo esc_attr( $name ); ?>[thumb_mode]" value="generate" <?php checked( $account['thumb_mode'], 'generate' ); ?> />
								<?php esc_html_e( 'Generate in WordPress', 'strychni0x-media-bridge-for-nextcloud' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Default: from the server (uses its preview/thumbnail endpoint). If the server generates no previews, WordPress can build and cache them – it downloads each image once.', 'strychni0x-media-bridge-for-nextcloud' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection', 'strychni0x-media-bridge-for-nextcloud' ); ?></th>
					<td>
						<button type="button" class="button ncmb-test-connection" data-account="<?php echo esc_attr( $account['id'] ); ?>" <?php disabled( ! $saved ); ?>>
							<?php esc_html_e( 'Check connection', 'strychni0x-media-bridge-for-nextcloud' ); ?>
						</button>
						<span class="ncmb-test-result" style="margin-left:8px;"></span>
						<?php if ( ! $template && ! $saved ) : ?>
							<p class="description ncmb-test-hint"><?php esc_html_e( 'Save the settings first to test a newly added account.', 'strychni0x-media-bridge-for-nextcloud' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</fieldset>
		<?php
	}
}
