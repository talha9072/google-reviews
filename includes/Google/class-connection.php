<?php
/**
 * State of the site's Google Business Profile connection.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Google;

use GoogleReviews\Crypto;
use GoogleReviews\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the stored connection.
 *
 * Tokens are encrypted before they reach the database and are never returned to
 * the browser. The Google client secret is not involved here at all — it lives
 * only on the hosted connect service.
 */
class Connection {

	private const OPTION = 'gbrw_google_connection';

	public const STATUS_DISCONNECTED = 'disconnected';
	public const STATUS_ACTIVE       = 'active';
	public const STATUS_REVOKED      = 'revoked';
	public const STATUS_ERROR        = 'error';

	/**
	 * Whether the site currently holds a usable connection.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		$state = self::state();

		return self::STATUS_ACTIVE === $state['status'] && '' !== $state['refresh_token_cipher'];
	}

	/**
	 * Current connection status.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public static function status(): string {
		return self::state()['status'];
	}

	/**
	 * The connected Google account's email address, when known.
	 *
	 * @return string Empty string when disconnected.
	 */
	public static function account_email(): string {
		return self::state()['account_email'];
	}

	/**
	 * When the connection was established.
	 *
	 * @return string MySQL UTC datetime, or an empty string.
	 */
	public static function connected_at(): string {
		return self::state()['connected_at'];
	}

	/**
	 * The most recent connection error, if any.
	 *
	 * @return string
	 */
	public static function last_error(): string {
		return self::state()['last_error'];
	}

	/**
	 * Persist a freshly issued token pair.
	 *
	 * @param string $access_token  Short-lived access token.
	 * @param string $refresh_token Long-lived refresh token.
	 * @param int    $expires_in    Access token lifetime in seconds.
	 * @param string $account_email Google account that granted access.
	 * @param string $scopes        Space-separated granted scopes.
	 * @return bool Whether the connection was stored.
	 */
	public static function store( string $access_token, string $refresh_token, int $expires_in, string $account_email, string $scopes ): bool {
		$access_cipher  = Crypto::encrypt( $access_token );
		$refresh_cipher = Crypto::encrypt( $refresh_token );

		if ( null === $access_cipher || null === $refresh_cipher ) {
			Logger::error( 'Refusing to store the Google connection: tokens could not be encrypted.' );

			return false;
		}

		$state = array(
			'status'               => self::STATUS_ACTIVE,
			'account_email'        => sanitize_email( $account_email ),
			'access_token_cipher'  => $access_cipher,
			'refresh_token_cipher' => $refresh_cipher,
			'token_expiry'         => gmdate( 'Y-m-d H:i:s', time() + max( 0, $expires_in ) ),
			'granted_scopes'       => sanitize_text_field( $scopes ),
			'connected_at'         => gmdate( 'Y-m-d H:i:s' ),
			'last_error'           => '',
			'key_id'               => Crypto::key_id(),
		);

		Logger::info( 'Google connection stored.', array( 'account_email' => $state['account_email'] ) );

		return update_option( self::OPTION, $state, false );
	}

	/**
	 * Read the decrypted refresh token.
	 *
	 * @return string|null Null when absent or undecryptable.
	 */
	public static function refresh_token(): ?string {
		$cipher = self::state()['refresh_token_cipher'];

		return '' === $cipher ? null : Crypto::decrypt( $cipher );
	}

	/**
	 * Read the decrypted access token, ignoring expired ones.
	 *
	 * @return string|null Null when absent, expired, or undecryptable.
	 */
	public static function access_token(): ?string {
		$state = self::state();

		if ( '' === $state['access_token_cipher'] || '' === $state['token_expiry'] ) {
			return null;
		}

		// Treat anything inside the next minute as already expired.
		if ( strtotime( $state['token_expiry'] . ' UTC' ) <= ( time() + MINUTE_IN_SECONDS ) ) {
			return null;
		}

		return Crypto::decrypt( $state['access_token_cipher'] );
	}

	/**
	 * Flag the connection as revoked or errored.
	 *
	 * The stored tokens are cleared, but published widgets keep rendering from
	 * the local database — a Google problem must never blank a customer's page.
	 *
	 * @param string $status  One of the STATUS_* constants.
	 * @param string $message Operator-facing reason.
	 * @return void
	 */
	public static function mark_failed( string $status, string $message ): void {
		$state = self::state();

		$state['status']               = $status;
		$state['last_error']           = sanitize_text_field( $message );
		$state['access_token_cipher']  = '';
		$state['refresh_token_cipher'] = '';

		update_option( self::OPTION, $state, false );

		Logger::warning( 'Google connection marked as failed.', array( 'status' => $status ) );
	}

	/**
	 * Remove the connection entirely.
	 *
	 * @return void
	 */
	public static function disconnect(): void {
		delete_option( self::OPTION );

		Logger::info( 'Google connection removed.' );
	}

	/**
	 * The stored state, with every key guaranteed present.
	 *
	 * @return array<string, string>
	 */
	private static function state(): array {
		$defaults = array(
			'status'               => self::STATUS_DISCONNECTED,
			'account_email'        => '',
			'access_token_cipher'  => '',
			'refresh_token_cipher' => '',
			'token_expiry'         => '',
			'granted_scopes'       => '',
			'connected_at'         => '',
			'last_error'           => '',
			'key_id'               => '',
		);

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		return array_merge( $defaults, array_map( 'strval', $stored ) );
	}
}
