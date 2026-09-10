<?php
/**
 * Shared test case.
 *
 * @package CekEmail
 */

namespace CekEmail\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Boots Brain Monkey and stubs the WordPress helpers the plugin relies on.
 */
abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Set up Brain Monkey and the shared stubs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\when( 'wp_unslash' )->returnArg();

		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);

		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( strip_tags( (string) $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
			}
		);

		Functions\when( 'sanitize_key' )->alias(
			static function ( $value ) {
				return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) );
			}
		);

		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);

		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/\\' );
			}
		);

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);

		Functions\when( 'get_bloginfo' )->justReturn( '6.7' );
	}

	/**
	 * Tear Brain Monkey down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
