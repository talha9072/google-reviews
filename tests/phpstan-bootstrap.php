<?php
/**
 * Constants defined at runtime by the plugin bootstrap, declared here so static
 * analysis does not report them as undefined.
 *
 * @package GoogleReviews
 */

define( 'GBRW_VERSION', '0.1.0' );
define( 'GBRW_DB_VERSION', 1 );
define( 'GBRW_MIN_PHP', '8.0' );
define( 'GBRW_PLUGIN_FILE', __DIR__ . '/../google-reviews.php' );
define( 'GBRW_PLUGIN_DIR', __DIR__ . '/../' );
define( 'GBRW_PLUGIN_URL', 'https://example.com/wp-content/plugins/google-reviews/' );
define( 'GBRW_PLUGIN_BASENAME', 'google-reviews/google-reviews.php' );
define( 'GBRW_CONNECT_SERVICE_URL', 'https://connect.example.com' );

// GBRW_ENCRYPTION_KEY is deliberately NOT defined here: it is optional, set by
// the site owner in wp-config.php, and every use is behind a defined() guard.
