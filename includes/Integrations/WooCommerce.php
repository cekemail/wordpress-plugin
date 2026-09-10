<?php
/**
 * WooCommerce integration.
 *
 * @package CekEmail
 */

namespace CekEmail\Integrations;

use CekEmail\Settings;
use CekEmail\Validator;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the address submitted on the WooCommerce checkout and registration forms.
 *
 * Three entry points are covered: the classic shortcode checkout, the My Account
 * registration form, and the block checkout, which posts to the Store API and
 * never fires `woocommerce_checkout_process`.
 */
class WooCommerce implements Integration {

	/**
	 * Source slug reported for the checkout check.
	 */
	const SOURCE_CHECKOUT = 'woocommerce_checkout';

	/**
	 * Source slug reported for the registration check.
	 */
	const SOURCE_REGISTRATION = 'woocommerce_registration';

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
		return 'woocommerce';
	}

	/**
	 * WooCommerce has to be active.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Register the integration's hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout' ) );
		add_action( 'woocommerce_register_post', array( $this, 'validate_registration' ), 20, 3 );
		add_action( 'woocommerce_blocks_validate_location_address_fields', array( $this, 'validate_block_checkout' ), 10, 3 );
	}

	/**
	 * Add a checkout notice when the billing address is blocked.
	 *
	 * @return void
	 */
	public function validate_checkout(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook runs.
		$posted = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';

		$decision = $this->decide( $posted, self::SOURCE_CHECKOUT );

		if ( null === $decision ) {
			return;
		}

		wc_add_notice( esc_html( $decision['message'] ), 'error' );
	}

	/**
	 * Add a registration error when the address is blocked.
	 *
	 * @param string   $username         Submitted username.
	 * @param string   $email            Submitted address.
	 * @param WP_Error $validation_error Errors collected so far.
	 * @return void
	 */
	public function validate_registration( $username, $email, $validation_error ): void {
		if ( ! is_wp_error( $validation_error ) ) {
			return;
		}

		$decision = $this->decide( is_string( $email ) ? $email : '', self::SOURCE_REGISTRATION );

		if ( null === $decision ) {
			return;
		}

		$validation_error->add( 'cekemail_invalid_email', esc_html( $decision['message'] ) );
	}

	/**
	 * Add a block checkout error when the billing address is blocked.
	 *
	 * The Store API runs this action while it validates the posted address, long
	 * before an order exists, and turns the collected errors into a per field
	 * response the block checkout shows next to the email input. The email is
	 * part of the billing address only, so the shipping group is skipped.
	 *
	 * @param WP_Error $errors Errors collected for this address.
	 * @param array    $fields Posted address fields.
	 * @param string   $group  Address group, `billing` or `shipping`.
	 * @return void
	 */
	public function validate_block_checkout( $errors, $fields, $group ): void {
		if ( ! is_wp_error( $errors ) || 'billing' !== $group || ! is_array( $fields ) ) {
			return;
		}

		$email = isset( $fields['email'] ) ? sanitize_email( (string) $fields['email'] ) : '';

		$decision = $this->decide( $email, self::SOURCE_CHECKOUT );

		if ( null === $decision ) {
			return;
		}

		$errors->add( 'cekemail_invalid_email', esc_html( $decision['message'] ), array( 'key' => 'email' ) );
	}

	/**
	 * Verify an address, unless WordPress can already tell it is malformed.
	 *
	 * @param string $email  Address to verify.
	 * @param string $source Integration slug reported with the check.
	 * @return array{action: string, message: string}|null Decision when the address is blocked, null otherwise.
	 */
	private function decide( string $email, string $source ): ?array {
		$email = trim( $email );

		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}

		$decision = $this->validator->validate( $email, $source );

		return 'block' === $decision['action'] ? $decision : null;
	}
}
