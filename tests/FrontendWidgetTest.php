<?php
/**
 * Front-end widget tests.
 *
 * @package CekEmail
 */

namespace CekEmail\Tests;

use Brain\Monkey\Functions;
use CekEmail\Frontend_Widget;
use CekEmail\Settings;
use Mockery;

/**
 * Covers the wp_enqueue_scripts callback and the globals handed to the widget.
 */
class FrontendWidgetTest extends TestCase {

	/**
	 * Stub the WordPress helpers the loader calls.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'plugins_url' )->alias(
			static function ( $path ) {
				return 'https://example.com/wp-content/plugins/cekemail-email-validation/' . $path;
			}
		);
	}

	/**
	 * Build the loader over a settings repository returning fixed values.
	 *
	 * @param array<string, mixed> $settings Setting values.
	 * @return Frontend_Widget
	 */
	private function make_widget( array $settings ): Frontend_Widget {
		$repository = Mockery::mock( Settings::class );

		$repository->shouldReceive( 'get' )->andReturnUsing(
			static function ( $key ) use ( $settings ) {
				return $settings[ $key ] ?? null;
			}
		);

		return new Frontend_Widget( $repository );
	}

	/**
	 * Settings with the widget switched on.
	 *
	 * @return array<string, mixed>
	 */
	private function enabled_settings(): array {
		return array(
			'widget_enabled' => true,
			'widget_key'     => 'wk_live_123',
			'api_base_url'   => 'https://cekemail.com/',
		);
	}

	/**
	 * Collect the handles that were enqueued.
	 *
	 * @return object
	 */
	private function capture_enqueues(): object {
		$enqueued = new class() {

			/**
			 * Recorded handles.
			 *
			 * @var string[]
			 */
			public $all = array();
		};

		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( $handle ) use ( $enqueued ) {
				$enqueued->all[] = $handle;
			}
		);

		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle ) use ( $enqueued ) {
				$enqueued->all[] = $handle;
			}
		);

		return $enqueued;
	}

	/**
	 * The script is enqueued in the footer with the plugin version.
	 *
	 * @return void
	 */
	public function test_it_enqueues_the_bundled_script(): void {
		$enqueued = array();

		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( ...$args ) use ( &$enqueued ) {
				$enqueued = $args;
			}
		);

		Functions\when( 'wp_add_inline_script' )->justReturn( true );

		$this->make_widget( $this->enabled_settings() )->enqueue();

		$this->assertSame(
			array(
				'cekemail-widget',
				'https://example.com/wp-content/plugins/cekemail-email-validation/assets/js/cekemail-widget.min.js',
				array(),
				CEKEMAIL_VERSION,
				true,
			),
			$enqueued
		);
	}

	/**
	 * The globals the widget reads are printed before the script.
	 *
	 * @return void
	 */
	public function test_it_sets_the_widget_globals(): void {
		Functions\when( 'wp_enqueue_script' )->justReturn( true );

		$script = '';

		$position = '';
		$handle   = '';

		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $script_handle, $data, $script_position = 'after' ) use ( &$script, &$handle, &$position ) {
				$handle   = $script_handle;
				$script   = $data;
				$position = $script_position;
			}
		);

		$this->make_widget( $this->enabled_settings() )->enqueue();

		$this->assertSame( 'cekemail-widget', $handle );
		$this->assertSame( 'before', $position );
		$this->assertStringContainsString( 'window.CekEmail_APIKEY = "wk_live_123";', $script );
		$this->assertStringContainsString( 'window.CekEmail_API_URL = "https:\/\/cekemail.com\/api\/v1\/widget\/email-check";', $script );
		$this->assertStringContainsString( 'window.CekEmail_LOCALE = "en";', $script );
		$this->assertStringContainsString( 'window.CekEmail_MESSAGES = {};', $script );
	}

	/**
	 * The default API URL points the widget at the api. host without the /api prefix.
	 *
	 * @return void
	 */
	public function test_the_default_api_url_uses_the_api_host(): void {
		Functions\when( 'wp_enqueue_script' )->justReturn( true );

		$script = '';

		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $script_handle, $data ) use ( &$script ) {
				$script = $data;
			}
		);

		$settings                 = $this->enabled_settings();
		$settings['api_base_url'] = Settings::defaults()['api_base_url'];

		$this->make_widget( $settings )->enqueue();

		$this->assertStringContainsString( 'window.CekEmail_API_URL = "https:\/\/api.cekemail.com\/v1\/widget\/email-check";', $script );
	}

	/**
	 * Indonesian sites get the Indonesian widget messages.
	 *
	 * @return void
	 */
	public function test_an_indonesian_site_gets_the_indonesian_locale(): void {
		Functions\when( 'get_locale' )->justReturn( 'id_ID' );
		Functions\when( 'wp_enqueue_script' )->justReturn( true );

		$script = '';

		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( $handle, $data ) use ( &$script ) {
				$script = $data;

				return true;
			}
		);

		$this->make_widget( $this->enabled_settings() )->enqueue();

		$this->assertStringContainsString( 'window.CekEmail_LOCALE = "id";', $script );
	}

	/**
	 * Nothing loads while the widget is switched off.
	 *
	 * @return void
	 */
	public function test_it_does_nothing_when_the_widget_is_disabled(): void {
		$enqueued = $this->capture_enqueues();

		$this->make_widget(
			array(
				'widget_enabled' => false,
				'widget_key'     => 'wk_live_123',
				'api_base_url'   => 'https://cekemail.com',
			)
		)->enqueue();

		$this->assertSame( array(), $enqueued->all );
	}

	/**
	 * Nothing loads without a widget key.
	 *
	 * @return void
	 */
	public function test_it_does_nothing_without_a_widget_key(): void {
		$enqueued = $this->capture_enqueues();

		$this->make_widget(
			array(
				'widget_enabled' => true,
				'widget_key'     => '   ',
				'api_base_url'   => 'https://cekemail.com',
			)
		)->enqueue();

		$this->assertSame( array(), $enqueued->all );
	}

	/**
	 * Nothing loads in the admin.
	 *
	 * @return void
	 */
	public function test_it_does_nothing_in_the_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		$enqueued = $this->capture_enqueues();

		$this->make_widget( $this->enabled_settings() )->enqueue();

		$this->assertSame( array(), $enqueued->all );
	}
}
