<?php
/**
 * Step 2 — Google redirects here. Exchange the code, park the tokens under a
 * one-time ticket, and send the browser back to the customer's site.
 *
 * This is the single redirect URI registered in Google Cloud Console. Customer
 * sites never register anything of their own.
 *
 * @package GoogleReviewsConnect
 */

declare( strict_types=1 );

require __DIR__ . '/lib.php';

$config = gbrw_config();

$state_param = gbrw_param( $_GET, 'state' );
$state       = gbrw_verify( $state_param );

if ( null === $state ) {
	gbrw_fail_html( 'This authorization response could not be verified, or it expired. Please start again.' );
}

$return = (string) ( $state['return'] ?? '' );

if ( gbrw_site_origin( $return ) !== ( $state['site'] ?? null ) ) {
	gbrw_fail_html( 'The return address did not match the requesting site.' );
}

$separator = ( false === strpos( $return, '?' ) ) ? '?' : '&';

// The customer declined, or Google refused.
$error = gbrw_param( $_GET, 'error' );

if ( '' !== $error ) {
	header( 'Cache-Control: no-store' );
	header( 'Location: ' . $return . $separator . 'gbrw_error=' . rawurlencode( $error ), true, 302 );
	exit;
}

$code = gbrw_param( $_GET, 'code' );

if ( '' === $code ) {
	gbrw_fail_html( 'Google did not return an authorization code.' );
}

$tokens = gbrw_google_token(
	array(
		'code'          => $code,
		'client_id'     => (string) $config['client_id'],
		'client_secret' => (string) $config['client_secret'],
		'redirect_uri'  => rtrim( (string) $config['service_url'], '/' ) . '/callback.php',
		'grant_type'    => 'authorization_code',
	)
);

if ( 200 !== (int) $tokens['_status'] || empty( $tokens['access_token'] ) ) {
	$reason = isset( $tokens['error'] ) ? (string) $tokens['error'] : 'token_exchange_failed';

	header( 'Cache-Control: no-store' );
	header( 'Location: ' . $return . $separator . 'gbrw_error=' . rawurlencode( $reason ), true, 302 );
	exit;
}

// Best effort: identify the account so the site can show who is connected.
$email = '';
$ch    = curl_init( 'https://www.googleapis.com/oauth2/v3/userinfo' );

curl_setopt_array(
	$ch,
	array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_HTTPHEADER     => array( 'Authorization: Bearer ' . $tokens['access_token'] ),
	)
);

$info = curl_exec( $ch );
curl_close( $ch );

if ( is_string( $info ) ) {
	$decoded = json_decode( $info, true );
	$email   = is_array( $decoded ) && isset( $decoded['email'] ) ? (string) $decoded['email'] : '';
}

$ticket = gbrw_ticket_put(
	array(
		'site'          => (string) $state['site'],
		'nonce'         => (string) $state['nonce'],
		'access_token'  => (string) $tokens['access_token'],
		'refresh_token' => isset( $tokens['refresh_token'] ) ? (string) $tokens['refresh_token'] : '',
		'expires_in'    => isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : 3600,
		'scope'         => isset( $tokens['scope'] ) ? (string) $tokens['scope'] : '',
		'email'         => $email,
	)
);

// Only the ticket travels through the browser. The tokens never do.
header( 'Cache-Control: no-store' );
header( 'Location: ' . $return . $separator . 'gbrw_ticket=' . rawurlencode( $ticket ), true, 302 );
exit;
