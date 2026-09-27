<?php
/**
 * API client tests.
 *
 * @package CekEmail
 */

namespace CekEmail\Tests;

use Brain\Monkey\Functions;
use CekEmail\Admin_Notices;
use CekEmail\Api_Client;
use CekEmail\Settings;
use Mockery;
use WP_Error;

/**
 * Covers response normalisation and caching.
 */
class ApiClientTest extends TestCase {

	/**
	 * Build a client with mocked settings.
	 *
	 * @param array              $settings Setting overrides.
	 * @param Admin_Notices|null $notices  Admin notice queue.
	 * @return Api_Client
	 */
	private function make_client( array $settings = array(), ?Admin_Notices $notices = null ): Api_Client {
		$values = array_merge(
			Settings::defaults(),
			array( 'api_key' => 'test-token' ),
			$settings
		);

		$settings_mock = Mockery::mock( Settings::class );
		$settings_mock->shouldReceive( 'get' )->andReturnUsing(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
			}
		);
		$settings_mock->shouldReceive( 'has_api_key' )->andReturnUsing(
			static function () use ( $values ) {
				return '' !== (string) $values['api_key'];
			}
		);

		return new Api_Client( $settings_mock, $notices );
	}

	/**
	 * Stub the transient helpers so nothing is cached.
	 *
	 * @return void
	 */
	private function stub_empty_cache(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
	}

	/**
	 * Stub a JSON response.
	 *
	 * @param int   $code HTTP status code.
	 * @param mixed $body Response body, encoded as JSON when not a string.
	 * @return void
	 */
	private function stub_response( int $code, $body ): void {
		Functions\when( 'wp_remote_post' )->justReturn( array( 'response' => array( 'code' => $code ) ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( is_string( $body ) ? $body : json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * A successful body as the API returns it.
	 *
	 * @return array
	 */
	private function success_body(): array {
		return array(
			'success' => true,
			'data'    => array(
				'input'       => 'john@gmail.com',
				'status'      => 'valid',
				'reason'      => 'Mailbox exists',
				'reason_code' => 'mailbox_exists',
				'suggestion'  => null,
			),
			'meta'    => array( 'request_id' => 'abc' ),
		);
	}

	/**
	 * A 200 response is normalised.
	 *
	 * @return void
	 */
	public function test_check_normalises_a_successful_response(): void {
		$this->stub_empty_cache();
		$this->stub_response( 200, $this->success_body() );

		$result = $this->make_client()->check( 'John@Gmail.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'valid', $result['status'] );
		$this->assertSame( 'mailbox_exists', $result['reason_code'] );
		$this->assertSame( 'Mailbox exists', $result['reason'] );
		$this->assertNull( $result['suggestion'] );
		$this->assertNull( $result['error'] );
		$this->assertSame( 200, $result['http_code'] );
	}

	/**
	 * The suggestion is carried over when present.
	 *
	 * @return void
	 */
	public function test_check_returns_the_suggestion(): void {
		$this->stub_empty_cache();

		$body                        = $this->success_body();
		$body['data']['status']      = 'invalid';
		$body['data']['suggestion']  = 'john@gmail.com';
		$body['data']['reason_code'] = 'no_mx_records';

		$this->stub_response( 200, $body );

		$result = $this->make_client()->check( 'john@gmial.com' );

		$this->assertSame( 'john@gmail.com', $result['suggestion'] );
		$this->assertSame( 'no_mx_records', $result['reason_code'] );
	}

	/**
	 * Non-string fields in a successful body are dropped.
	 *
	 * @return void
	 */
	public function test_check_ignores_non_string_fields(): void {
		$this->stub_empty_cache();

		$body                        = $this->success_body();
		$body['data']['status']      = array( 'valid' );
		$body['data']['reason_code'] = 42;
		$body['data']['reason']      = array( 'text' => 'Mailbox exists' );

		$this->stub_response( 200, $body );

		$result = $this->make_client()->check( 'john@gmail.com' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'unknown', $result['status'] );
		$this->assertNull( $result['reason_code'] );
		$this->assertNull( $result['reason'] );
	}

	/**
	 * A successful request clears every pending admin notice.
	 *
	 * @return void
	 */
	public function test_a_successful_check_clears_the_admin_notices(): void {
		$this->stub_empty_cache();
		$this->stub_response( 200, $this->success_body() );

		$notices = Mockery::mock( Admin_Notices::class );
		$notices->shouldReceive( 'clear_all' )->once();

		$this->assertTrue( $this->make_client( array(), $notices )->check( 'john@gmail.com' )['ok'] );
	}

	/**
	 * A failed request leaves the admin notices alone.
	 *
	 * @return void
	 */
	public function test_a_failed_check_keeps_the_admin_notices(): void {
		$this->stub_empty_cache();
		$this->stub_response( 401, array( 'message' => 'Unauthenticated.' ) );

		$notices = Mockery::mock( Admin_Notices::class );
		$notices->shouldNotReceive( 'clear_all' );

		$this->assertFalse( $this->make_client( array(), $notices )->check( 'john@gmail.com' )['ok'] );
	}

	/**
	 * A cached result proves nothing about the key, so the notices stay.
	 *
	 * @return void
	 */
	public function test_a_cached_result_keeps_the_admin_notices(): void {
		$cached = $this->success_body()['data'];

		Functions\when( 'get_transient' )->justReturn(
			array(
				'ok'          => true,
				'status'      => $cached['status'],
				'reason_code' => $cached['reason_code'],
				'reason'      => $cached['reason'],
				'suggestion'  => null,
				'error'       => null,
				'http_code'   => 200,
			)
		);
		Functions\expect( 'wp_remote_post' )->never();

		$notices = Mockery::mock( Admin_Notices::class );
		$notices->shouldNotReceive( 'clear_all' );

		$this->assertTrue( $this->make_client( array(), $notices )->check( 'john@gmail.com' )['ok'] );
	}

	/**
	 * A successful connection test clears the key notices but not the credits notice.
	 *
	 * @return void
	 */
	public function test_a_successful_connection_test_clears_the_key_notices(): void {
		$this->stub_response( 200, $this->success_body() );

		$notices = Mockery::mock( Admin_Notices::class );
		$notices->shouldReceive( 'clear' )->once()->with( 'unauthorized' );
		$notices->shouldReceive( 'clear' )->once()->with( 'forbidden' );
		$notices->shouldNotReceive( 'clear' )->with( 'insufficient_credits' );
		$notices->shouldNotReceive( 'clear_all' );

		$this->assertTrue( $this->make_client( array(), $notices )->test_connection()['ok'] );
	}

	/**
	 * The request carries the token, the user agent and the timeout.
	 *
	 * @return void
	 */
	public function test_check_sends_an_authenticated_request(): void {
		$this->stub_empty_cache();

		Functions\expect( 'wp_remote_post' )->once()->with(
			'https://cekemail.com/api/v1/email-check',
			Mockery::on(
				static function ( $args ) {
					return 15 === $args['timeout']
						&& 'Bearer test-token' === $args['headers']['Authorization']
						&& 'application/json' === $args['headers']['Content-Type']
						&& 'CekEmail-WordPress/1.0.0 (WordPress/6.7)' === $args['user-agent']
						&& '{"email":"john@gmail.com"}' === $args['body'];
				}
			)
		)->andReturn( array() );

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $this->success_body() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$result = $this->make_client( array( 'api_base_url' => 'https://cekemail.com/' ) )->check( ' John@Gmail.com ' );

		$this->assertTrue( $result['ok'] );
	}

	/**
	 * The configured timeout replaces the default one.
	 *
	 * @return void
	 */
	public function test_check_uses_the_configured_timeout(): void {
		$this->stub_empty_cache();

		Functions\expect( 'wp_remote_post' )->once()->with(
			'https://api.cekemail.com/v1/email-check',
			Mockery::on(
				static function ( $args ) {
					return is_array( $args ) && 25 === $args['timeout'];
				}
			)
		)->andReturn( array() );

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $this->success_body() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$result = $this->make_client( array( 'request_timeout' => 25 ) )->check( 'john@gmail.com' );

		$this->assertTrue( $result['ok'] );
	}

	/**
	 * API URLs and the endpoint each one posts to.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function endpoint_provider(): array {
		return array(
			'default api host' => array( 'https://api.cekemail.com', 'https://api.cekemail.com/v1/email-check' ),
			'legacy default'   => array( 'https://cekemail.com', 'https://cekemail.com/api/v1/email-check' ),
			'self-hosted'      => array( 'https://mail.example.com/', 'https://mail.example.com/api/v1/email-check' ),
		);
	}

	/**
	 * The check is posted to the endpoint for the configured API URL.
	 *
	 * @dataProvider endpoint_provider
	 *
	 * @param string $base_url Configured API URL.
	 * @param string $expected Expected endpoint URL.
	 * @return void
	 */
	public function test_check_posts_to_the_endpoint_for_the_configured_url( string $base_url, string $expected ): void {
		$this->stub_empty_cache();

		Functions\expect( 'wp_remote_post' )->once()->with( $expected, Mockery::type( 'array' ) )->andReturn( array() );

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $this->success_body() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$result = $this->make_client( array( 'api_base_url' => $base_url ) )->check( 'john@gmail.com' );

		$this->assertTrue( $result['ok'] );
	}

	/**
	 * HTTP failures map onto the plugin's error vocabulary.
	 *
	 * @return array<string, array{0: int, 1: array, 2: string}>
	 */
	public static function http_error_provider(): array {
		return array(
			'unauthorized' => array( 401, array( 'message' => 'Unauthenticated.' ), 'unauthorized' ),
			'credits'      => array( 402, array( 'error' => 'insufficient_credits' ), 'insufficient_credits' ),
			'scope'        => array( 403, array( 'error' => 'insufficient_scope' ), 'forbidden' ),
			'ip'           => array( 403, array( 'error' => 'ip_not_allowed' ), 'forbidden' ),
			'validation'   => array( 422, array( 'message' => 'The email field is required.' ), 'invalid_response' ),
			'rate limit'   => array( 429, array( 'message' => 'Too Many Attempts.' ), 'rate_limited' ),
			'server error' => array( 500, array( 'message' => 'Server Error' ), 'invalid_response' ),
		);
	}

	/**
	 * Each HTTP failure produces the matching error slug.
	 *
	 * @dataProvider http_error_provider
	 *
	 * @param int    $code     HTTP status code.
	 * @param array  $body     Response body.
	 * @param string $expected Expected error slug.
	 * @return void
	 */
	public function test_check_maps_http_failures( int $code, array $body, string $expected ): void {
		$this->stub_empty_cache();
		$this->stub_response( $code, $body );

		$result = $this->make_client()->check( 'john@gmail.com' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( $expected, $result['error'] );
		$this->assertSame( $code, $result['http_code'] );
		$this->assertNull( $result['status'] );
	}

	/**
	 * Transport failures are reported as network errors.
	 *
	 * @return void
	 */
	public function test_check_reports_transport_failures(): void {
		$this->stub_empty_cache();

		Functions\when( 'wp_remote_post' )->justReturn( new WP_Error( 'http_request_failed', 'Connection timed out' ) );

		$result = $this->make_client()->check( 'john@gmail.com' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'network', $result['error'] );
		$this->assertNull( $result['http_code'] );
	}

	/**
	 * A body that is not the documented envelope is rejected.
	 *
	 * @return void
	 */
	public function test_check_rejects_an_unexpected_body(): void {
		$this->stub_empty_cache();
		$this->stub_response( 200, '<html>maintenance</html>' );

		$result = $this->make_client()->check( 'john@gmail.com' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'invalid_response', $result['error'] );
	}

	/**
	 * Without a key no request is made.
	 *
	 * @return void
	 */
	public function test_check_without_an_api_key_does_not_call_the_api(): void {
		$this->stub_empty_cache();

		Functions\expect( 'wp_remote_post' )->never();

		$result = $this->make_client( array( 'api_key' => '' ) )->check( 'john@gmail.com' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unauthorized', $result['error'] );
	}

	/**
	 * A cached result short circuits the request.
	 *
	 * @return void
	 */
	public function test_check_returns_a_cached_result(): void {
		$cached = array(
			'ok'          => true,
			'status'      => 'valid',
			'reason_code' => 'mailbox_exists',
			'reason'      => 'Mailbox exists',
			'suggestion'  => null,
			'error'       => null,
			'http_code'   => 200,
		);

		Functions\expect( 'get_transient' )
			->once()
			->with( 'cekemail_' . hash( 'sha256', 'john@gmail.com' ) )
			->andReturn( $cached );

		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( $cached, $this->make_client()->check( 'John@Gmail.com' ) );
	}

	/**
	 * Successful results are cached for the configured lifetime.
	 *
	 * @return void
	 */
	public function test_check_caches_successful_results(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		$this->stub_response( 200, $this->success_body() );

		Functions\expect( 'set_transient' )
			->once()
			->with( 'cekemail_' . hash( 'sha256', 'john@gmail.com' ), Mockery::type( 'array' ), 900 );

		$result = $this->make_client( array( 'cache_ttl' => 900 ) )->check( 'john@gmail.com' );

		$this->assertTrue( $result['ok'] );
	}

	/**
	 * Failures are never cached.
	 *
	 * @return void
	 */
	public function test_check_does_not_cache_failures(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->never();

		$this->stub_response( 401, array( 'message' => 'Unauthenticated.' ) );

		$result = $this->make_client()->check( 'john@gmail.com' );

		$this->assertFalse( $result['ok'] );
	}

	/**
	 * A zero lifetime disables the cache entirely.
	 *
	 * @return void
	 */
	public function test_check_skips_the_cache_when_the_lifetime_is_zero(): void {
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->stub_response( 200, $this->success_body() );

		$result = $this->make_client( array( 'cache_ttl' => 0 ) )->check( 'john@gmail.com' );

		$this->assertTrue( $result['ok'] );
	}

	/**
	 * The probe reports success without spending credits.
	 *
	 * @return void
	 */
	public function test_test_connection_reports_success(): void {
		$body                        = $this->success_body();
		$body['data']['status']      = 'invalid';
		$body['data']['reason_code'] = 'no_mx_records';

		Functions\expect( 'wp_remote_post' )->once()->with(
			'https://api.cekemail.com/v1/email-check',
			Mockery::on(
				static function ( $args ) {
					return '{"email":"nobody@example.invalid"}' === $args['body'];
				}
			)
		)->andReturn( array() );

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $body ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		Functions\expect( 'set_transient' )->never();

		$result = $this->make_client()->test_connection();

		$this->assertTrue( $result['ok'] );
		$this->assertNotSame( '', $result['message'] );
	}

	/**
	 * The probe reports a rejected key.
	 *
	 * @return void
	 */
	public function test_test_connection_reports_a_rejected_key(): void {
		$this->stub_response( 401, array( 'message' => 'Unauthenticated.' ) );

		$result = $this->make_client()->test_connection();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unauthorized', $result['error'] );
	}

	/**
	 * The probe asks for a key before calling the API.
	 *
	 * @return void
	 */
	public function test_test_connection_requires_an_api_key(): void {
		Functions\expect( 'wp_remote_post' )->never();

		$result = $this->make_client( array( 'api_key' => '' ) )->test_connection();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unauthorized', $result['error'] );
	}

	/**
	 * The health endpoint is read without authentication.
	 *
	 * @return void
	 */
	public function test_health_reports_the_service_state(): void {
		Functions\expect( 'wp_remote_get' )->once()->with(
			'https://api.cekemail.com/v1/health',
			Mockery::on(
				static function ( $args ) {
					return ! isset( $args['headers']['Authorization'] );
				}
			)
		)->andReturn( array() );

		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"status":"ok"}' );

		$this->assertTrue( $this->make_client()->health() );
	}

	/**
	 * A degraded service reports false.
	 *
	 * @return void
	 */
	public function test_health_reports_a_degraded_service(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array() );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 503 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"status":"degraded"}' );

		$this->assertFalse( $this->make_client()->health() );
	}

	/**
	 * Addresses used for logging.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function mask_provider(): array {
		return array(
			'regular address'  => array( 'john@gmail.com', 'j***@gmail.com' ),
			'one character'    => array( 'a@b.com', '***@b.com' ),
			'two characters'   => array( 'ab@b.com', '***@b.com' ),
			'three characters' => array( 'abc@b.com', 'a***@b.com' ),
			'no at sign'       => array( 'not-an-address', '***' ),
			'empty'            => array( '', '' ),
		);
	}

	/**
	 * Masking never leaks the local part.
	 *
	 * @dataProvider mask_provider
	 *
	 * @param string $email    Address to mask.
	 * @param string $expected Expected masked form.
	 * @return void
	 */
	public function test_mask_email( string $email, string $expected ): void {
		$this->assertSame( $expected, Api_Client::mask_email( $email ) );
	}
}
