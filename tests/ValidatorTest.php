<?php
/**
 * Policy engine tests.
 *
 * @package CekEmail
 */

namespace CekEmail\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use CekEmail\Admin_Notices;
use CekEmail\Api_Client;
use CekEmail\Settings;
use CekEmail\Validator;
use Mockery;

/**
 * Covers every status and policy combination.
 */
class ValidatorTest extends TestCase {

	/**
	 * Build a validator with mocked collaborators.
	 *
	 * @param array         $settings Setting overrides.
	 * @param Admin_Notices $notices  Notice queue.
	 * @param Api_Client    $client   API client.
	 * @return Validator
	 */
	private function make_validator( array $settings, $notices = null, $client = null ): Validator {
		$values = array_merge( Settings::defaults(), $settings );

		$settings_mock = Mockery::mock( Settings::class );
		$settings_mock->shouldReceive( 'get' )->andReturnUsing(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
			}
		);

		$client  = $client ? $client : Mockery::mock( Api_Client::class );
		$notices = $notices ? $notices : Mockery::mock( Admin_Notices::class )->shouldIgnoreMissing();

		return new Validator( $settings_mock, $client, $notices );
	}

	/**
	 * Build a successful API result.
	 *
	 * @param string      $status     Result status.
	 * @param string|null $suggestion Suggested address.
	 * @return array
	 */
	private function success_result( string $status, ?string $suggestion = null ): array {
		return array(
			'ok'          => true,
			'status'      => $status,
			'reason_code' => 'mailbox_exists',
			'reason'      => 'Mailbox exists',
			'suggestion'  => $suggestion,
			'error'       => null,
			'http_code'   => 200,
		);
	}

	/**
	 * Status and policy combinations.
	 *
	 * @return array<string, array{0: string, 1: array, 2: string}>
	 */
	public static function policy_provider(): array {
		return array(
			'valid is always allowed'              => array( 'valid', array( 'on_unknown' => 'block', 'on_catch_all' => 'block' ), 'allow' ),
			'invalid is always blocked'            => array( 'invalid', array( 'on_unknown' => 'allow', 'on_catch_all' => 'allow', 'block_disposable' => false ), 'block' ),
			'disposable blocked when configured'   => array( 'disposable', array( 'block_disposable' => true ), 'block' ),
			'disposable allowed when configured'   => array( 'disposable', array( 'block_disposable' => false ), 'allow' ),
			'catch all follows allow policy'       => array( 'catch_all', array( 'on_catch_all' => 'allow' ), 'allow' ),
			'catch all follows block policy'       => array( 'catch_all', array( 'on_catch_all' => 'block' ), 'block' ),
			'unknown follows allow policy'         => array( 'unknown', array( 'on_unknown' => 'allow' ), 'allow' ),
			'unknown follows block policy'         => array( 'unknown', array( 'on_unknown' => 'block' ), 'block' ),
			'greylisted follows allow policy'      => array( 'greylisted', array( 'on_unknown' => 'allow' ), 'allow' ),
			'greylisted follows block policy'      => array( 'greylisted', array( 'on_unknown' => 'block' ), 'block' ),
			'unrecognised status follows unknown'  => array( 'something_new', array( 'on_unknown' => 'block' ), 'block' ),
		);
	}

	/**
	 * Each status resolves to the configured action.
	 *
	 * @dataProvider policy_provider
	 *
	 * @param string $status   Result status.
	 * @param array  $settings Setting overrides.
	 * @param string $expected Expected action.
	 * @return void
	 */
	public function test_evaluate_applies_the_configured_policy( string $status, array $settings, string $expected ): void {
		$decision = $this->make_validator( $settings )->evaluate( $this->success_result( $status ) );

		$this->assertSame( $expected, $decision['action'] );

		if ( 'block' === $expected ) {
			$this->assertNotSame( '', $decision['message'] );
		} else {
			$this->assertSame( '', $decision['message'] );
		}
	}

	/**
	 * API error policies.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function api_error_provider(): array {
		return array(
			'unauthorized allowed'    => array( 'unauthorized', 'allow', 'allow' ),
			'unauthorized blocked'    => array( 'unauthorized', 'block', 'block' ),
			'credits allowed'         => array( 'insufficient_credits', 'allow', 'allow' ),
			'credits blocked'         => array( 'insufficient_credits', 'block', 'block' ),
			'forbidden allowed'       => array( 'forbidden', 'allow', 'allow' ),
			'rate limited allowed'    => array( 'rate_limited', 'allow', 'allow' ),
			'network blocked'         => array( 'network', 'block', 'block' ),
			'invalid response allowed' => array( 'invalid_response', 'allow', 'allow' ),
		);
	}

	/**
	 * API failures follow the API error policy.
	 *
	 * @dataProvider api_error_provider
	 *
	 * @param string $error    Error slug.
	 * @param string $policy   Configured policy.
	 * @param string $expected Expected action.
	 * @return void
	 */
	public function test_evaluate_applies_the_api_error_policy( string $error, string $policy, string $expected ): void {
		$decision = $this->make_validator( array( 'on_api_error' => $policy ) )->evaluate(
			array(
				'ok'          => false,
				'status'      => null,
				'reason_code' => null,
				'reason'      => null,
				'suggestion'  => null,
				'error'       => $error,
				'http_code'   => 500,
			)
		);

		$this->assertSame( $expected, $decision['action'] );
		$this->assertNotSame( '', $decision['message'] );
	}

	/**
	 * Account level failures raise an admin notice.
	 *
	 * @return void
	 */
	public function test_evaluate_records_a_notice_for_account_level_failures(): void {
		$notices = Mockery::mock( Admin_Notices::class );
		$notices->shouldReceive( 'record' )->once()->with( 'insufficient_credits' );

		$this->make_validator( array(), $notices )->evaluate(
			array(
				'ok'    => false,
				'error' => 'insufficient_credits',
			)
		);

		$this->assertTrue( true );
	}

	/**
	 * Transient failures do not raise an admin notice.
	 *
	 * @return void
	 */
	public function test_evaluate_does_not_record_a_notice_for_transient_failures(): void {
		$notices = Mockery::mock( Admin_Notices::class );
		$notices->shouldNotReceive( 'record' );

		$decision = $this->make_validator( array(), $notices )->evaluate(
			array(
				'ok'    => false,
				'error' => 'network',
			)
		);

		$this->assertSame( 'allow', $decision['action'] );
	}

	/**
	 * The suggestion is appended when enabled.
	 *
	 * @return void
	 */
	public function test_message_includes_the_suggestion_when_enabled(): void {
		$decision = $this->make_validator( array( 'show_suggestion' => true ) )
			->evaluate( $this->success_result( 'invalid', 'user@gmail.com' ) );

		$this->assertSame( 'block', $decision['action'] );
		$this->assertStringContainsString( 'user@gmail.com', $decision['message'] );
	}

	/**
	 * The suggestion is left out when disabled.
	 *
	 * @return void
	 */
	public function test_message_omits_the_suggestion_when_disabled(): void {
		$decision = $this->make_validator( array( 'show_suggestion' => false ) )
			->evaluate( $this->success_result( 'invalid', 'user@gmail.com' ) );

		$this->assertSame( 'block', $decision['action'] );
		$this->assertStringNotContainsString( 'user@gmail.com', $decision['message'] );
	}

	/**
	 * validate() checks the address, fires the action and returns the decision.
	 *
	 * @return void
	 */
	public function test_validate_fires_the_after_check_action_with_a_masked_address(): void {
		$client = Mockery::mock( Api_Client::class );
		$client->shouldReceive( 'check' )->once()->with( 'john@gmail.com' )->andReturn( $this->success_result( 'disposable' ) );

		Actions\expectDone( 'cekemail_after_check' )->once()->with(
			'j***@gmail.com',
			Mockery::type( 'array' ),
			Mockery::on(
				static function ( $decision ) {
					return isset( $decision['action'] ) && 'block' === $decision['action'];
				}
			),
			'core_registration'
		);

		$decision = $this->make_validator( array( 'block_disposable' => true ), null, $client )
			->validate( 'john@gmail.com', 'core_registration' );

		$this->assertSame( 'block', $decision['action'] );
	}

	/**
	 * The decision filter can override the outcome.
	 *
	 * @return void
	 */
	public function test_validate_lets_the_decision_filter_override_the_outcome(): void {
		$client = Mockery::mock( Api_Client::class );
		$client->shouldReceive( 'check' )->once()->andReturn( $this->success_result( 'valid' ) );

		Filters\expectApplied( 'cekemail_decision' )->once()->andReturn(
			array(
				'action'  => 'block',
				'message' => 'Nope.',
			)
		);

		$decision = $this->make_validator( array(), null, $client )->validate( 'john@gmail.com', 'core_comments' );

		$this->assertSame( 'block', $decision['action'] );
		$this->assertSame( 'Nope.', $decision['message'] );
	}
}
