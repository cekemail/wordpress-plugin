<?php
/**
 * Front-end widget loader.
 *
 * @package CekEmail
 */

namespace CekEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the bundled browser widget on the front end.
 *
 * The script ships with the plugin and is never loaded from a CDN. It attaches
 * itself to every `input[type="email"]` on the page and checks the address with
 * the public widget endpoint, which uses the widget key and never the API key.
 */
class Frontend_Widget {

	/**
	 * Script handle.
	 */
	const HANDLE = 'cekemail-widget';

	/**
	 * Path of the bundled script, relative to the plugin directory.
	 */
	const SCRIPT = 'assets/js/cekemail-widget.min.js';

	/**
	 * Endpoint the widget posts to, appended to the configured API URL.
	 */
	const ENDPOINT = '/api/v1/widget/email-check';

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the enqueue callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the widget and its configuration.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( self::SCRIPT, CEKEMAIL_PLUGIN_FILE ),
			array(),
			CEKEMAIL_VERSION,
			true
		);

		wp_add_inline_script( self::HANDLE, $this->config_script(), 'before' );
	}

	/**
	 * Whether the widget should load on this request.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( ! $this->settings->get( 'widget_enabled' ) ) {
			return false;
		}

		return '' !== $this->widget_key();
	}

	/**
	 * Configured widget key.
	 *
	 * @return string
	 */
	private function widget_key(): string {
		return trim( (string) $this->settings->get( 'widget_key' ) );
	}

	/**
	 * Globals the widget reads when it boots.
	 *
	 * @return string
	 */
	private function config_script(): string {
		$config = array(
			'CekEmail_APIKEY'   => $this->widget_key(),
			'CekEmail_API_URL'  => untrailingslashit( (string) $this->settings->get( 'api_base_url' ) ) . self::ENDPOINT,
			'CekEmail_LOCALE'   => $this->locale(),
			'CekEmail_MESSAGES' => (object) array(),
		);

		$lines = array();

		foreach ( $config as $name => $value ) {
			$lines[] = sprintf( 'window.%1$s = %2$s;', $name, wp_json_encode( $value ) );
		}

		return implode( "\n", $lines );
	}

	/**
	 * Locale the widget renders its messages in.
	 *
	 * @return string
	 */
	private function locale(): string {
		return 0 === strpos( strtolower( (string) get_locale() ), 'id' ) ? 'id' : 'en';
	}
}
