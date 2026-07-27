<?php
/**
 * Shared helpers for the connect service.
 *
 * This directory is NOT part of the WordPress plugin. It is deployed separately
 * to a domain you control, and is the only place the Google client secret lives.
 *
 * @package GoogleReviewsConnect
 */

declare( strict_types=1 );

/**
 * Load configuration once.
 *
 * @return array<string, mixed>
 */
function gbrw_config(): array {
	static $config = null;

	if ( null !== $config ) {
		return $config;
	}

	$path = __DIR__ . '/config.php';

	if ( ! is_readable( $path ) ) {
		gbrw_fail( 'Connect service is not configured.', 500 );
	}

	$config = require $path;

	foreach ( array( 'client_id', 'client_secret', 'state_secret', 'service_url' ) as $required ) {
		if ( empty( $config[ $required ] ) ) {
			gbrw_fail( 'Connect service configuration is incomplete.', 500 );
		}
	}

	return $config;
}

/**
 * Emit a JSON response and stop.
 *
 * @param array<string, mixed> $data   Response body.
 * @param int                  $status HTTP status code.
 * @return never
 */
function gbrw_json_out( array $data, int $status = 200 ) {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	echo json_encode( $data, JSON_UNESCAPED_SLASHES );
	exit;
}

/**
 * Emit an error and stop.
 *
 * @param string $message Human-readable reason.
 * @param int    $status  HTTP status code.
 * @return never
 */
function gbrw_fail( string $message, int $status = 400 ) {
	gbrw_json_out( array( 'error' => $message ), $status );
}

/**
 * Show an error as HTML, for failures that happen mid-browser-redirect.
 *
 * @param string $message Human-readable reason.
 * @return never
 */
function gbrw_fail_html( string $message ) {
	http_response_code( 400 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	echo '<!doctype html><meta charset="utf-8"><title>Connection failed</title>';
	echo '<div style="font:16px/1.5 system-ui,sans-serif;max-width:34em;margin:4em auto;padding:0 1em">';
	echo '<h1 style="font-size:1.3em">Connection failed</h1>';
	echo '<p>' . htmlspecialchars( $message, ENT_QUOTES, 'UTF-8' ) . '</p>';
	echo '<p>Please close this window and try again from your WordPress dashboard.</p>';
	echo '</div>';
	exit;
}

/**
 * Sign a payload so it can survive a round trip through the browser untampered.
 *
 * @param array<string, mixed> $payload Data to sign.
 * @return string URL-safe signed token.
 */
function gbrw_sign( array $payload ): string {
	$config = gbrw_config();
	$body   = gbrw_b64_encode( (string) json_encode( $payload ) );
	$sig    = hash_hmac( 'sha256', $body, (string) $config['state_secret'] );

	return $body . '.' . $sig;
}

/**
 * Verify and decode a signed payload.
 *
 * @param string $token   Signed token.
 * @param int    $max_age Maximum acceptable age in seconds.
 * @return array<string, mixed>|null Decoded payload, or null when invalid.
 */
function gbrw_verify( string $token, int $max_age = 900 ): ?array {
	$config = gbrw_config();
	$parts  = explode( '.', $token, 2 );

	if ( 2 !== count( $parts ) ) {
		return null;
	}

	$expected = hash_hmac( 'sha256', $parts[0], (string) $config['state_secret'] );

	if ( ! hash_equals( $expected, $parts[1] ) ) {
		return null;
	}

	$payload = json_decode( (string) gbrw_b64_decode( $parts[0] ), true );

	if ( ! is_array( $payload ) || ! isset( $payload['ts'] ) ) {
		return null;
	}

	if ( ( time() - (int) $payload['ts'] ) > $max_age ) {
		return null;
	}

	return $payload;
}

/**
 * URL-safe base64 encode.
 *
 * @param string $raw Raw bytes.
 * @return string
 */
function gbrw_b64_encode( string $raw ): string {
	return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
}

/**
 * URL-safe base64 decode.
 *
 * @param string $encoded Encoded string.
 * @return string
 */
function gbrw_b64_decode( string $encoded ): string {
	$decoded = base64_decode( strtr( $encoded, '-_', '+/' ), true );

	return false === $decoded ? '' : $decoded;
}

/**
 * Directory used to park tokens between the callback and the claim.
 *
 * @return string
 */
function gbrw_ticket_dir(): string {
	$config = gbrw_config();
	$dir    = isset( $config['ticket_dir'] ) ? (string) $config['ticket_dir'] : __DIR__ . '/tickets';

	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
		gbrw_fail( 'Connect service storage is unavailable.', 500 );
	}

	return rtrim( $dir, '/\\' );
}

