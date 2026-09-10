<?php
/**
 * WordPress registration integration.
 *
 * @package CekEmail
 */

namespace CekEmail\Integrations;

use CekEmail\Settings;
use CekEmail\Validator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the address submitted on the WordPress registration form.
 */
class Core_Registration implements Integration {

	/**
	 * Source slug reported with every check.
	 */
	const SOURCE = 'core_registration';

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Policy engine.
	 *
	 * @var Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings  Settings repository.
	 * @param Validator $validator Policy engine.
	 */
	public function __construct( Settings $settings, Validator $validator ) {
		$this->settings  = $settings;
		$this->validator = $validator;
	}

	/**
	 * Slug matching the key inside the `integrations` setting.
	 *
	 * @return string
	 */
	public function key(): string {
		return self::SOURCE;
	}

	/**
	 * WordPress registration is always available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Register the integration's hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'registration_errors', array( $this, 'validate' ), 20, 3 );
	}

	/**
	 * Add a registration error when the address is blocked.
	 *
	 * @param WP_Error $errors               Registration errors so far.
	 * @param string   $sanitized_user_login Submitted login.
	 * @param string   $user_email           Submitted address.
	 * @return WP_Error
	 */
	public function validate( $errors, $sanitized_user_login, $user_email ) {
		if ( ! is_wp_error( $errors ) ) {
			return $errors;
		}

		$email = is_string( $user_email ) ? trim( $user_email ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return $errors;
		}

		foreach ( array( 'empty_email', 'invalid_email', 'email_exists' ) as $code ) {
			if ( '' !== $errors->get_error_message( $code ) ) {
				return $errors;
			}
		}

		$decision = $this->validator->validate( $email, self::SOURCE );

		if ( 'block' === $decision['action'] ) {
			$errors->add( 'cekemail_invalid_email', esc_html( $decision['message'] ) );
		}

		return $errors;
	}
}
