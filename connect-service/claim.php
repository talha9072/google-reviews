<?php
/**
 * Step 3 — the customer's site redeems its ticket, server to server, and
 * receives the tokens. The ticket is destroyed on first use.
 *
 * @package GoogleReviewsConnect
 */

declare( strict_types=1 );

require __DIR__ . '/lib.php';

gbrw_config();

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	gbrw_fail( 'POST required.', 405 );
}

gbrw_rate_limit( 'claim', 30, 60 );

$ticket = gbrw_param( $_POST, 'ticket' );
$site   = gbrw_param( $_POST, 'site' );
$nonce  = gbrw_param( $_POST, 'nonce' );

$origin = gbrw_site_origin( $site );

if ( null === $origin || '' === $ticket || '' === $nonce ) {
	gbrw_fail( 'Missing or invalid parameters.' );
}

$data = gbrw_ticket_take( $ticket );

if ( null === $data ) {
	gbrw_fail( 'That ticket is unknown, already used, or expired.', 404 );
}

// The claiming site must be the same one that started the flow, and must know
// the nonce it generated. Both are checked in constant time.
if ( ! hash_equals( (string) $data['site'], $origin ) || ! hash_equals( (string) $data['nonce'], $nonce ) ) {
	gbrw_fail( 'This ticket does not belong to the requesting site.', 403 );
}

gbrw_json_out(
	array(
		'access_token'  => (string) $data['access_token'],
		'refresh_token' => (string) $data['refresh_token'],
		'expires_in'    => (int) $data['expires_in'],
		'scope'         => (string) $data['scope'],
		'email'         => (string) $data['email'],
	)
);
