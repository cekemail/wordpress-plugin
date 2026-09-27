<?php
/**
 * Uninstall routine tests.
 *
 * @package CekEmail
 */

namespace CekEmail\Tests;

use Brain\Monkey\Functions;

/**
 * Covers uninstall.php.
 */
class UninstallTest extends TestCase {

	/**
	 * Site the stubs currently act on.
	 *
	 * @var int
	 */
	private $site_id = 1;

	/**
	 * Options and transients deleted, prefixed with the site they were deleted on.
	 *
	 * @var string[]
	 */
	private $deleted = array();

	/**
	 * Stub the WordPress helpers the uninstall routine calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
			define( 'WP_UNINSTALL_PLUGIN', 'cekemail-email-validation/cekemail-email-validation.php' );
		}

		Functions\when( 'delete_option' )->alias(
			function ( $option ) {
				$this->deleted[] = $this->site_id . ':option:' . $option;

				return true;
			}
		);

		Functions\when( 'delete_transient' )->alias(
			function ( $transient ) {
				$this->deleted[] = $this->site_id . ':transient:' . $transient;

				return true;
			}
		);

		Functions\when( 'switch_to_blog' )->alias(
			function ( $site_id ) {
				$this->site_id = $site_id;

				return true;
			}
		);

		Functions\when( 'restore_current_blog' )->alias(
			function () {
				$this->site_id = 1;

				return true;
			}
		);

		Functions\expect( 'wp_cache_flush' )->never();
	}

	/**
	 * What the routine deletes on one site.
	 *
	 * @param int $site_id Site ID.
	 * @return string[]
	 */
	private function expected_for_site( int $site_id ): array {
		return array(
			$site_id . ':option:cekemail_settings',
			$site_id . ':option:cekemail_recent_checks',
			$site_id . ':transient:cekemail_notice_unauthorized',
			$site_id . ':transient:cekemail_notice_insufficient_credits',
			$site_id . ':transient:cekemail_notice_forbidden',
		);
	}

	/**
	 * Run uninstall.php.
	 *
	 * @return void
	 */
	private function run_uninstall(): void {
		include CEKEMAIL_PLUGIN_DIR . 'uninstall.php';
	}

	/**
	 * A single site loses its options and notice transients without a cache flush.
	 *
	 * @return void
	 */
	public function test_a_single_site_is_cleaned_up(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'get_sites' )->never();

		$this->run_uninstall();

		$this->assertSame( $this->expected_for_site( 1 ), $this->deleted );
	}

	/**
	 * Every site of a network is cleaned up.
	 *
	 * @return void
	 */
	public function test_every_site_of_a_network_is_cleaned_up(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2 ) );

		$this->run_uninstall();

		$this->assertSame(
			array_merge( $this->expected_for_site( 1 ), $this->expected_for_site( 2 ) ),
			$this->deleted
		);
	}
}
