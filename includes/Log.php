<?php
/**
 * Recent checks log.
 *
 * @package CekEmail
 */

namespace CekEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Ring buffer holding the most recent checks, with addresses masked.
 */
class Log {

	/**
	 * Option holding the entries.
	 */
	const OPTION = 'cekemail_recent_checks';

	/**
	 * How many entries are kept.
	 */
	const MAX_ENTRIES = 50;

	/**
	 * Hook the recorder.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'cekemail_after_check', array( $this, 'record' ), 10, 4 );
	}

	/**
	 * Store one check.
	 *
	 * @param string $masked_email Masked address.
	 * @param array  $result       Normalised API result.
	 * @param array  $decision     Decision with `action` and `message` keys.
	 * @param string $source       Integration slug the check came from.
	 * @return void
	 */
	public function record( string $masked_email, array $result, array $decision, string $source ): void {
		$entries = $this->entries();

		array_unshift(
			$entries,
			array(
				'email'       => $masked_email,
				'status'      => isset( $result['status'] ) ? (string) $result['status'] : '',
				'reason_code' => isset( $result['reason_code'] ) ? (string) $result['reason_code'] : '',
				'error'       => isset( $result['error'] ) ? (string) $result['error'] : '',
				'decision'    => isset( $decision['action'] ) ? (string) $decision['action'] : '',
				'source'      => $source,
				'time'        => time(),
			)
		);

		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ), false );
	}

	/**
	 * Read the stored entries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function entries(): array {
		$entries = get_option( self::OPTION, array() );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		return array_values( array_filter( $entries, 'is_array' ) );
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}
}
