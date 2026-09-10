<?php
/**
 * WooCommerce integration tests.
 *
 * @package CekEmail
 */

namespace CekEmail\Tests;

use Brain\Monkey\Functions;
use CekEmail\Integrations\WooCommerce;
use CekEmail\Settings;
use CekEmail\Validator;
use Mockery;
use WP_Error;

/**
 * Covers the classic checkout, the My Account registration form and the block checkout.
 */
class WooCommerceTest extends TestCase {

	/**
	 * Stub the WordPress helpers the integration calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);

		Functions\when( 'sanitize_email' )->alias(
			static function ( $email ) {
				return trim( (string) $email );
			}
		);
	}

	/**
	 * Drop the posted value between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_POST['billing_email'] );

		parent::tearDown();
	}

	/**
	 * Build the integration with a validator returning a fixed decision.
	 *
	 * @param array|null $decision Decision the validator returns, or null when it must not be called.
	 * @return WooCommerce
	 */
	private function make_integration( ?array $decision ): WooCommerce {
		$validator = Mockery::mock( Validator::class );

		if ( null === $decision ) {
			$validator->shouldNotReceive( 'validate' );
		} else {
			$validator->shouldReceive( 'validate' )->once()->andReturn( $decision );
		}

		return new WooCommerce( Mockery::mock( Settings::class ), $validator );
	}

	/**
	 * A blocked decision, for readability.
	 *
	 * @return array{action: string, message: string}
	 */
	private function blocked(): array {
		return array(
			'action'  => 'block',
			'message' => 'Disposable email addresses are not accepted.',
		);
	}

	/**
	 * An allowed decision, for readability.
	 *
	 * @return array{action: string, message: string}
	 */
	private function allowed(): array {
		return array(
			'action'  => 'allow',
			'message' => '',
		);
	}

	/**
	 * Collect the notices WooCommerce would have shown.
	 *
	 * @return object
	 */
	private function capture_notices(): object {
		$notices = new class() {

			/**
			 * Recorded notices.
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			public $all = array();
		};

		Functions\when( 'wc_add_notice' )->alias(
			static function ( $message, $type = 'success' ) use ( $notices ) {
				$notices->all[] = array( $message, $type );
			}
		);

		return $notices;
	}

	/**
	 * The integration reports its slug and availability.
	 *
	 * @return void
	 */
	public function test_it_describes_itself(): void {
		$integration = $this->make_integration( null );

		$this->assertSame( 'woocommerce', $integration->key() );
		$this->assertTrue( $integration->is_available() );
	}

	/**
	 * A blocked checkout address adds an error notice.
	 *
	 * @return void
	 */
	public function test_a_blocked_checkout_address_adds_a_notice(): void {
		$_POST['billing_email'] = 'john@mailinator.com';

		$notices = $this->capture_notices();

		$this->make_integration( $this->blocked() )->validate_checkout();

		$this->assertSame( array( array( 'Disposable email addresses are not accepted.', 'error' ) ), $notices->all );
	}

	/**
	 * An allowed checkout address adds no notice.
	 *
	 * @return void
	 */
	public function test_an_allowed_checkout_address_adds_no_notice(): void {
		$_POST['billing_email'] = 'john@gmail.com';

		$notices = $this->capture_notices();

		$this->make_integration( $this->allowed() )->validate_checkout();

		$this->assertSame( array(), $notices->all );
	}

	/**
	 * A malformed checkout address is left to WooCommerce.
	 *
	 * @return void
	 */
	public function test_a_malformed_checkout_address_is_not_checked(): void {
		$_POST['billing_email'] = 'not-an-address';

		$notices = $this->capture_notices();

		$this->make_integration( null )->validate_checkout();

		$this->assertSame( array(), $notices->all );
	}

	/**
	 * A blocked registration address adds a registration error.
	 *
	 * @return void
	 */
	public function test_a_blocked_registration_address_adds_an_error(): void {
		$errors = new WP_Error();

		$this->make_integration( $this->blocked() )->validate_registration( 'john', 'john@mailinator.com', $errors );

		$this->assertSame( array( 'cekemail_invalid_email' ), $errors->get_error_codes() );
		$this->assertSame( 'Disposable email addresses are not accepted.', $errors->get_error_message( 'cekemail_invalid_email' ) );
	}

	/**
	 * An allowed registration address adds no error.
	 *
	 * @return void
	 */
	public function test_an_allowed_registration_address_adds_no_error(): void {
		$errors = new WP_Error();

		$this->make_integration( $this->allowed() )->validate_registration( 'john', 'john@gmail.com', $errors );

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * A blocked billing address adds a block checkout error.
	 *
	 * @return void
	 */
	public function test_a_blocked_block_checkout_address_adds_an_error(): void {
		$errors = new WP_Error();

		$this->make_integration( $this->blocked() )->validate_block_checkout(
			$errors,
			array( 'email' => 'john@mailinator.com' ),
			'billing'
		);

		$this->assertSame( array( 'cekemail_invalid_email' ), $errors->get_error_codes() );
	}

	/**
	 * The shipping address carries no email, so it is skipped.
	 *
	 * @return void
	 */
	public function test_the_shipping_address_group_is_skipped(): void {
		$errors = new WP_Error();

		$this->make_integration( null )->validate_block_checkout(
			$errors,
			array( 'email' => 'john@mailinator.com' ),
			'shipping'
		);

		$this->assertFalse( $errors->has_errors() );
	}
}
