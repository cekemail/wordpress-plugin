<?php
/**
 * Settings storage and settings screen.
 *
 * @package CekEmail
 */

namespace CekEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the plugin options and renders the settings screen.
 */
class Settings {

	/**
	 * Option holding every setting.
	 */
	const OPTION = 'cekemail_settings';

	/**
	 * Settings group used by the Settings API.
	 */
	const GROUP = 'cekemail_settings_group';

	/**
	 * Settings page slug.
	 */
	const PAGE = 'cekemail-email-validation';

	/**
	 * Smallest accepted cache lifetime, in seconds. Zero disables caching.
	 */
	const CACHE_TTL_MIN = 60;

	/**
	 * Largest accepted cache lifetime, in seconds.
	 */
	const CACHE_TTL_MAX = 604800;

	/**
	 * Smallest accepted API request timeout, in seconds.
	 */
	const REQUEST_TIMEOUT_MIN = 5;

	/**
	 * Largest accepted API request timeout, in seconds.
	 */
	const REQUEST_TIMEOUT_MAX = 30;

	/**
	 * API client, used by the connection test.
	 *
	 * @var Api_Client|null
	 */
	private $api_client = null;

	/**
	 * Recent checks log, rendered on the settings page.
	 *
	 * @var Log|null
	 */
	private $log = null;

	/**
	 * Memoised settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private $cache = null;

	/**
	 * Default value for every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'api_key'           => '',
			'api_base_url'      => 'https://api.cekemail.com',
			'request_timeout'   => 15,
			'widget_enabled'    => true,
			'widget_key'        => '',
			'block_disposable'  => true,
			'on_catch_all'      => 'allow',
			'on_unknown'        => 'allow',
			'on_api_error'      => 'allow',
			'show_suggestion'   => true,
			'cache_ttl'         => 3600,
			'integrations'      => self::default_integrations(),
		);
	}

	/**
	 * Default state of every integration toggle.
	 *
	 * @return array<string, bool>
	 */
	public static function default_integrations(): array {
		return array(
			'core_registration' => true,
			'core_comments'     => true,
			'woocommerce'       => true,
			'cf7'               => true,
		);
	}

	/**
	 * Labels shown for each integration toggle.
	 *
	 * @return array<string, string>
	 */
	public static function integration_labels(): array {
		/**
		 * Filters the integration toggles shown on the settings screen.
		 *
		 * @param array<string, string> $labels Integration slug to label.
		 */
		return apply_filters(
			'cekemail_integration_labels',
			array(
				'core_registration' => __( 'WordPress user registration', 'cekemail-email-validation' ),
				'core_comments'     => __( 'WordPress comments', 'cekemail-email-validation' ),
				'woocommerce'       => __( 'WooCommerce checkout and registration', 'cekemail-email-validation' ),
				'cf7'               => __( 'Contact Form 7', 'cekemail-email-validation' ),
			)
		);
	}

	/**
	 * URL of the settings screen.
	 *
	 * @return string
	 */
	public static function page_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Absolute URL of a versioned API endpoint.
	 *
	 * The hosted API on an `api.` host serves the versioned paths at its root.
	 * Any other host, such as a self-hosted instance or the legacy
	 * https://cekemail.com URL, serves them under the `/api` prefix.
	 *
	 * @param string $base_url Configured API URL.
	 * @param string $path     Versioned endpoint path, leading slash included, such as `/v1/email-check`.
	 * @return string
	 */
	public static function api_url( string $base_url, string $path ): string {
		$base_url = untrailingslashit( $base_url );
		$parsed   = wp_parse_url( $base_url );
		$host     = is_array( $parsed ) && isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';

		if ( 0 !== strpos( $host, 'api.' ) ) {
			$path = '/api' . $path;
		}

		return $base_url . $path;
	}

