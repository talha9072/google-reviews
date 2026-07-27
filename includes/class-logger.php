<?php
/**
 * File logger with redaction.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Writes diagnostics to an unguessable file inside the uploads directory.
 *
 * The filename carries a random component because nginx ignores .htaccess, so
 * the deny rule cannot be relied on as the only protection.
 *
 * Nothing sensitive is ever written: tokens, authorization codes, and anything
 * resembling a secret are redacted before the line is composed.
 */
class Logger {

	private const MAX_BYTES = 5242880; // 5 MB.

	/**
	 * Context keys whose values are never written.
	 *
	 * @var array<int, string>
	 */
	private const REDACT_KEYS = array(
		'access_token',
		'refresh_token',
		'token',
		'code',
		'client_secret',
		'secret',
		'authorization',
		'password',
		'license_key',
		'state',
	);

	/**
	 * Create the log directory and its guards.
	 *
	 * @return void
	 */
	public static function prepare_storage(): void {
		$dir = self::directory();

		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$guards = array(
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		);

		foreach ( $guards as $filename => $contents ) {
			$path = $dir . $filename;

			if ( ! file_exists( $path ) ) {
				self::write_file( $path, $contents );
			}
		}
	}

	/**
	 * Log a debug message. Suppressed unless debug logging is switched on.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( ! Settings::get( 'debug_logging', false ) && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		self::log( 'debug', $message, $context );
	}

	/**
	 * Log an informational message.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Log an error.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Compose and append a line.
	 *
	 * @param string               $level   Severity.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Structured context.
	 * @return void
	 */
	private static function log( string $level, string $message, array $context = array() ): void {
		$path = self::file_path();

		if ( '' === $path ) {
			return;
		}

		self::maybe_rotate( $path );

		$line = sprintf(
			'[%s] %s: %s',
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			self::scrub( $message )
		);

		if ( ! empty( $context ) ) {
			$encoded = wp_json_encode( self::redact( $context ) );
			$line   .= ' ' . ( false === $encoded ? '{"context":"unencodable"}' : $encoded );
		}

		self::write_file( $path, $line . PHP_EOL, true );
	}

	/**
	 * Replace sensitive values in a context array.
	 *
	 * @param array<string, mixed> $context Structured context.
	 * @return array<string, mixed> Redacted context.
	 */
	private static function redact( array $context ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), self::REDACT_KEYS, true ) ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact( $value );
				continue;
			}

			if ( is_string( $value ) ) {
				$clean[ $key ] = self::scrub( $value );
				continue;
			}

			// Numbers, booleans, and null keep their JSON type so the log stays
			// machine-readable.
			$clean[ $key ] = is_scalar( $value ) || null === $value
				? $value
				: '[' . gettype( $value ) . ']';
		}

		return $clean;
	}

	/**
	 * Strip query strings and long token-like strings out of free text.
	 *
	 * @param string $text Raw text.
	 * @return string Scrubbed text.
	 */
	private static function scrub( string $text ): string {
		// Query strings can carry codes and tokens; keep the path only.
		$text = (string) preg_replace( '#(https?://[^\s?]+)\?\S*#i', '$1?[redacted]', $text );

		// Bare Google-style tokens.
		$text = (string) preg_replace( '#\b(ya29|1//)[A-Za-z0-9._\-]{10,}#', '[redacted]', $text );

		return $text;
	}

	/**
	 * Truncate the log once it grows past the size cap.
	 *
	 * @param string $path Log file path.
	 * @return void
	 */
	private static function maybe_rotate( string $path ): void {
		if ( ! file_exists( $path ) ) {
			return;
		}

		$size = filesize( $path );

		if ( false === $size || $size < self::MAX_BYTES ) {
			return;
		}

		$previous = $path . '.1';

		global $wp_filesystem;

		if ( self::init_filesystem() && $wp_filesystem instanceof \WP_Filesystem_Base ) {
			$wp_filesystem->delete( $previous );
			$wp_filesystem->move( $path, $previous );
		}
	}

	/**
	 * Absolute path to the active log file.
	 *
	 * @return string Empty string when uploads are unavailable.
	 */
	public static function file_path(): string {
		$dir = self::directory();

		if ( '' === $dir ) {
			return '';
		}

		$suffix = get_option( 'gbrw_log_suffix' );

		if ( ! is_string( $suffix ) || '' === $suffix ) {
			$suffix = wp_generate_password( 20, false, false );
			update_option( 'gbrw_log_suffix', $suffix, false );
		}

		return $dir . 'gbrw-' . $suffix . '.log';
	}

	/**
	 * Log directory, with trailing slash.
	 *
	 * @return string Empty string when uploads are unavailable.
	 */
	private static function directory(): string {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $uploads['basedir'] ) . 'gbrw-logs/';
	}

	/**
	 * Write through WP_Filesystem.
	 *
	 * @param string $path     Destination path.
	 * @param string $contents Contents to write.
	 * @param bool   $append   Whether to append rather than replace.
	 * @return void
	 */
	private static function write_file( string $path, string $contents, bool $append = false ): void {
		global $wp_filesystem;

		if ( ! self::init_filesystem() || ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return;
		}

		if ( $append && $wp_filesystem->exists( $path ) ) {
			$existing = $wp_filesystem->get_contents( $path );
			$contents = ( false === $existing ? '' : $existing ) . $contents;
		}

		$wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE );
	}

	/**
	 * Boot WP_Filesystem on demand.
	 *
	 * @return bool Whether the filesystem is usable.
	 */
	private static function init_filesystem(): bool {
		global $wp_filesystem;

		if ( $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		return (bool) WP_Filesystem();
	}
}
