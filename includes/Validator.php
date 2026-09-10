<?php
/**
 * Policy engine.
 *
 * @package CekEmail
 */

namespace CekEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an API result into an allow or block decision.
 */
class Validator {

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var Api_Client
	 */
	private $client;

	/**
	 * Admin notice queue.
	 *
	 * @var Admin_Notices
	 */
	private $notices;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings repository.
	 * @param Api_Client    $client   API client.
	 * @param Admin_Notices $notices  Admin notice queue.
	 */
	public function __construct( Settings $settings, Api_Client $client, Admin_Notices $notices ) {
		$this->settings = $settings;
		$this->client   = $client;
		$this->notices  = $notices;
	}

	/**
	 * Verify an address and decide what the caller should do with it.
	 *
	 * @param string $email  Address to verify.
	 * @param string $source Integration slug the check came from.
	 * @return array{action: string, message: string}
	 */
	public function validate( string $email, string $source ): array {
		$result   = $this->client->check( $email );
		$decision = $this->evaluate( $result );

		/**
		 * Filters the decision taken for a verification result.
		 *
		 * @param array  $decision Decision with `action` and `message` keys.
		 * @param array  $result   Normalised API result.
		 * @param string $source   Integration slug the check came from.
		 */
		$decision = apply_filters( 'cekemail_decision', $decision, $result, $source );

		$decision = $this->normalise_decision( $decision );

		$masked_email = Api_Client::mask_email( $email );

		/**
		 * Fires after every check, with the address masked.
		 *
		 * @param string $masked_email Masked address, for example j***@gmail.com.
		 * @param array  $result       Normalised API result.
		 * @param array  $decision     Decision with `action` and `message` keys.
		 * @param string $source       Integration slug the check came from.
		 */
		do_action( 'cekemail_after_check', $masked_email, $result, $decision, $source );

		return $decision;
	}

	/**
	 * Apply the configured policy to a normalised API result.
	 *
	 * @param array $result Normalised API result.
	 * @return array{action: string, message: string}
	 */
	public function evaluate( array $result ): array {
		if ( empty( $result['ok'] ) ) {
			$error = isset( $result['error'] ) ? (string) $result['error'] : 'invalid_response';

			if ( in_array( $error, array( 'unauthorized', 'insufficient_credits', 'forbidden' ), true ) ) {
				$this->notices->record( $error );
			}

			return array(
				'action'  => $this->policy( 'on_api_error' ),
				'message' => __( 'We could not verify your email address right now. Please try again in a few minutes.', 'cekemail-email-validation' ),
			);
		}

		$status = isset( $result['status'] ) ? (string) $result['status'] : 'unknown';

		switch ( $status ) {
			case 'valid':
				$action = 'allow';
				break;
			case 'invalid':
				$action = 'block';
				break;
			case 'disposable':
				$action = $this->settings->get( 'block_disposable' ) ? 'block' : 'allow';
				break;
			case 'catch_all':
				$action = $this->policy( 'on_catch_all' );
				break;
			case 'greylisted':
			case 'unknown':
			default:
				$action = $this->policy( 'on_unknown' );
				break;
		}

		return array(
			'action'  => $action,
			'message' => 'allow' === $action ? '' : $this->message_for( $status, $result ),
		);
	}

	/**
	 * Read an allow or block policy setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function policy( string $key ): string {
		return 'block' === $this->settings->get( $key ) ? 'block' : 'allow';
	}

	/**
	 * Message shown to the visitor when an address is blocked.
	 *
	 * @param string $status Result status.
	 * @param array  $result Normalised API result.
	 * @return string
	 */
	private function message_for( string $status, array $result ): string {
		$suggestion = '';

		if ( $this->settings->get( 'show_suggestion' ) && ! empty( $result['suggestion'] ) ) {
			$suggestion = (string) $result['suggestion'];
		}

		switch ( $status ) {
			case 'invalid':
				if ( '' !== $suggestion ) {
					/* translators: %s: suggested email address. */
					return sprintf( __( 'This email address cannot receive mail. Did you mean %s?', 'cekemail-email-validation' ), $suggestion );
				}

				return __( 'This email address cannot receive mail. Please check it for typos.', 'cekemail-email-validation' );

			case 'disposable':
				if ( '' !== $suggestion ) {
					/* translators: %s: suggested email address. */
					return sprintf( __( 'Disposable email addresses are not accepted. Did you mean %s?', 'cekemail-email-validation' ), $suggestion );
				}

				return __( 'Disposable email addresses are not accepted. Please use a permanent address.', 'cekemail-email-validation' );

			case 'catch_all':
				if ( '' !== $suggestion ) {
					/* translators: %s: suggested email address. */
					return sprintf( __( 'We could not confirm that this email address can receive mail. Did you mean %s?', 'cekemail-email-validation' ), $suggestion );
				}

				return __( 'We could not confirm that this email address can receive mail. Please use a different address.', 'cekemail-email-validation' );

			default:
				if ( '' !== $suggestion ) {
					/* translators: %s: suggested email address. */
					return sprintf( __( 'We could not verify this email address. Did you mean %s?', 'cekemail-email-validation' ), $suggestion );
				}

				return __( 'We could not verify this email address. Please use a different address.', 'cekemail-email-validation' );
		}
	}

	/**
	 * Make sure a filtered decision still has the expected shape.
	 *
	 * @param mixed $decision Decision returned by the filter.
	 * @return array{action: string, message: string}
	 */
	private function normalise_decision( $decision ): array {
		if ( ! is_array( $decision ) ) {
			return array(
				'action'  => 'allow',
				'message' => '',
			);
		}

		$action = ( isset( $decision['action'] ) && 'block' === $decision['action'] ) ? 'block' : 'allow';

		return array(
			'action'  => $action,
			'message' => isset( $decision['message'] ) ? (string) $decision['message'] : '',
		);
	}
}
