<?php
/**
 * Setup checker. Upload the service, open this file in a browser, and it tells
 * you exactly what is still missing and what to paste where.
 *
 * Delete this file once the service is working.
 *
 * @package GoogleReviewsConnect
 */

declare( strict_types=1 );

$checks = array();
$config = null;

$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
	|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );

$host      = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : 'yourdomain.com';
$dir       = rtrim( str_replace( '\\', '/', dirname( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ), '/' );
$base      = ( $https ? 'https' : 'http' ) . '://' . $host . $dir;
$config_ok = false;

// --- Environment -----------------------------------------------------------

$checks[] = array(
	'label' => 'PHP 8.0 or newer',
	'ok'    => version_compare( PHP_VERSION, '8.0', '>=' ),
	'note'  => 'Running PHP ' . PHP_VERSION,
);

$checks[] = array(
	'label' => 'cURL extension',
	'ok'    => function_exists( 'curl_init' ),
	'note'  => function_exists( 'curl_init' ) ? 'Available' : 'Required to talk to Google. Ask your host to enable it.',
);

$checks[] = array(
	'label' => 'Served over HTTPS',
	'ok'    => $https,
	'note'  => $https
		? 'Good'
		: 'Google will refuse to redirect here over plain HTTP. Install a certificate first.',
);

// --- Configuration ---------------------------------------------------------

if ( ! is_readable( __DIR__ . '/config.php' ) ) {
	$checks[] = array(
		'label' => 'config.php exists',
		'ok'    => false,
		'note'  => 'Copy config.example.php to config.php, then reload this page.',
	);
} else {
	$config    = require __DIR__ . '/config.php';
	$config_ok = is_array( $config );

	$checks[] = array(
		'label' => 'config.php exists',
		'ok'    => $config_ok,
		'note'  => $config_ok ? 'Loaded' : 'The file did not return an array.',
	);

	if ( $config_ok ) {
		foreach ( array(
			'client_id'     => 'From Google Cloud Console -> Credentials -> OAuth client ID',
			'client_secret' => 'From the same OAuth client',
			'state_secret'  => 'Run: php -r "echo bin2hex(random_bytes(32));"',
			'service_url'   => 'The public base URL of this folder',
		) as $key => $hint ) {
			$value = isset( $config[ $key ] ) ? (string) $config[ $key ] : '';

			$checks[] = array(
				'label' => 'config: ' . $key,
				'ok'    => '' !== $value,
				'note'  => '' !== $value ? 'Set' : 'Empty. ' . $hint,
			);
		}

		if ( ! empty( $config['state_secret'] ) && strlen( (string) $config['state_secret'] ) < 32 ) {
			$checks[] = array(
				'label' => 'state_secret is long enough',
				'ok'    => false,
				'note'  => 'Use at least 32 characters. Run: php -r "echo bin2hex(random_bytes(32));"',
			);
		}

		$configured_url = isset( $config['service_url'] ) ? rtrim( (string) $config['service_url'], '/' ) : '';

		if ( '' !== $configured_url ) {
			$checks[] = array(
				'label' => 'service_url matches this location',
				'ok'    => $configured_url === $base,
				'note'  => $configured_url === $base
					? 'Matches'
					: 'config.php says "' . htmlspecialchars( $configured_url, ENT_QUOTES, 'UTF-8' ) . '" but this folder is actually at "' . htmlspecialchars( $base, ENT_QUOTES, 'UTF-8' ) . '". They must match exactly.',
			);
		}
	}
}

// --- Storage ---------------------------------------------------------------

$ticket_dir = ( is_array( $config ) && ! empty( $config['ticket_dir'] ) )
	? (string) $config['ticket_dir']
	: __DIR__ . '/tickets';

if ( ! is_dir( $ticket_dir ) ) {
	@mkdir( $ticket_dir, 0700, true );
}

$checks[] = array(
	'label' => 'tickets folder is writable',
	'ok'    => is_dir( $ticket_dir ) && is_writable( $ticket_dir ),
	'note'  => is_dir( $ticket_dir ) && is_writable( $ticket_dir )
		? 'Writable'
		: 'Create ' . htmlspecialchars( $ticket_dir, ENT_QUOTES, 'UTF-8' ) . ' and make it writable by PHP.',
);

// --- Is config.php exposed over HTTP? --------------------------------------

$exposed = null;

if ( function_exists( 'curl_init' ) && $https ) {
	$ch = curl_init( $base . '/config.php' );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 8,
			CURLOPT_SSL_VERIFYPEER => false,
		)
	);
	$body   = (string) curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );

	// A leak looks like PHP source in the response body.
	$exposed = ( 200 === $status && false !== strpos( $body, 'client_secret' ) );

	$checks[] = array(
		'label' => 'config.php is not readable over HTTP',
		'ok'    => ! $exposed,
		'note'  => $exposed
			? 'YOUR CLIENT SECRET IS PUBLICLY READABLE. Fix this before going further.'
			: 'Not exposed',
	);
}

