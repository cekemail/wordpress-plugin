<?php
/**
 * Plugin container.
 *
 * @package CekEmail
 */

namespace CekEmail;

use CekEmail\Integrations\CF7;
use CekEmail\Integrations\Core_Comments;
use CekEmail\Integrations\Core_Registration;
use CekEmail\Integrations\Integration;
use CekEmail\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every plugin service together and registers its hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $api_client;

	/**
	 * Policy engine.
	 *
	 * @var Validator
	 */
	private $validator;

	/**
	 * Recent checks log.
	 *
	 * @var Log
	 */
	private $log;

	/**
	 * Admin notice queue.
	 *
	 * @var Admin_Notices
	 */
	private $admin_notices;

	/**
	 * Front-end widget loader.
	 *
	 * @var Frontend_Widget
	 */
	private $frontend_widget;

	/**
	 * Whether hooks were already registered.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Build the object graph.
	 */
	private function __construct() {
		$this->settings        = new Settings();
		$this->admin_notices   = new Admin_Notices();
		$this->api_client      = new Api_Client( $this->settings );
		$this->validator       = new Validator( $this->settings, $this->api_client, $this->admin_notices );
		$this->log             = new Log();
		$this->frontend_widget = new Frontend_Widget( $this->settings );
	}

	/**
	 * Retrieve the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register every hook the plugin needs.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$this->registered = true;

		$this->settings->register( $this->api_client, $this->log );
		$this->admin_notices->register();
		$this->log->register();
		$this->frontend_widget->register();

		$this->register_integrations();

		do_action( 'cekemail_loaded', $this );
	}

	/**
	 * Settings repository accessor.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * API client accessor.
	 *
	 * @return Api_Client
	 */
	public function api_client(): Api_Client {
		return $this->api_client;
	}

	/**
	 * Policy engine accessor.
	 *
	 * @return Validator
	 */
	public function validator(): Validator {
		return $this->validator;
	}

	/**
	 * Boot the enabled integrations.
	 *
	 * Integration classes receive the settings repository and the validator,
	 * and are only registered when an API key is configured, the integration
	 * toggle is on and the integration reports itself as available.
	 *
	 * @return void
	 */
	private function register_integrations(): void {
		if ( ! $this->settings->has_api_key() ) {
			return;
		}

		/**
		 * Filters the integration classes the plugin boots.
		 *
		 * @param string[] $classes Fully qualified class names implementing Integration.
		 */
		$classes = apply_filters(
			'cekemail_integration_classes',
			array(
				Core_Registration::class,
				Core_Comments::class,
				WooCommerce::class,
				CF7::class,
			)
		);

		foreach ( (array) $classes as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}

			if ( ! is_subclass_of( $class_name, Integration::class ) ) {
				continue;
			}

			$integration = new $class_name( $this->settings, $this->validator );

			if ( ! $integration->is_available() ) {
				continue;
			}

			if ( ! $this->settings->is_integration_enabled( $integration->key() ) ) {
				continue;
			}

			$integration->register();
		}
	}
}
