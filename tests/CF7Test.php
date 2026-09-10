<?php
/**
 * Contact Form 7 integration tests.
 *
 * @package CekEmail
 */

namespace CekEmail\Tests;

use Brain\Monkey\Functions;
use CekEmail\Integrations\CF7;
use CekEmail\Settings;
use CekEmail\Validator;
use Mockery;
use stdClass;

/**
 * Covers the wpcf7_validate_email filters.
 */
class CF7Test extends TestCase {

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
		unset( $_POST['your-email'] );

		parent::tearDown();
	}

	/**
	 * Build the integration with a validator returning a fixed decision.
	 *
	 * @param array|null $decision Decision the validator returns, or null when it must not be called.
	 * @return CF7
	 */
	private function make_integration( ?array $decision ): CF7 {
		$validator = Mockery::mock( Validator::class );

		if ( null === $decision ) {
			$validator->shouldNotReceive( 'validate' );
		} else {
			$validator->shouldReceive( 'validate' )->once()->andReturn( $decision );
		}

		return new CF7( Mockery::mock( Settings::class ), $validator );
	}

	/**
	 * Stand-in for the WPCF7_Validation object the filter receives.
	 *
	 * @param bool $valid Whether the field is still valid.
	 * @return object
	 */
	private function make_result( bool $valid ): object {
		return new class( $valid ) {

			/**
			 * Whether the field is still valid.
			 *
			 * @var bool
			 */
			public $valid;

			/**
			 * Messages recorded by invalidate().
			 *
			 * @var string[]
			 */
			public $invalidated = array();

			/**
			 * Constructor.
			 *
			 * @param bool $valid Whether the field is still valid.
			 */
			public function __construct( bool $valid ) {
				$this->valid = $valid;
			}

			/**
			 * Whether the named field is still valid.
			 *
			 * @param string|null $name Field name.
			 * @return bool
			 */
			public function is_valid( $name = null ) {
				return $this->valid;
			}

			/**
			 * Record an error for a tag.
			 *
			 * @param object $context Form tag.
			 * @param string $error   Error message.
			 * @return void
			 */
			public function invalidate( $context, $error ) {
				$this->invalidated[ $context->name ] = $error;
			}
		};
	}

	/**
	 * Stand-in for the form tag the filter receives.
	 *
	 * @return stdClass
	 */
	private function make_tag(): stdClass {
		$tag       = new stdClass();
		$tag->name = 'your-email';

		return $tag;
	}

	/**
	 * The integration reports its slug and availability.
	 *
	 * @return void
	 */
	public function test_it_describes_itself(): void {
		$integration = $this->make_integration( null );

		$this->assertSame( 'cf7', $integration->key() );
		$this->assertTrue( $integration->is_available() );
	}

	/**
	 * A blocked address invalidates the tag.
	 *
	 * @return void
	 */
	public function test_a_blocked_address_invalidates_the_tag(): void {
		$_POST['your-email'] = 'john@mailinator.com';

		$result = $this->make_integration(
			array(
				'action'  => 'block',
				'message' => 'Disposable email addresses are not accepted.',
			)
		)->validate( $this->make_result( true ), $this->make_tag() );

		$this->assertSame(
			array( 'your-email' => 'Disposable email addresses are not accepted.' ),
			$result->invalidated
		);
	}

	/**
	 * An allowed address leaves the tag alone.
	 *
	 * @return void
	 */
	public function test_an_allowed_address_leaves_the_tag_alone(): void {
		$_POST['your-email'] = 'john@gmail.com';

		$result = $this->make_integration(
			array(
				'action'  => 'allow',
				'message' => '',
			)
		)->validate( $this->make_result( true ), $this->make_tag() );

		$this->assertSame( array(), $result->invalidated );
	}

	/**
	 * An empty field is left to Contact Form 7.
	 *
	 * @return void
	 */
	public function test_an_empty_field_is_not_checked(): void {
		$result = $this->make_integration( null )->validate( $this->make_result( true ), $this->make_tag() );

		$this->assertSame( array(), $result->invalidated );
	}

	/**
	 * A field Contact Form 7 already rejected is not checked again.
	 *
	 * @return void
	 */
	public function test_an_already_invalid_field_is_not_checked(): void {
		$_POST['your-email'] = 'john@mailinator.com';

		$result = $this->make_integration( null )->validate( $this->make_result( false ), $this->make_tag() );

		$this->assertSame( array(), $result->invalidated );
	}
}