$all_ok = true;
foreach ( $checks as $check ) {
	if ( ! $check['ok'] ) {
		$all_ok = false;
	}
}

$redirect_uri = $base . '/callback.php';

header( 'Content-Type: text/html; charset=utf-8' );
header( 'Cache-Control: no-store' );
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Connect service setup check</title>
<style>
	body { font: 16px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 46em; margin: 3em auto; padding: 0 1.2em; color: #1d2327; }
	h1 { font-size: 1.5em; margin-bottom: .2em; }
	h2 { font-size: 1.1em; margin-top: 2em; }
	.sub { color: #646970; margin-top: 0; }
	ul { list-style: none; padding: 0; }
	li { padding: .55em 0; border-bottom: 1px solid #e5e5e5; display: flex; gap: .7em; align-items: flex-start; }
	.mark { flex: 0 0 1.4em; font-weight: 700; }
	.ok .mark { color: #007017; }
	.bad .mark { color: #b32d2e; }
	.note { color: #646970; font-size: .9em; display: block; }
	code { background: #f0f0f1; padding: .15em .4em; border-radius: 3px; font-size: .9em; word-break: break-all; }
	pre { background: #f6f7f7; border: 1px solid #dcdcde; padding: .9em; border-radius: 4px; overflow-x: auto; }
	.banner { padding: 1em 1.2em; border-radius: 4px; margin: 1.5em 0; }
	.good { background: #edfaef; border-left: 4px solid #00a32a; }
	.warn { background: #fcf9e8; border-left: 4px solid #dba617; }
</style>

<h1>Connect service setup check</h1>
<p class="sub">This folder is at <code><?php echo htmlspecialchars( $base, ENT_QUOTES, 'UTF-8' ); ?></code></p>

<?php if ( $all_ok ) : ?>
	<div class="banner good"><strong>Everything checks out.</strong> Follow the two steps below, then delete this file.</div>
<?php else : ?>
	<div class="banner warn"><strong>Not ready yet.</strong> Fix the red items below and reload this page.</div>
<?php endif; ?>

<ul>
<?php foreach ( $checks as $check ) : ?>
	<li class="<?php echo $check['ok'] ? 'ok' : 'bad'; ?>">
		<span class="mark"><?php echo $check['ok'] ? '&#10003;' : '&#10007;'; ?></span>
		<span>
			<?php echo htmlspecialchars( (string) $check['label'], ENT_QUOTES, 'UTF-8' ); ?>
			<span class="note"><?php echo htmlspecialchars( (string) $check['note'], ENT_QUOTES, 'UTF-8' ); ?></span>
		</span>
	</li>
<?php endforeach; ?>
</ul>

<h2>1. Paste this into Google Cloud Console</h2>
<p>Credentials &rarr; your OAuth client &rarr; <strong>Authorised redirect URIs</strong>. Add exactly this, and nothing else:</p>
<pre><?php echo htmlspecialchars( $redirect_uri, ENT_QUOTES, 'UTF-8' ); ?></pre>

<h2>2. Paste this into each customer's wp-config.php</h2>
<pre><?php echo htmlspecialchars( "define( 'GBRW_CONNECT_SERVICE_URL', '" . $base . "' );", ENT_QUOTES, 'UTF-8' ); ?></pre>
<p>Add it above the line that says <code>/* That's all, stop editing! */</code>.</p>

<?php if ( ! $config_ok ) : ?>
<h2>Need a state secret?</h2>
<p>Here is a fresh one you can paste into <code>config.php</code>:</p>
<pre><?php echo htmlspecialchars( bin2hex( random_bytes( 32 ) ), ENT_QUOTES, 'UTF-8' ); ?></pre>
<?php endif; ?>

<h2>When it is working</h2>
<p>Delete <code>check.php</code> from this folder. It does not expose secrets, but it does not need to stay online.</p>