	/**
	 * Hook the settings screen and its handlers.
	 *
	 * @param Api_Client $api_client API client used by the connection test.
	 * @param Log        $log        Recent checks log.
	 * @return void
	 */
	public function register( Api_Client $api_client, Log $log ): void {
		$this->api_client = $api_client;
		$this->log        = $log;

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_cekemail_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'admin_post_cekemail_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_cache' ) );
	}

	/**
	 * Read every setting, with defaults applied.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( self::defaults(), $stored );

		$integrations = isset( $stored['integrations'] ) && is_array( $stored['integrations'] ) ? $stored['integrations'] : array();

		$settings['integrations'] = array_merge( self::default_integrations(), $integrations );

		$this->cache = $settings;

		return $settings;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value returned when the key is unknown.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$settings = $this->all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Whether an API key is configured.
	 *
	 * @return bool
	 */
	public function has_api_key(): bool {
		return '' !== trim( (string) $this->get( 'api_key' ) );
	}

	/**
	 * Whether an integration toggle is on.
	 *
	 * @param string $key Integration slug.
	 * @return bool
	 */
	public function is_integration_enabled( string $key ): bool {
		$integrations = $this->get( 'integrations' );

		if ( ! is_array( $integrations ) || ! isset( $integrations[ $key ] ) ) {
			return false;
		}

		return (bool) $integrations[ $key ];
	}

	/**
	 * Drop the memoised settings.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->cache = null;
	}

	/**
	 * Register the option and its fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section(
			'cekemail_section_general',
			__( 'General', 'cekemail-email-validation' ),
			array( $this, 'render_general_section' ),
			self::PAGE
		);

		add_settings_field(
			'cekemail_api_key',
			__( 'API key', 'cekemail-email-validation' ),
			array( $this, 'render_api_key_field' ),
			self::PAGE,
			'cekemail_section_general'
		);

		add_settings_field(
			'cekemail_api_base_url',
			__( 'API URL', 'cekemail-email-validation' ),
			array( $this, 'render_api_base_url_field' ),
			self::PAGE,
			'cekemail_section_general'
		);

		add_settings_field(
			'cekemail_request_timeout',
			__( 'Request timeout', 'cekemail-email-validation' ),
			array( $this, 'render_request_timeout_field' ),
			self::PAGE,
			'cekemail_section_general'
		);

		add_settings_field(
			'cekemail_connection',
			__( 'Connection', 'cekemail-email-validation' ),
			array( $this, 'render_connection_field' ),
			self::PAGE,
			'cekemail_section_general'
		);

		add_settings_section(
			'cekemail_section_policy',
			__( 'Policy', 'cekemail-email-validation' ),
			array( $this, 'render_policy_section' ),
			self::PAGE
		);

		add_settings_field(
			'cekemail_block_disposable',
			__( 'Disposable addresses', 'cekemail-email-validation' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE,
			'cekemail_section_policy',
			array(
				'key'   => 'block_disposable',
				'label' => __( 'Block addresses from disposable mail providers', 'cekemail-email-validation' ),
			)
		);

		add_settings_field(
			'cekemail_on_catch_all',
			__( 'Catch-all domains', 'cekemail-email-validation' ),
			array( $this, 'render_policy_field' ),
			self::PAGE,
			'cekemail_section_policy',
			array(
				'key'         => 'on_catch_all',
				'description' => __( 'Catch-all servers accept every address, so the mailbox itself cannot be confirmed.', 'cekemail-email-validation' ),
			)
		);

		add_settings_field(
			'cekemail_on_unknown',
			__( 'Unverifiable addresses', 'cekemail-email-validation' ),
			array( $this, 'render_policy_field' ),
			self::PAGE,
			'cekemail_section_policy',
			array(
				'key'         => 'on_unknown',
				'description' => __( 'Used when the mail server greylists us or gives no usable answer.', 'cekemail-email-validation' ),
			)
		);

		add_settings_field(
			'cekemail_on_api_error',
			__( 'API errors', 'cekemail-email-validation' ),
			array( $this, 'render_policy_field' ),
			self::PAGE,
			'cekemail_section_policy',
			array(
				'key'         => 'on_api_error',
				'description' => __( 'Used when the API cannot be reached, the key is rejected or credits run out.', 'cekemail-email-validation' ),
			)
		);

		add_settings_field(
			'cekemail_show_suggestion',
			__( 'Typo suggestions', 'cekemail-email-validation' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE,
			'cekemail_section_policy',
			array(
				'key'   => 'show_suggestion',
				'label' => __( 'Include the suggested address in the error message', 'cekemail-email-validation' ),
			)
		);

		add_settings_field(
			'cekemail_cache_ttl',
			__( 'Cache lifetime', 'cekemail-email-validation' ),
			array( $this, 'render_cache_ttl_field' ),
			self::PAGE,
			'cekemail_section_policy'
		);

		add_settings_section(
			'cekemail_section_integrations',
			__( 'Integrations', 'cekemail-email-validation' ),
			array( $this, 'render_integrations_section' ),
			self::PAGE
		);

		add_settings_field(
			'cekemail_integrations',
			__( 'Check addresses on', 'cekemail-email-validation' ),
			array( $this, 'render_integrations_field' ),
			self::PAGE,
			'cekemail_section_integrations'
		);

		add_settings_section(
			'cekemail_section_widget',
			__( 'Front-end widget', 'cekemail-email-validation' ),
			array( $this, 'render_widget_section' ),
			self::PAGE
		);

		add_settings_field(
			'cekemail_widget_enabled',
			__( 'Widget', 'cekemail-email-validation' ),
			array( $this, 'render_checkbox_field' ),
			self::PAGE,
			'cekemail_section_widget',
			array(
				'key'   => 'widget_enabled',
				'label' => __( 'Check email addresses in the browser when visitors leave the email field', 'cekemail-email-validation' ),
			)
		);

		add_settings_field(
			'cekemail_widget_key',
			__( 'Widget key', 'cekemail-email-validation' ),
			array( $this, 'render_widget_key_field' ),
			self::PAGE,
			'cekemail_section_widget'
		);
	}

	/**
	 * Add the settings screen under the Settings menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_options_page(
			__( 'CekEmail Email Validation', 'cekemail-email-validation' ),
			__( 'CekEmail', 'cekemail-email-validation' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the inline script backing the connection test.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE !== $hook_suffix ) {
			return;
		}

		wp_register_script( 'cekemail-admin', false, array( 'jquery' ), CEKEMAIL_VERSION, true );
		wp_enqueue_script( 'cekemail-admin' );

		wp_localize_script(
			'cekemail-admin',
			'cekemailAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cekemail_test_connection' ),
				'testing' => __( 'Testing the connection…', 'cekemail-email-validation' ),
				'failed'  => __( 'The connection test could not be completed.', 'cekemail-email-validation' ),
			)
		);

		wp_add_inline_script( 'cekemail-admin', $this->inline_script() );
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'CekEmail Email Validation', 'cekemail-email-validation' ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<?php $this->render_log_table(); ?>
		</div>
		<?php
	}

	/**
	 * Sanitize the submitted settings.
	 *
	 * @param mixed $input Raw settings.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$current  = $this->all();
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();

		$output = array();

		$api_key = isset( $input['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) ) : '';

		if ( ! empty( $input['remove_api_key'] ) ) {
			$output['api_key'] = '';
		} else {
			$output['api_key'] = '' === $api_key ? (string) $current['api_key'] : $api_key;
		}

		$output['api_base_url'] = $this->sanitize_url(
			isset( $input['api_base_url'] ) ? $input['api_base_url'] : '',
			(string) $defaults['api_base_url']
		);

		$output['widget_enabled']   = ! empty( $input['widget_enabled'] );
		$output['block_disposable'] = ! empty( $input['block_disposable'] );
		$output['show_suggestion']  = ! empty( $input['show_suggestion'] );

		$output['widget_key'] = isset( $input['widget_key'] ) ? sanitize_text_field( wp_unslash( $input['widget_key'] ) ) : '';

		foreach ( array( 'on_catch_all', 'on_unknown', 'on_api_error' ) as $key ) {
			$value          = isset( $input[ $key ] ) ? sanitize_key( wp_unslash( $input[ $key ] ) ) : '';
			$output[ $key ] = in_array( $value, array( 'allow', 'block' ), true ) ? $value : (string) $defaults[ $key ];
		}

		$output['cache_ttl'] = $this->sanitize_cache_ttl( isset( $input['cache_ttl'] ) ? $input['cache_ttl'] : $defaults['cache_ttl'] );

		$output['request_timeout'] = $this->sanitize_request_timeout( isset( $input['request_timeout'] ) ? $input['request_timeout'] : $defaults['request_timeout'] );

		$integrations = isset( $input['integrations'] ) && is_array( $input['integrations'] ) ? $input['integrations'] : array();
		$known        = array_keys( self::default_integrations() );
		$toggles      = array();

		foreach ( $known as $key ) {
			$toggles[ $key ] = ! empty( $integrations[ $key ] );
		}

		$output['integrations'] = $toggles;

		$this->cache = null;

		return $output;
	}

	/**
	 * Handle the connection test request.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'cekemail_test_connection' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to do that.', 'cekemail-email-validation' ) ),
				403
			);
		}

		if ( ! $this->api_client instanceof Api_Client ) {
			wp_send_json_error( array( 'message' => __( 'The API client is not available.', 'cekemail-email-validation' ) ) );
		}

		$this->flush_cache();

		$result = $this->api_client->test_connection();

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		}

		wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * Handle the clear log request.
	 *
	 * @return void
	 */
	public function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'cekemail-email-validation' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'cekemail_clear_log' );

		if ( $this->log instanceof Log ) {
			$this->log->clear();
		}

		wp_safe_redirect( add_query_arg( 'cekemail-log-cleared', '1', self::page_url() ) );
		exit;
	}

	/**
	 * General section description.
	 *
	 * @return void
	 */
	public function render_general_section(): void {
		echo '<p>' . esc_html__( 'Create an API key in your CekEmail dashboard and paste it here.', 'cekemail-email-validation' ) . '</p>';
	}

	/**
	 * Policy section description.
	 *
	 * @return void
	 */
	public function render_policy_section(): void {
		echo '<p>' . esc_html__( 'Decide what happens when an address cannot be confirmed. Invalid addresses are always blocked.', 'cekemail-email-validation' ) . '</p>';
	}

	/**
	 * Integrations section description.
	 *
	 * @return void
	 */
	public function render_integrations_section(): void {
		echo '<p>' . esc_html__( 'Integrations only run while an API key is saved.', 'cekemail-email-validation' ) . '</p>';
	}

	/**
	 * Widget section description.
	 *
	 * @return void
	 */
	public function render_widget_section(): void {
		echo '<p>' . esc_html__( 'The widget gives visitors feedback before they submit a form. It never exposes your API key.', 'cekemail-email-validation' ) . '</p>';
		echo '<p>' . esc_html__( 'The script is bundled with the plugin, is only loaded on the front end, and attaches itself to every email field on the page, whichever plugin or theme rendered it.', 'cekemail-email-validation' ) . '</p>';
	}

	/**
	 * API key field.
	 *
	 * @return void
	 */
	public function render_api_key_field(): void {
		printf(
			'<input type="password" class="regular-text" id="cekemail_api_key" name="%1$s[api_key]" value="" autocomplete="off" />',
			esc_attr( self::OPTION )
		);

		echo '<p class="description">';

		if ( $this->has_api_key() ) {
			echo esc_html__( 'A key is saved. Leave blank to keep the current key, or tick "Remove the saved API key" to delete it and stop verification.', 'cekemail-email-validation' );
		} else {
			echo esc_html__( 'No key saved yet. Leave blank to keep the current key.', 'cekemail-email-validation' );
		}

		echo '</p>';

		if ( $this->has_api_key() ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s[remove_api_key]" value="1" /> %2$s</label></p>',
				esc_attr( self::OPTION ),
				esc_html__( 'Remove the saved API key', 'cekemail-email-validation' )
			);
		}
	}

	/**
	 * API URL field.
	 *
	 * @return void
	 */
	public function render_api_base_url_field(): void {
		printf(
			'<input type="url" class="regular-text code" id="cekemail_api_base_url" name="%1$s[api_base_url]" value="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( (string) $this->get( 'api_base_url' ) )
		);

		echo '<p class="description">' . esc_html__( 'Only change this if you use a self-hosted CekEmail instance.', 'cekemail-email-validation' ) . ' ' . esc_html__( 'Self-hosted instances use the /api/v1 prefix automatically.', 'cekemail-email-validation' ) . ' ' . esc_html__( 'The address must use https; plain http is only accepted for localhost and 127.0.0.1.', 'cekemail-email-validation' ) . '</p>';
	}

	/**
	 * API request timeout field.
	 *
	 * @return void
	 */
	public function render_request_timeout_field(): void {
		printf(
			'<input type="number" class="small-text" min="%1$d" max="%2$d" step="1" name="%3$s[request_timeout]" value="%4$d" /> %5$s',
			(int) self::REQUEST_TIMEOUT_MIN,
			(int) self::REQUEST_TIMEOUT_MAX,
			esc_attr( self::OPTION ),
			(int) $this->get( 'request_timeout' ),
			esc_html__( 'seconds', 'cekemail-email-validation' )
		);

		echo '<p class="description">' . esc_html__( "How long to wait for the CekEmail API before applying the 'API errors' policy. Slow mail servers can take up to 30 seconds on the first check of an address; later checks are cached.", 'cekemail-email-validation' ) . '</p>';
	}

	/**
	 * Connection test button.
	 *
	 * @return void
	 */
	public function render_connection_field(): void {
		printf(
			'<button type="button" class="button" id="cekemail-test-connection">%s</button>',
			esc_html__( 'Test connection', 'cekemail-email-validation' )
		);

		echo ' <span id="cekemail-test-connection-result"></span>';

		echo '<p class="description">' . esc_html__( 'Saves nothing and uses no credits. Save your settings first.', 'cekemail-email-validation' ) . '</p>';
	}

	/**
	 * Generic checkbox field.
	 *
	 * @param array $args Field arguments with `key` and `label`.
	 * @return void
	 */
	public function render_checkbox_field( $args ): void {
		$key   = isset( $args['key'] ) ? (string) $args['key'] : '';
		$label = isset( $args['label'] ) ? (string) $args['label'] : '';

		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( (bool) $this->get( $key ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Allow or block dropdown.
	 *
	 * @param array $args Field arguments with `key` and `description`.
	 * @return void
	 */
	public function render_policy_field( $args ): void {
		$key     = isset( $args['key'] ) ? (string) $args['key'] : '';
		$current = (string) $this->get( $key );

		printf(
			'<select name="%1$s[%2$s]"><option value="allow" %3$s>%4$s</option><option value="block" %5$s>%6$s</option></select>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			selected( $current, 'allow', false ),
			esc_html__( 'Allow the submission', 'cekemail-email-validation' ),
			selected( $current, 'block', false ),
			esc_html__( 'Block the submission', 'cekemail-email-validation' )
		);

		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( (string) $args['description'] ) . '</p>';
		}
	}

	/**
	 * Cache lifetime field.
	 *
	 * @return void
	 */
	public function render_cache_ttl_field(): void {
		printf(
			'<input type="number" class="small-text" min="0" max="%1$d" step="1" name="%2$s[cache_ttl]" value="%3$d" /> %4$s',
			(int) self::CACHE_TTL_MAX,
			esc_attr( self::OPTION ),
			(int) $this->get( 'cache_ttl' ),
			esc_html__( 'seconds', 'cekemail-email-validation' )
		);

		echo '<p class="description">' . esc_html__( 'How long a result is reused before the address is checked again. Use 0 to disable caching.', 'cekemail-email-validation' ) . '</p>';
	}

	/**
	 * Widget key field.
	 *
	 * @return void
	 */
	public function render_widget_key_field(): void {
		printf(
			'<input type="text" class="regular-text code" name="%1$s[widget_key]" value="%2$s" autocomplete="off" />',
			esc_attr( self::OPTION ),
			esc_attr( (string) $this->get( 'widget_key' ) )
		);

		echo '<p class="description">' . esc_html__( 'Public widget key from your CekEmail dashboard. It is safe to expose in the browser.', 'cekemail-email-validation' ) . '</p>';
	}

	/**
	 * Integration toggles.
	 *
	 * @return void
	 */
	public function render_integrations_field(): void {
		echo '<fieldset>';

		foreach ( self::integration_labels() as $key => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[integrations][%2$s]" value="1" %3$s /> %4$s</label><br />',
				esc_attr( self::OPTION ),
				esc_attr( (string) $key ),
				checked( $this->is_integration_enabled( (string) $key ), true, false ),
				esc_html( (string) $label )
			);
		}

		echo '</fieldset>';
	}

	/**
	 * Recent checks table.
	 *
	 * @return void
	 */
	private function render_log_table(): void {
		if ( ! $this->log instanceof Log ) {
			return;
		}

		$entries = $this->log->entries();

		echo '<h2>' . esc_html__( 'Recent checks', 'cekemail-email-validation' ) . '</h2>';

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'No addresses have been checked yet.', 'cekemail-email-validation' ) . '</p>';

			return;
		}

		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Time', 'cekemail-email-validation' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Email', 'cekemail-email-validation' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Status', 'cekemail-email-validation' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Reason', 'cekemail-email-validation' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Decision', 'cekemail-email-validation' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Source', 'cekemail-email-validation' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $entries as $entry ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', isset( $entry['time'] ) ? (int) $entry['time'] : 0 ) ); ?></td>
					<td><?php echo esc_html( isset( $entry['email'] ) ? (string) $entry['email'] : '' ); ?></td>
					<td><?php echo esc_html( $this->log_status( $entry ) ); ?></td>
					<td><?php echo esc_html( isset( $entry['reason_code'] ) ? (string) $entry['reason_code'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $entry['decision'] ) ? (string) $entry['decision'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $entry['source'] ) ? (string) $entry['source'] : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="cekemail_clear_log" />
			<?php wp_nonce_field( 'cekemail_clear_log' ); ?>
			<?php submit_button( __( 'Clear log', 'cekemail-email-validation' ), 'secondary', 'submit', true ); ?>
		</form>
		<?php
	}

	/**
	 * Status shown for one log entry, so a fail-open decision is visible.
	 *
	 * @param array $entry Log entry.
	 * @return string
	 */
	private function log_status( array $entry ): string {
		$status = isset( $entry['status'] ) ? (string) $entry['status'] : '';

		if ( '' !== $status ) {
			return $status;
		}

		$error = isset( $entry['error'] ) ? (string) $entry['error'] : '';

		if ( '' === $error ) {
			return '';
		}

		/* translators: %s: error slug, for example network. */
		return sprintf( __( 'Error: %s', 'cekemail-email-validation' ), $error );
	}

	/**
	 * Sanitize the API base URL.
	 *
	 * @param mixed  $value    Raw URL.
	 * @param string $fallback Value used when the URL is unusable.
	 * @return string
	 */
	private function sanitize_url( $value, string $fallback ): string {
		$url = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';

		$parsed = '' === $url ? false : wp_parse_url( $url );
		$scheme = is_array( $parsed ) && isset( $parsed['scheme'] ) ? strtolower( (string) $parsed['scheme'] ) : '';
		$host   = is_array( $parsed ) && isset( $parsed['host'] ) ? (string) $parsed['host'] : '';

		$is_local = in_array( strtolower( $host ), array( 'localhost', '127.0.0.1' ), true );

		if ( '' !== $host && ( 'https' === $scheme || ( 'http' === $scheme && $is_local ) ) ) {
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
		} else {
			$url = '';
		}

		if ( '' === $url ) {
			add_settings_error(
				self::OPTION,
				'cekemail_api_base_url',
				__( 'The API URL must be a full https address; plain http is only accepted for localhost and 127.0.0.1. The default was restored.', 'cekemail-email-validation' )
			);

			return $fallback;
		}

		return untrailingslashit( $url );
	}

	/**
	 * Clamp the cache lifetime into its accepted range.
	 *
	 * @param mixed $value Raw lifetime.
	 * @return int
	 */
	private function sanitize_cache_ttl( $value ): int {
		if ( ! is_numeric( $value ) ) {
			$defaults = self::defaults();

			return (int) $defaults['cache_ttl'];
		}

		$ttl = absint( $value );

		if ( 0 === $ttl ) {
			return 0;
		}

		if ( $ttl < self::CACHE_TTL_MIN ) {
			return self::CACHE_TTL_MIN;
		}

		if ( $ttl > self::CACHE_TTL_MAX ) {
			return self::CACHE_TTL_MAX;
		}

		return $ttl;
	}

	/**
	 * Clamp the API request timeout into its accepted range.
	 *
	 * @param mixed $value Raw timeout.
	 * @return int
	 */
	private function sanitize_request_timeout( $value ): int {
		if ( ! is_numeric( $value ) ) {
			$defaults = self::defaults();

			return (int) $defaults['request_timeout'];
		}

		$timeout = absint( $value );

		if ( $timeout < self::REQUEST_TIMEOUT_MIN ) {
			return self::REQUEST_TIMEOUT_MIN;
		}

		if ( $timeout > self::REQUEST_TIMEOUT_MAX ) {
			return self::REQUEST_TIMEOUT_MAX;
		}

		return $timeout;
	}

	/**
	 * Script backing the connection test button.
	 *
	 * @return string
	 */
	private function inline_script(): string {
		return <<<'JS'
( function ( $ ) {
	$( document ).on( 'click', '#cekemail-test-connection', function ( event ) {
		event.preventDefault();

		var button = $( this );
		var output = $( '#cekemail-test-connection-result' );

		button.prop( 'disabled', true );
		output.removeClass( 'notice notice-success notice-error notice-alt' ).text( cekemailAdmin.testing );

		$.post( cekemailAdmin.ajaxUrl, {
			action: 'cekemail_test_connection',
			_ajax_nonce: cekemailAdmin.nonce
		} ).done( function ( response ) {
			var message = ( response && response.data && response.data.message ) ? response.data.message : cekemailAdmin.failed;
			var success = !! ( response && response.success );

			output.addClass( 'notice notice-alt' ).addClass( success ? 'notice-success' : 'notice-error' ).text( message );
		} ).fail( function () {
			output.addClass( 'notice notice-alt notice-error' ).text( cekemailAdmin.failed );
		} ).always( function () {
			button.prop( 'disabled', false );
		} );
	} );
}( jQuery ) );
JS;
	}
}
