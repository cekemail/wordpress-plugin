<?php
/**
 * WordPress comments integration.
 *
 * @package CekEmail
 */

namespace CekEmail\Integrations;

use CekEmail\Settings;
use CekEmail\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the address submitted with a comment by a logged out visitor.
 */
class Core_Comments implements Integration {

	/**
	 * Source slug reported with every check.
	 */
	const SOURCE = 'core_comments';

	/**
	 * Settings repository.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Policy engine.
	 *
	 * @var Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings  Settings repository.
	 * @param Validator $validator Policy engine.
	 */
	public function __construct( Settings $settings, Validator $validator ) {
		$this->settings  = $settings;
		$this->validator = $validator;
	}

	/**
	 * Slug matching the key inside the `integrations` setting.
	 *
	 * @return string
	 */
	public function key(): string {
		return self::SOURCE;
	}

	/**
	 * WordPress comments are always available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Register the integration's hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'preprocess_comment', array( $this, 'validate' ) );
	}

	/**
	 * Stop the comment when the address is blocked.
	 *
	 * @param array $commentdata Comment data being processed.
	 * @return array
	 */
	public function validate( $commentdata ) {
		if ( ! is_array( $commentdata ) || is_user_logged_in() ) {
			return $commentdata;
		}

		$email = isset( $commentdata['comment_author_email'] ) ? trim( (string) $commentdata['comment_author_email'] ) : '';

		if ( '' === $email || ! is_email( $email ) ) {
			return $commentdata;
		}

		$decision = $this->validator->validate( $email, self::SOURCE );

		if ( 'block' === $decision['action'] ) {
			wp_die(
				esc_html( $decision['message'] ),
				esc_html__( 'Comment blocked', 'cekemail-email-validation' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		return $commentdata;
	}
}
