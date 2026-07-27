<?php
/**
 * Step 1 — a customer site sends its owner here; we send them to Google.
 *
 * @package GoogleReviewsConnect
 */

declare( strict_types=1 );

require __DIR__ . '/lib.php';

$config = gbrw_config();

gbrw_rate_limit( 'authorize', 20, 60 );

$site   = gbrw_param( $_GET, 'site' );
$nonce  = gbrw_param( $_GET, 'nonce' );
$return = gbrw_param( $_GET, 'return' );

$origin = gbrw_site_origin( $site );

if ( null === $origin ) {
	gbrw_fail_html( 'The requesting site address was not valid.' );
}

if ( strlen( $nonce ) < 16 || ! preg_match( '/^[A-Za-z0-9]+$/', $nonce ) ) {
	gbrw_fail_html( 'The request was missing a valid security token.' );
}

// The return URL must live on the same origin as the requesting site. Without
// this check the service would be an open redirector.
if ( gbrw_site_origin( $return ) !== $origin ) {
	gbrw_fail_html( 'The return address did not match the requesting site.' );
}

$state = gbrw_sign(
	array(
		'site'   => $origin,
		'nonce'  => $nonce,
		'return' => $return,
		'ts'     => time(),
	)
);

$params = array(
	'client_id'              => (string) $config['client_id'],
	'redirect_uri'           => rtrim( (string) $config['service_url'], '/' ) . '/callback.php',
	'response_type'          => 'code',
	'scope'                  => 'https://www.googleapis.com/auth/business.manage openid email',
	'access_type'            => 'offline',
	'prompt'                 => 'consent',
	'include_granted_scopes' => 'true',
	'state'                  => $state,
);

header( 'Cache-Control: no-store' );
header( 'Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( $params ), true, 302 );
exit;