/**
 * Park a token set under a one-time ticket.
 *
 * Tokens live here for at most a couple of minutes, purely so they never have to
 * travel through the browser's address bar. Nothing is retained afterwards.
 *
 * @param array<string, mixed> $data Token set plus binding data.
 * @param int                  $ttl  Lifetime in seconds.
 * @return string The ticket.
 */
function gbrw_ticket_put( array $data, int $ttl = 180 ): string {
	gbrw_ticket_gc();

	$ticket       = bin2hex( random_bytes( 32 ) );
	$data['_exp'] = time() + $ttl;

	$path = gbrw_ticket_dir() . '/' . hash( 'sha256', $ticket ) . '.json';

	if ( false === file_put_contents( $path, (string) json_encode( $data ), LOCK_EX ) ) {
		gbrw_fail( 'Could not complete the connection.', 500 );
	}

	@chmod( $path, 0600 );

	return $ticket;
}

/**
 * Redeem a ticket. Single use: the file is removed before the data is returned.
 *
 * @param string $ticket The ticket.
 * @return array<string, mixed>|null Stored data, or null when unknown or expired.
 */
function gbrw_ticket_take( string $ticket ): ?array {
	if ( ! preg_match( '/^[a-f0-9]{64}$/', $ticket ) ) {
		return null;
	}

	$path = gbrw_ticket_dir() . '/' . hash( 'sha256', $ticket ) . '.json';

	if ( ! is_readable( $path ) ) {
		return null;
	}

	$raw = (string) file_get_contents( $path );
	@unlink( $path );

	$data = json_decode( $raw, true );

	if ( ! is_array( $data ) || ! isset( $data['_exp'] ) || time() > (int) $data['_exp'] ) {
		return null;
	}

	return $data;
}

/**
 * Delete expired tickets.
 *
 * @return void
 */
function gbrw_ticket_gc(): void {
	$files = glob( gbrw_ticket_dir() . '/*.json' );

	if ( ! is_array( $files ) ) {
		return;
	}

	foreach ( $files as $file ) {
		$data = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $data ) || ! isset( $data['_exp'] ) || time() > (int) $data['_exp'] ) {
			@unlink( $file );
		}
	}
}

/**
 * Normalise a site URL to its origin, rejecting anything unusable.
 *
 * @param string $url Candidate site URL.
 * @return string|null Origin, or null when invalid.
 */
function gbrw_site_origin( string $url ): ?string {
	$parts = parse_url( $url );

	if ( false === $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return null;
	}

	if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return null;
	}

	$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );

	if ( ! empty( $parts['port'] ) ) {
		$origin .= ':' . (int) $parts['port'];
	}

	return $origin;
}

/**
 * POST to Google's token endpoint.
 *
 * @param array<string, string> $params Request body.
 * @return array<string, mixed> Decoded response, plus a '_status' key.
 */
function gbrw_google_token( array $params ): array {
	$ch = curl_init( 'https://oauth2.googleapis.com/token' );

	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query( $params ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 20,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER     => array( 'Accept: application/json' ),
		)
	);

	$body   = curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$err    = curl_error( $ch );

	curl_close( $ch );

	if ( false === $body ) {
		return array(
			'_status' => 0,
			'error'   => 'network_error',
			'error_description' => $err,
		);
	}

	$data = json_decode( (string) $body, true );

	if ( ! is_array( $data ) ) {
		return array(
			'_status' => $status,
			'error'   => 'bad_response',
		);
	}

	$data['_status'] = $status;

	return $data;
}

/**
 * Read a query or body parameter as a string.
 *
 * @param array<string, mixed> $source Superglobal to read from.
 * @param string               $key    Parameter name.
 * @return string Empty string when absent.
 */
function gbrw_param( array $source, string $key ): string {
	return isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? trim( $source[ $key ] ) : '';
}

/**
 * Very small fixed-window rate limiter, keyed by client IP.
 *
 * @param string $bucket Logical bucket name.
 * @param int    $limit  Maximum requests per window.
 * @param int    $window Window length in seconds.
 * @return void
 */
function gbrw_rate_limit( string $bucket, int $limit = 30, int $window = 60 ): void {
	$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
	$path = gbrw_ticket_dir() . '/rl-' . hash( 'sha256', $bucket . '|' . $ip . '|' . floor( time() / $window ) ) . '.cnt';

	$count = is_readable( $path ) ? (int) file_get_contents( $path ) : 0;

	if ( $count >= $limit ) {
		gbrw_fail( 'Too many requests. Please wait a moment and try again.', 429 );
	}

	file_put_contents( $path, (string) ( $count + 1 ), LOCK_EX );
}
