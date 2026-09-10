<?php
/**
 * Registration integration tests.
 *
 * @package CekEmail
 */

namespace CekEmail\Tests;

use Brain\Monkey\Functions;
use CekEmail\Integrations\Core_Registration;
use CekEmail\Settings;
use CekEmail\Validator;
use Mockery;
use WP_Error;

/**
 * Covers the registration_errors filter.
 */
class CoreRegistrationTest extends TestCase {

	/**
	 * Build the integration with a validator returning a fixed decision.
	 *
	 * @param array|null $decision Decision the validator returns, or null when it must not be called.
	 * @return Core_Registration
	 */
	private function make_integration( ?array $decision ): Core_Registration {
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
			}
		);

		$validator = Mockery::mock( Validator::class );

		if ( null === $decision ) {
			$validator->shouldNotReceive( 'validate' );
		} else {
			$validator->shouldReceive( 'validate' )->once()->andReturn( $decision );
		}

		return new Core_Registration( Mockery::mock( Settings::class ), $validator );
	}

	/**
	 * The integration reports its slug and availability.
	 *
	 * @return void
	 */
	public function test_it_describes_itself(): void {
		$integration = $this->make_integration( null );

		$this->assertSame( 'core_registration', $integration->key() );
		$this->assertTrue( $integration->is_available() );
	}

	/**
	 * A blocked address adds a registration error.
	 *
	 * @return void
	 */
	public function test_a_blocked_address_adds_an_error(): void {
		$integration = $this->make_integration(
			array(
				'action'  => 'block',
				'message' => 'This email address cannot receive mail.',
			)
		);

		$errors = $integration->validate( new WP_Error(), 'john', 'john@gmail.com' );

		$this->assertSame( array( 'cekemail_invalid_email' ), $errors->get_error_codes() );
		$this->assertSame( 'This email address cannot receive mail.', $errors->get_error_message( 'cekemail_invalid_email' ) );
	}

	/**
	 * An allowed address is left alone.
	 *
	 * @return void
	 */
	public function test_an_allowed_address_adds_no_error(): void {
		$integration = $this->make_integration(
			array(
				'action'  => 'allow',
				'message' => '',
			)
		);

		$errors = $integration->validate( new WP_Error(), 'john', 'john@gmail.com' );

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * Malformed addresses are left to WordPress.
	 *
	 * @return void
	 */
	public function test_a_malformed_address_is_not_checked(): void {
		$integration = $this->make_integration( null );

		$errors = $integration->validate( new WP_Error(), 'john', 'not-an-address' );

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * Addresses WordPress already rejected are not checked again.
	 *
	 * @return void
	 */
	public function test_an_address_wordpress_already_rejected_is_not_checked(): void {
		$integration = $this->make_integration( null );

		$errors = $integration->validate(
			new WP_Error( 'email_exists', 'This email is already registered.' ),
			'john',
			'john@gmail.com'
		);

		$this->assertSame( array( 'email_exists' ), $errors->get_error_codes() );
	}
}
