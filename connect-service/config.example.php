<?php
/**
 * Copy this file to config.php and fill it in.
 *
 * config.php holds your Google client secret. It must never be committed to git
 * and must never be readable over HTTP.
 *
 * @package GoogleReviewsConnect
 */

return array(

	// From Google Cloud Console -> APIs & Services -> Credentials -> OAuth
	// client ID -> Web application.
	'client_id'     => '',
	'client_secret' => '',

	// The public HTTPS base URL of this service, with no trailing slash.
	// The redirect URI you register in Google Cloud Console is this plus
	// "/callback.php" — for example https://connect.yourdomain.com/callback.php
	'service_url'   => 'https://connect.yourdomain.com',

	// Random secret used to sign the OAuth state parameter.
	// Generate with:  php -r "echo bin2hex(random_bytes(32));"
	'state_secret'  => '',

	// Where one-time tickets are parked for ~3 minutes between Google's callback
	// and the customer site claiming them. Put this OUTSIDE your web root if you
	// can; the bundled .htaccess only protects Apache.
	'ticket_dir'    => __DIR__ . '/tickets',

);
