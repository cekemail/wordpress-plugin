<?php
/**
 * CekEmail API client.
 *
 * @package CekEmail
 */

namespace CekEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the CekEmail REST API and normalises its responses.
 */
class Api_Client {

	/**
	 * Prefix used for the result transients.
	 */
	const CACHE_PREFIX = 'cekemail_';

	/**
	 * Request timeout in seconds, used when the setting is unusable.
	 */
	const TIMEOUT = 15;

	/**
	 * Address used by the zero cost connection probe.
	 */
	const PROBE_EMAIL = 'nobody@example.invalid';

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Verify a single address.
	 *
	 * @param string $email Address to verify.
	 * @return array{ok: bool, status: string|null, reason_code: string|null, reason: string|null, suggestion: string|null, error: string|null, http_code: int|null}
	 */
	public function check( string $email ): array {
		$email = strtolower( trim( $email ) );

		if ( '' === $email ) {
			return $this->error_result( 'invalid_response', null );
		}

		$cache_key = self::CACHE_PREFIX . hash( 'sha256', $email );
		$ttl       = (int) $this->settings->get( 'cache_ttl' );

		if ( $ttl > 0 ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) && isset( $cached['ok'] ) ) {
				return $cached;
			}
		}

		$result = $this->request_check( $email );

		if ( $result['ok'] && $ttl > 0 ) {
			set_transient( $cache_key, $result, $ttl );
		}

		return $result;
	}

	/**
	 * Probe the API with an address that never consumes credits.
	 *
	 * @return array{ok: bool, message: string, error: string|null, http_code: int|null}
	 */
	public function test_connection(): array {
		if ( ! $this->settings->has_api_key() ) {
			return array(
				'ok'        => false,
				'message'   => __( 'Add your API key and save the settings before testing the connection.', 'cekemail-email-validation' ),
				'error'     => 'unauthorized',
				'http_code' => null,
			);
		}

		$result = $this->request_check( self::PROBE_EMAIL );

		if ( $result['ok'] ) {
			return array(
				'ok'        => true,
				'message'   => __( 'Connection successful. Your API key is valid.', 'cekemail-email-validation' ),
				'error'     => null,
				'http_code' => $result['http_code'],
			);
		}

		return array(
			'ok'        => false,
			'message'   => $this->error_message( $result['error'] ),
			'error'     => $result['error'],
			'http_code' => $result['http_code'],
		);
	}

	/**
	 * Ask the unauthenticated health endpoint whether the service is up.
	 *
	 * @return bool
	 */
	public function health(): bool {
		$response = wp_remote_get(
			$this->endpoint( '/v1/health' ),
			array(
				'timeout'    => $this->timeout(),
				'user-agent' => $this->user_agent(),
				'headers'    => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) && isset( $body['status'] ) && 'ok' === $body['status'];
	}

	/**
	 * Mask an address so it can safely be logged.
	 *
	 * @param string $email Address to mask.
	 * @return string
	 */
	public static function mask_email( string $email ): string {
		$email = trim( $email );

		if ( '' === $email ) {
			return '';
		}

		$at = strrpos( $email, '@' );

		if ( false === $at || 0 === $at ) {
			return '***';
		}

		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at );

		return substr( $local, 0, 1 ) . '***' . $domain;
	}

	/**
	 * Perform the verification request and normalise the response.
	 *
	 * @param string $email Address to verify.
	 * @return array{ok: bool, status: string|null, reason_code: string|null, reason: string|null, suggestion: string|null, error: string|null, http_code: int|null}
	 */
	private function request_check( string $email ): array {
		$api_key = (string) $this->settings->get( 'api_key' );

		if ( '' === $api_key ) {
			return $this->error_result( 'unauthorized', null );
		}

		$response = wp_remote_post(
			$this->endpoint( '/v1/email-check' ),
			array(
				'timeout'    => $this->timeout(),
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'       => wp_json_encode( array( 'email' => $email ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_failure( $email, 'network', 0 );

			return $this->error_result( 'network', null );
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $http_code ) {
			if ( ! is_array( $body ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
				$this->log_failure( $email, 'invalid_response', $http_code );

				return $this->error_result( 'invalid_response', $http_code );
			}

			$data = $body['data'];

			return array(
				'ok'          => true,
				'status'      => isset( $data['status'] ) ? (string) $data['status'] : 'unknown',
				'reason_code' => isset( $data['reason_code'] ) ? (string) $data['reason_code'] : null,
				'reason'      => isset( $data['reason'] ) ? (string) $data['reason'] : null,
				'suggestion'  => ( isset( $data['suggestion'] ) && is_string( $data['suggestion'] ) && '' !== $data['suggestion'] ) ? $data['suggestion'] : null,
				'error'       => null,
				'http_code'   => $http_code,
			);
		}

		$error = $this->error_for_code( $http_code, is_array( $body ) ? $body : array() );

		$this->log_failure( $email, $error, $http_code );

		return $this->error_result( $error, $http_code );
	}

	/**
	 * Map an HTTP status onto the plugin's error vocabulary.
	 *
	 * @param int   $http_code HTTP status code.
	 * @param array $body      Decoded response body.
	 * @return string
	 */
	private function error_for_code( int $http_code, array $body ): string {
		if ( isset( $body['error'] ) && 'insufficient_credits' === $body['error'] ) {
			return 'insufficient_credits';
		}

		switch ( $http_code ) {
			case 401:
				return 'unauthorized';
			case 402:
				return 'insufficient_credits';
			case 403:
				return 'forbidden';
			case 429:
				return 'rate_limited';
			default:
				return 'invalid_response';
		}
	}

	/**
	 * Build a normalised failure result.
	 *
	 * @param string   $error     Error slug.
	 * @param int|null $http_code HTTP status code, when there was a response.
	 * @return array{ok: bool, status: string|null, reason_code: string|null, reason: string|null, suggestion: string|null, error: string|null, http_code: int|null}
	 */
	private function error_result( string $error, ?int $http_code ): array {
		return array(
			'ok'          => false,
			'status'      => null,
			'reason_code' => null,
			'reason'      => null,
			'suggestion'  => null,
			'error'       => $error,
			'http_code'   => $http_code,
		);
	}

	/**
	 * Human readable message for an error slug.
	 *
	 * @param string|null $error Error slug.
	 * @return string
	 */
	private function error_message( ?string $error ): string {
		switch ( $error ) {
			case 'unauthorized':
				return __( 'The API key was rejected. Check that you copied the whole key and that it has not been revoked.', 'cekemail-email-validation' );
			case 'insufficient_credits':
				return __( 'Your CekEmail account has run out of credits.', 'cekemail-email-validation' );
			case 'forbidden':
				return __( 'This API key is not allowed to verify email addresses from this site. Check its scopes and IP allowlist.', 'cekemail-email-validation' );
			case 'rate_limited':
				return __( 'Too many requests were sent to the API. Please try again in a minute.', 'cekemail-email-validation' );
			case 'network':
				return __( 'Could not reach the CekEmail API. Check the API URL and your server connectivity.', 'cekemail-email-validation' );
			default:
				return __( 'The API returned an unexpected response.', 'cekemail-email-validation' );
		}
	}

	/**
	 * Absolute endpoint URL for a path.
	 *
	 * @param string $path Versioned endpoint path, leading slash included.
	 * @return string
	 */
	private function endpoint( string $path ): string {
		return Settings::api_url( (string) $this->settings->get( 'api_base_url' ), $path );
	}

	/**
	 * Configured request timeout in seconds.
	 *
	 * @return int
	 */
	private function timeout(): int {
		$timeout = (int) $this->settings->get( 'request_timeout', self::TIMEOUT );

		return $timeout > 0 ? $timeout : self::TIMEOUT;
	}

	/**
	 * User agent sent with every request.
	 *
	 * @return string
	 */
	private function user_agent(): string {
		return 'CekEmail-WordPress/' . CEKEMAIL_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')';
	}

	/**
	 * Log a failure without leaking the token or the full address.
	 *
	 * @param string $email     Address that was being verified.
	 * @param string $error     Error slug.
	 * @param int    $http_code HTTP status code, 0 when there was no response.
	 * @return void
	 */
	private function log_failure( string $email, string $error, int $http_code ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			sprintf(
				'CekEmail: check for %1$s failed (%2$s, HTTP %3$d).',
				self::mask_email( $email ),
				$error,
				$http_code
			)
		);
	}
}
