<?php
/**
 * Contact Form 7 integration.
 *
 * @package CekEmail
 */

namespace CekEmail\Integrations;

use CekEmail\Settings;
use CekEmail\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the address submitted in a Contact Form 7 email field.
 */
class CF7 implements Integration {

	/**
	 * Source slug reported with every check.
	 */
	const SOURCE = 'cf7';

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
	 * Contact Form 7 has to be active.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( 'WPCF7' );
	}

	/**
	 * Register the integration's hooks.
	 *
	 * Contact Form 7 fires `wpcf7_validate_{$type}` for every tag, so both the
	 * optional `email` and the required `email*` field types need a filter.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wpcf7_validate_email', array( $this, 'validate' ), 20, 2 );
		add_filter( 'wpcf7_validate_email*', array( $this, 'validate' ), 20, 2 );
	}

	/**
	 * Invalidate the tag when the address is blocked.
	 *
	 * @param object $result Validation result being built.
	 * @param object $tag    Form tag being validated.
	 * @return object
	 */
	public function validate( $result, $tag ) {
		if ( ! is_object( $result ) || ! is_object( $tag ) || ! isset( $tag->name ) ) {
			return $result;
		}

		$name = (string) $tag->name;

		if ( '' === $name || ! $result->is_valid( $name ) ) {
			return $result;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Contact Form 7 verifies the submission before running its validation filters.
		$posted = isset( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';

		if ( ! is_scalar( $posted ) ) {
			return $result;
		}

		$email = trim( sanitize_email( (string) $posted ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return $result;
		}

		$decision = $this->validator->validate( $email, self::SOURCE );

		if ( 'block' === $decision['action'] ) {
			$result->invalidate( $tag, esc_html( $decision['message'] ) );
		}

		return $result;
	}
}
