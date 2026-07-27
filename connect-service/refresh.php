<?php
/**
 * Ongoing — swap a refresh token for a fresh access token.
 *
 * The customer's site holds the refresh token; only the client secret needed to
 * use it lives here. Nothing is stored by this endpoint.
 *
 * @package GoogleReviewsConnect
 */

declare( strict_types=1 );

require __DIR__ . '/lib.php';

$config = gbrw_config();

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	gbrw_fail( 'POST required.', 405 );
}

gbrw_rate_limit( 'refresh', 60, 60 );

$refresh_token = gbrw_param( $_POST, 'refresh_token' );
$site          = gbrw_param( $_POST, 'site' );

if ( '' === $refresh_token || null === gbrw_site_origin( $site ) ) {
	gbrw_fail( 'Missing or invalid parameters.' );
}

$tokens = gbrw_google_token(
	array(
		'refresh_token' => $refresh_token,
		'client_id'     => (string) $config['client_id'],
		'client_secret' => (string) $config['client_secret'],
		'grant_type'    => 'refresh_token',
	)
);

$status = (int) $tokens['_status'];

if ( 200 !== $status || empty( $tokens['access_token'] ) ) {
	// Pass Google's own error code through so the plugin can tell a revoked
	// grant apart from a transient failure.
	gbrw_json_out(
		array(
			'error'             => isset( $tokens['error'] ) ? (string) $tokens['error'] : 'refresh_failed',
			'error_description' => isset( $tokens['error_description'] ) ? (string) $tokens['error_description'] : '',
		),
		400 === $status ? 400 : 502
	);
}

gbrw_json_out(
	array(
		'access_token' => (string) $tokens['access_token'],
		'expires_in'   => isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : 3600,
		'scope'        => isset( $tokens['scope'] ) ? (string) $tokens['scope'] : '',
	)
);
