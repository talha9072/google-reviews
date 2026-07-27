<?php
/**
 * Authenticated encryption for Google OAuth tokens at rest.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews;

defined( 'ABSPATH' ) || exit;

/**
 * XSalsa20-Poly1305 via libsodium, which ships with PHP 7.2+.
 *
 * Key resolution, in order:
 *   1. The GBRW_ENCRYPTION_KEY constant in wp-config.php (recommended).
 *   2. Derived from the site's AUTH_KEY and SECURE_AUTH_KEY salts.
 *
 * Ciphertext carries a key identifier so a key change is detected and reported
 * as "reconnect required" rather than surfacing as a silent decryption failure.
 */
class Crypto {

	private const FORMAT    = 'v1';
	private const HKDF_INFO = 'gbrw-token-encryption';
	private const HKDF_SALT = 'gbrw-v1';

	/**
	 * Whether encryption can run in this environment.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'hash_hkdf' );
	}

	/**
	 * Where the encryption key comes from.
	 *
	 * @return string One of 'constant', 'salts', or 'unavailable'.
	 */
	public static function key_source(): string {
		if ( ! self::is_available() ) {
			return 'unavailable';
		}

		return defined( 'GBRW_ENCRYPTION_KEY' ) && '' !== (string) GBRW_ENCRYPTION_KEY
			? 'constant'
			: 'salts';
	}

	/**
	 * Encrypt a value.
	 *
	 * @param string $plaintext Value to protect.
	 * @return string|null Portable ciphertext, or null when encryption is impossible.
	 */
	public static function encrypt( string $plaintext ): ?string {
		$key = self::derive_key();

		if ( null === $key ) {
			return null;
		}

		try {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		} catch ( \Exception $e ) {
			Logger::error( 'Token encryption failed.' );

			return null;
		} finally {
			sodium_memzero( $key );
		}

		// Not obfuscation: the nonce and ciphertext are raw binary and must be
		// made safe to store in a text option column.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::FORMAT . '.' . self::key_id() . '.' . base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $payload Portable ciphertext.
	 * @return string|null Plaintext, or null when the payload is unreadable.
	 */
	public static function decrypt( string $payload ): ?string {
		$parts = explode( '.', $payload, 3 );

		if ( 3 !== count( $parts ) || self::FORMAT !== $parts[0] ) {
			return null;
		}

		if ( ! hash_equals( self::key_id(), $parts[1] ) ) {
			// The site's salts changed, or GBRW_ENCRYPTION_KEY was added/altered.
			Logger::warning( 'Stored token was encrypted with a different key; Google must be reconnected.' );

			return null;
		}

		// Not obfuscation: restores the raw binary written by encrypt().
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = base64_decode( $parts[2], true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}

		$key = self::derive_key();

		if ( null === $key ) {
			return null;
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
		} catch ( \Exception $e ) {
			return null;
		} finally {
			sodium_memzero( $key );
		}

		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Short, non-reversible identifier for the active key.
	 *
	 * @return string
	 */
	public static function key_id(): string {
		$key = self::derive_key();

		if ( null === $key ) {
			return 'none';
		}

		$id = substr( hash( 'sha256', 'gbrw-key-id|' . $key ), 0, 12 );

		sodium_memzero( $key );

		return $id;
	}

	/**
	 * Whether the site is relying on salts that were never customised.
	 *
	 * @return bool
	 */
	public static function has_weak_salts(): bool {
		if ( 'constant' === self::key_source() ) {
			return false;
		}

		return ! defined( 'AUTH_KEY' )
			|| '' === (string) AUTH_KEY
			|| false !== strpos( (string) AUTH_KEY, 'put your unique phrase here' );
	}

	/**
	 * Derive the 32-byte symmetric key.
	 *
	 * @return string|null Raw key bytes, or null when unavailable.
	 */
	private static function derive_key(): ?string {
		if ( ! self::is_available() ) {
			return null;
		}

		if ( defined( 'GBRW_ENCRYPTION_KEY' ) && '' !== (string) GBRW_ENCRYPTION_KEY ) {
			$material = (string) GBRW_ENCRYPTION_KEY;
		} elseif ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			$material = (string) AUTH_KEY . (string) SECURE_AUTH_KEY;
		} else {
			return null;
		}

		return hash_hkdf(
			'sha256',
			$material,
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
			self::HKDF_INFO,
			self::HKDF_SALT
		);
	}
}
