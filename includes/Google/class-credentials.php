<?php
/**
 * OAuth client credentials for this site.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Google;

use GoogleReviews\Crypto;
use GoogleReviews\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Two ways a site can obtain Google OAuth credentials.
 *
 * MODE_MANAGED  — the hosted connect service holds the client secret and swaps
 *                 codes and refresh tokens on the site's behalf. This is what
 *                 customers get: one click, no Google Cloud project.
 *
 * MODE_OWN      — the site owner registers their own Google Cloud project and
 *                 pastes the client ID and secret here. No connect service
 *                 involved. Used for development, and for technical users who
 *                 would rather not depend on a third party.
 *
 * The client secret is encrypted at rest and never sent to the browser.
 */
class Credentials {

	private const OPTION = 'gbrw_google_credentials';

	public const MODE_MANAGED = 'managed';
	public const MODE_OWN     = 'own';
	public const MODE_NONE    = 'none';

	/**
	 * Which mode this site can currently use.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public static function mode(): string {
		// The hosted service wins: it is the path customers are meant to take,
		// and it works without any Google Cloud setup on their part.
		if ( self::connect_service_available() ) {
			return self::MODE_MANAGED;
		}

		if ( self::has_own_credentials() ) {
			return self::MODE_OWN;
		}

		return self::MODE_NONE;
	}

	/**
	 * Whether a real connect service has been configured for this build.
	 *
	 * @return bool
	 */
	public static function connect_service_available(): bool {
		return '' !== self::connect_service_url();
	}

	/**
	 * Base URL of the hosted connect service, without a trailing slash.
	 *
	 * @return string Empty string when this build has no service configured.
	 */
	public static function connect_service_url(): string {
		if ( ! defined( 'GBRW_CONNECT_SERVICE_URL' ) ) {
			return '';
		}

		$url = untrailingslashit( (string) GBRW_CONNECT_SERVICE_URL );

		// The shipped placeholder must never be treated as a real service.
		if ( '' === $url || false !== strpos( $url, 'example.com' ) ) {
			return '';
		}

		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? $url : '';
	}

	/**
	 * Whether the site owner has supplied their own client ID and secret.
	 *
	 * @return bool
	 */
	public static function has_own_credentials(): bool {
		$stored = self::stored();

		return '' !== $stored['client_id'] && '' !== $stored['client_secret_cipher'];
	}

	/**
	 * The OAuth client ID.
	 *
	 * @return string Empty string when unset.
	 */
	public static function client_id(): string {
		return self::stored()['client_id'];
	}

	/**
	 * The decrypted OAuth client secret.
	 *
	 * @return string|null Null when unset or undecryptable.
	 */
	public static function client_secret(): ?string {
		$cipher = self::stored()['client_secret_cipher'];

		return '' === $cipher ? null : Crypto::decrypt( $cipher );
	}

	/**
	 * Store the site owner's own credentials.
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @return bool Whether the credentials were saved.
	 */
	public static function save( string $client_id, string $client_secret ): bool {
		$cipher = Crypto::encrypt( $client_secret );

		if ( null === $cipher ) {
			Logger::error( 'Refusing to store Google credentials: the client secret could not be encrypted.' );

			return false;
		}

		Logger::info( 'Google client credentials saved.' );

		return update_option(
			self::OPTION,
			array(
				'client_id'            => $client_id,
				'client_secret_cipher' => $cipher,
			),
			false
		);
	}

	/**
	 * Remove the stored credentials.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );

		Logger::info( 'Google client credentials removed.' );
	}

	/**
	 * The exact redirect URI that must be registered in Google Cloud Console.
	 *
	 * @return string
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin.php?page=gbrw-settings' );
	}

	/**
	 * This site's origin, derived from the same URL as the redirect URI so the
	 * two can never disagree on scheme or host.
	 *
	 * @return string
	 */
	public static function site_origin(): string {
		$parts = wp_parse_url( self::redirect_uri() );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return untrailingslashit( home_url() );
		}

		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );

		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		return $origin;
	}

	/**
	 * Whether the redirect URI will be accepted by Google.
	 *
	 * Google requires HTTPS for every redirect URI except loopback addresses,
	 * and rejects hosts that are not real public domains — which rules out the
	 * .local hostnames used by most WordPress dev environments.
	 *
	 * @return bool
	 */
	public static function redirect_uri_usable(): bool {
		$host = (string) wp_parse_url( self::redirect_uri(), PHP_URL_HOST );

		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return true;
		}

		if ( 'https' !== wp_parse_url( self::redirect_uri(), PHP_URL_SCHEME ) ) {
			return false;
		}

		// Reserved development suffixes Google will not accept.
		foreach ( array( '.local', '.test', '.invalid', '.example', '.localhost' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return false;
			}
		}

		return false !== strpos( $host, '.' );
	}

	/**
	 * The stored option, with every key guaranteed present.
	 *
	 * @return array<string, string>
	 */
	private static function stored(): array {
		$defaults = array(
			'client_id'            => '',
			'client_secret_cipher' => '',
		);

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		return array_merge( $defaults, array_map( 'strval', $stored ) );
	}
}
