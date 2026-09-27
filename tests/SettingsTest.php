<?php
/**
 * Settings sanitization tests.
 *
 * @package CekEmail
 */

namespace CekEmail\Tests;

use Brain\Monkey\Functions;
use CekEmail\Settings;

/**
 * Covers the sanitize callback and the defaults.
 */
class SettingsTest extends TestCase {

	/**
	 * Stub the WordPress helpers the sanitize callback uses.
	 *
	 * @param array $stored Currently stored settings.
	 * @return Settings
	 */
	private function make_settings( array $stored = array() ): Settings {
		Functions\when( 'get_option' )->justReturn( $stored );
		Functions\when( 'add_settings_error' )->justReturn( null );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url ) {
				return wp_parse_url_stub( $url );
			}
		);

		return new Settings();
	}

	/**
	 * Every default is applied when nothing is stored.
	 *
	 * @return void
	 */
	public function test_defaults_are_applied(): void {
		$settings = $this->make_settings();

		$this->assertSame( 'https://api.cekemail.com', $settings->get( 'api_base_url' ) );
		$this->assertTrue( $settings->get( 'block_disposable' ) );
		$this->assertSame( 'allow', $settings->get( 'on_catch_all' ) );
		$this->assertSame( 3600, $settings->get( 'cache_ttl' ) );
		$this->assertSame( 15, $settings->get( 'request_timeout' ) );
		$this->assertFalse( $settings->has_api_key() );
		$this->assertTrue( $settings->is_integration_enabled( 'core_registration' ) );
		$this->assertFalse( $settings->is_integration_enabled( 'unknown_integration' ) );
	}

	/**
	 * A blank key keeps the stored one.
	 *
	 * @return void
	 */
	public function test_a_blank_api_key_keeps_the_stored_key(): void {
		$settings = $this->make_settings( array( 'api_key' => 'stored-token' ) );

		$output = $settings->sanitize( array( 'api_key' => '' ) );

		$this->assertSame( 'stored-token', $output['api_key'] );
	}

	/**
	 * A submitted key replaces the stored one and is trimmed.
	 *
	 * @return void
	 */
	public function test_a_submitted_api_key_replaces_the_stored_key(): void {
		$settings = $this->make_settings( array( 'api_key' => 'stored-token' ) );

		$output = $settings->sanitize( array( 'api_key' => '  new-token  ' ) );

		$this->assertSame( 'new-token', $output['api_key'] );
	}

	/**
	 * Unusable URLs.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function bad_url_provider(): array {
		return array(
			'empty'         => array( '' ),
			'not a url'     => array( 'not a url' ),
			'scheme only'   => array( 'https://' ),
			'javascript'    => array( 'javascript:alert(1)' ),
			'no scheme'     => array( 'cekemail.com' ),
			'ftp'           => array( 'ftp://cekemail.com' ),
			'plain http'    => array( 'http://api.example.com' ),
		);
	}

	/**
	 * A bad URL falls back to the default.
	 *
	 * @dataProvider bad_url_provider
	 *
	 * @param string $url Submitted URL.
	 * @return void
	 */
	public function test_bad_urls_fall_back_to_the_default( string $url ): void {
		$settings = $this->make_settings();

		$output = $settings->sanitize( array( 'api_base_url' => $url ) );

		$this->assertSame( 'https://api.cekemail.com', $output['api_base_url'] );
	}

	/**
	 * A good URL is kept without its trailing slash.
	 *
	 * @return void
	 */
	public function test_a_good_url_is_kept_without_a_trailing_slash(): void {
		$settings = $this->make_settings();

		$output = $settings->sanitize( array( 'api_base_url' => 'https://api.example.com/base/' ) );

		$this->assertSame( 'https://api.example.com/base', $output['api_base_url'] );
	}

	/**
	 * Local addresses that may use plain http.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function local_http_url_provider(): array {
		return array(
			'localhost' => array( 'http://localhost:8010' ),
			'loopback'  => array( 'http://127.0.0.1:8010' ),
		);
	}

	/**
	 * Plain http is kept for a local instance.
	 *
	 * @dataProvider local_http_url_provider
	 *
	 * @param string $url Submitted URL.
	 * @return void
	 */
	public function test_plain_http_is_kept_for_a_local_instance( string $url ): void {
		$settings = $this->make_settings();

		$output = $settings->sanitize( array( 'api_base_url' => $url ) );

		$this->assertSame( $url, $output['api_base_url'] );
	}

	/**
	 * API URLs and the endpoint URL each one resolves to.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function api_url_provider(): array {
		return array(
			'default api host'      => array( 'https://api.cekemail.com', 'https://api.cekemail.com/v1/email-check' ),
			'api host with slash'   => array( 'https://api.cekemail.com/', 'https://api.cekemail.com/v1/email-check' ),
			'legacy default'        => array( 'https://cekemail.com', 'https://cekemail.com/api/v1/email-check' ),
			'legacy with slash'     => array( 'https://cekemail.com/', 'https://cekemail.com/api/v1/email-check' ),
			'self-hosted'           => array( 'https://mail.example.com', 'https://mail.example.com/api/v1/email-check' ),
			'self-hosted with port' => array( 'http://127.0.0.1:8010', 'http://127.0.0.1:8010/api/v1/email-check' ),
		);
	}

	/**
	 * Only an api. host drops the /api prefix.
	 *
	 * @dataProvider api_url_provider
	 *
	 * @param string $base_url Configured API URL.
	 * @param string $expected Expected endpoint URL.
	 * @return void
	 */
	public function test_api_url_adds_the_api_prefix_outside_an_api_host( string $base_url, string $expected ): void {
		$this->assertSame( $expected, Settings::api_url( $base_url, '/v1/email-check' ) );
	}

	/**
	 * Cache lifetimes.
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function cache_ttl_provider(): array {
		return array(
			'inside the range'  => array( 900, 900 ),
			'zero disables'     => array( 0, 0 ),
			'below the minimum' => array( 10, 60 ),
			'above the maximum' => array( 99999999, 604800 ),
			'negative'          => array( -50, 60 ),
			'not a number'      => array( 'soon', 3600 ),
		);
	}

	/**
	 * The cache lifetime is clamped.
	 *
	 * @dataProvider cache_ttl_provider
	 *
	 * @param mixed $value    Submitted lifetime.
	 * @param int   $expected Expected lifetime.
	 * @return void
	 */
	public function test_the_cache_lifetime_is_clamped( $value, int $expected ): void {
		$settings = $this->make_settings();

		$output = $settings->sanitize( array( 'cache_ttl' => $value ) );

		$this->assertSame( $expected, $output['cache_ttl'] );
	}

	/**
	 * Request timeouts.
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function request_timeout_provider(): array {
		return array(
			'inside the range'  => array( 20, 20 ),
			'below the minimum' => array( 1, 5 ),
			'above the maximum' => array( 99, 30 ),
			'zero'              => array( 0, 5 ),
			'not a number'      => array( 'fast', 15 ),
			'not submitted'     => array( null, 15 ),
		);
	}

	/**
	 * The request timeout is clamped.
	 *
	 * @dataProvider request_timeout_provider
	 *
	 * @param mixed $value    Submitted timeout.
	 * @param int   $expected Expected timeout.
	 * @return void
	 */
	public function test_the_request_timeout_is_clamped( $value, int $expected ): void {
		$settings = $this->make_settings();

		$output = $settings->sanitize( array( 'request_timeout' => $value ) );

		$this->assertSame( $expected, $output['request_timeout'] );
	}

	/**
	 * Unknown integration keys are dropped and missing ones become false.
	 *
	 * @return void
	 */
	public function test_unknown_integration_keys_are_dropped(): void {
		$settings = $this->make_settings();

		$output = $settings->sanitize(
			array(
				'integrations' => array(
					'core_registration' => '1',
					'evil_integration'  => '1',
				),
			)
		);

		$this->assertSame(
			array( 'core_registration', 'core_comments', 'woocommerce', 'cf7' ),
			array_keys( $output['integrations'] )
		);
		$this->assertTrue( $output['integrations']['core_registration'] );
		$this->assertFalse( $output['integrations']['core_comments'] );
		$this->assertArrayNotHasKey( 'evil_integration', $output['integrations'] );
	}

	/**
	 * Policy fields only accept allow or block.
	 *
	 * @return void
	 */
	public function test_policy_fields_reject_unknown_values(): void {
		$settings = $this->make_settings();

		$output = $settings->sanitize(
			array(
				'on_catch_all'  => 'block',
				'on_unknown'    => 'quarantine',
				'on_api_error'  => '<script>allow</script>',
			)
		);

		$this->assertSame( 'block', $output['on_catch_all'] );
		$this->assertSame( 'allow', $output['on_unknown'] );
		$this->assertSame( 'allow', $output['on_api_error'] );
	}

	/**
	 * Unchecked boxes become false and text fields are sanitized.
	 *
	 * @return void
	 */
	public function test_checkboxes_and_text_fields_are_normalised(): void {
		$settings = $this->make_settings();

		$output = $settings->sanitize(
			array(
				'block_disposable' => '1',
				'widget_key'       => '<b>pk_live_123</b>',
			)
		);

		$this->assertTrue( $output['block_disposable'] );
		$this->assertFalse( $output['show_suggestion'] );
		$this->assertFalse( $output['widget_enabled'] );
		$this->assertSame( 'pk_live_123', $output['widget_key'] );
	}
}

/**
 * URL parser used by the wp_parse_url stub.
 *
 * @param string $url URL to parse.
 * @return array|false
 */
function wp_parse_url_stub( $url ) {
	return parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}
