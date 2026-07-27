<?php
/**
 * Plugin Name:       Reviews Widget for Google Business Profile
 * Plugin URI:        https://example.com/reviews-widget
 * Description:       Import reviews from your Google Business Profile and display them anywhere with customizable, responsive widgets.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            TBD
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       google-reviews-widget
 * Domain Path:       /languages
 *
 * @package GoogleReviews
 */

// NOTE: This file must stay parseable by PHP 7.x so the version notice below can
// render on hosts that fall short of the minimum. Do not use PHP 8 syntax here.

defined( 'ABSPATH' ) || exit;

define( 'GBRW_VERSION', '0.1.0' );
define( 'GBRW_DB_VERSION', 1 );
define( 'GBRW_MIN_PHP', '8.0' );
define( 'GBRW_PLUGIN_FILE', __FILE__ );
define( 'GBRW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GBRW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GBRW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * The hosted connect service that proxies Google OAuth code exchange and token
 * refresh. The Google client secret lives there and never ships in this plugin.
 *
 * Overridable in wp-config.php for local development against a test service.
 */
if ( ! defined( 'GBRW_CONNECT_SERVICE_URL' ) ) {
	define( 'GBRW_CONNECT_SERVICE_URL', 'https://connect.example.com' );
}

/**
 * Collect the reasons this environment cannot run the plugin.
 *
 * @return array<int, string> List of human-readable requirement failures.
 */
function gbrw_requirement_failures() {
	$failures = array();

	if ( version_compare( PHP_VERSION, GBRW_MIN_PHP, '<' ) ) {
		$failures[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'PHP %1$s or higher is required. This site is running PHP %2$s.', 'google-reviews-widget' ),
			GBRW_MIN_PHP,
			PHP_VERSION
		);
	}

	if ( ! extension_loaded( 'sodium' ) && ! function_exists( 'sodium_crypto_secretbox' ) ) {
		$failures[] = __( 'The PHP Sodium extension is required to encrypt your Google connection tokens.', 'google-reviews-widget' );
	}

	return $failures;
}

/**
 * Show an admin notice describing why the plugin is inactive.
 *
 * @return void
 */
function gbrw_render_requirement_notice() {
	$failures = gbrw_requirement_failures();

	if ( empty( $failures ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>';
	echo esc_html__( 'Reviews Widget for Google Business Profile could not start.', 'google-reviews-widget' );
	echo '</strong></p><ul style="list-style:disc;margin-left:20px;">';

	foreach ( $failures as $failure ) {
		echo '<li>' . esc_html( $failure ) . '</li>';
	}

	echo '</ul><p>';
	echo esc_html__( 'Please ask your hosting provider to resolve this, then reload the page.', 'google-reviews-widget' );
	echo '</p></div>';
}

if ( ! empty( gbrw_requirement_failures() ) ) {
	add_action( 'admin_notices', 'gbrw_render_requirement_notice' );
	return;
}

require_once GBRW_PLUGIN_DIR . 'includes/class-autoloader.php';
\GoogleReviews\Autoloader::register();

register_activation_hook( __FILE__, array( '\GoogleReviews\Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\GoogleReviews\Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		\GoogleReviews\Plugin::instance()->boot();
	}
);
