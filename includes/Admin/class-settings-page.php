<?php
/**
 * Settings screen, including the Google connection panel.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Admin;

use GoogleReviews\Crypto;
use GoogleReviews\Google\Connection;
use GoogleReviews\Google\Credentials;
use GoogleReviews\Google\OAuth;
use GoogleReviews\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Connect Google, tune sync behaviour, and inspect system health.
 */
class SettingsPage {

	private const CAPABILITY = 'manage_options';
	private const NONCE      = 'gbrw_save_settings';

	/**
	 * Register the form handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_gbrw_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_gbrw_disconnect', array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_gbrw_save_credentials', array( __CLASS__, 'handle_save_credentials' ) );
		add_action( 'admin_post_gbrw_clear_credentials', array( __CLASS__, 'handle_clear_credentials' ) );
		add_action( 'admin_post_gbrw_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_init', array( OAuth::class, 'maybe_handle_callback' ) );
	}

	/**
	 * Store the site's own OAuth client credentials.
	 *
	 * @return void
	 */
	public static function handle_save_credentials(): void {
		self::guard( 'gbrw_save_credentials' );

		// Nonce and capability are both verified in self::guard() above; PHPCS
		// cannot see through the helper.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';

		// Deliberately not sanitize_text_field: a client secret is opaque and must
		// survive byte-for-byte. The charset is validated against an allowlist
		// below instead, which is stricter than sanitising would be.
		// Nonce and capability are both checked in self::guard() above.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$client_secret = isset( $_POST['client_secret'] ) ? trim( (string) wp_unslash( $_POST['client_secret'] ) ) : '';

		if ( '' === $client_id ) {
			self::redirect_with_error( __( 'The client ID is required.', 'google-reviews-widget' ) );
		}

		// An empty secret means "keep the one already stored".
		if ( '' === $client_secret ) {
			$existing = Credentials::client_secret();

			if ( null === $existing ) {
				self::redirect_with_error( __( 'The client secret is required.', 'google-reviews-widget' ) );
			}

			$client_secret = $existing;
		}

		if ( ! preg_match( '/^[A-Za-z0-9._\-]+$/', $client_secret ) ) {
			self::redirect_with_error( __( 'That client secret contains unexpected characters. Copy it again from Google Cloud Console.', 'google-reviews-widget' ) );
		}

		if ( ! Credentials::save( $client_id, $client_secret ) ) {
			self::redirect_with_error( __( 'The credentials could not be saved securely. Check that the Sodium extension is enabled.', 'google-reviews-widget' ) );
		}

		wp_safe_redirect( add_query_arg( 'gbrw_saved', '1', admin_url( 'admin.php?page=gbrw-settings' ) ) );
		exit;
	}

	/**
	 * Remove the site's own OAuth client credentials.
	 *
	 * @return void
	 */
	public static function handle_clear_credentials(): void {
		self::guard( 'gbrw_clear_credentials' );

		Connection::disconnect();
		Credentials::clear();

		wp_safe_redirect( add_query_arg( 'gbrw_saved', '1', admin_url( 'admin.php?page=gbrw-settings' ) ) );
		exit;
	}

	/**
	 * Send the user to Google's consent screen.
	 *
	 * @return void
	 */
	public static function handle_connect(): void {
		self::guard( 'gbrw_connect' );

		$url = OAuth::authorize_url();

		if ( null === $url ) {
			self::redirect_with_error( __( 'No Google credentials are configured yet.', 'google-reviews-widget' ) );
		}

		// Off-site redirect to accounts.google.com, so wp_safe_redirect cannot be used.
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		wp_redirect( $url );
		exit;
	}

	/**
	 * Shared capability and nonce check for form handlers.
	 *
	 * @param string $nonce_action Nonce action name.
	 * @return void
	 */
	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'google-reviews-widget' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Bounce back to the settings screen with an error message.
	 *
	 * @param string $message Operator-facing reason.
	 * @return never
	 */
	private static function redirect_with_error( string $message ) {
		set_transient( 'gbrw_oauth_error_' . get_current_user_id(), $message, 120 );

		wp_safe_redirect( admin_url( 'admin.php?page=gbrw-settings' ) );
		exit;
	}

	/**
	 * Persist submitted settings, then redirect.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'google-reviews-widget' ) );
		}

		check_admin_referer( self::NONCE );

		$allowed_intervals = array( '6h', '12h', 'daily', 'weekly' );
		$allowed_dates     = array( 'relative', 'absolute' );

		$interval = isset( $_POST['sync_interval'] )
			? sanitize_key( wp_unslash( $_POST['sync_interval'] ) )
			: 'daily';

		$date_display = isset( $_POST['date_display'] )
			? sanitize_key( wp_unslash( $_POST['date_display'] ) )
			: 'relative';

		Settings::update(
			array(
				'sync_interval'            => in_array( $interval, $allowed_intervals, true ) ? $interval : 'daily',
				'date_display'             => in_array( $date_display, $allowed_dates, true ) ? $date_display : 'relative',
				'show_attribution'         => isset( $_POST['show_attribution'] ),
				'propagate_deletions'      => isset( $_POST['propagate_deletions'] ),
				'debug_logging'            => isset( $_POST['debug_logging'] ),
				'delete_data_on_uninstall' => isset( $_POST['delete_data_on_uninstall'] ),
			)
		);

		wp_safe_redirect( add_query_arg( 'gbrw_saved', '1', admin_url( 'admin.php?page=gbrw-settings' ) ) );
		exit;
	}

	/**
	 * Remove the stored Google connection.
	 *
	 * @return void
	 */
	public static function handle_disconnect(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'google-reviews-widget' ) );
		}

		check_admin_referer( 'gbrw_disconnect' );

		Connection::disconnect();

		wp_safe_redirect( add_query_arg( 'gbrw_disconnected', '1', admin_url( 'admin.php?page=gbrw-settings' ) ) );
		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'google-reviews-widget' ) );
		}

		echo '<div class="wrap gbrw-wrap">';
		echo '<h1>' . esc_html__( 'Google Reviews Settings', 'google-reviews-widget' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		if ( isset( $_GET['gbrw_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'google-reviews-widget' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		if ( isset( $_GET['gbrw_disconnected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Google account disconnected. Your imported reviews were kept.', 'google-reviews-widget' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by our own redirect.
		if ( isset( $_GET['gbrw_connected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Google connected successfully.', 'google-reviews-widget' ) . '</p></div>';
		}

		$error = get_transient( 'gbrw_oauth_error_' . get_current_user_id() );

		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( 'gbrw_oauth_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		self::render_connection_panel();
		self::render_settings_form();
		self::render_system_status();

		echo '</div>';
	}

	/**
	 * The Google connection panel.
	 *
	 * @return void
	 */
	private static function render_connection_panel(): void {
		$connected = Connection::is_connected();

		echo '<div class="gbrw-panel">';
		echo '<h2>' . esc_html__( 'Google connection', 'google-reviews-widget' ) . '</h2>';

		if ( $connected ) {
			echo '<p class="gbrw-status gbrw-status--ok">' . esc_html__( 'Connected', 'google-reviews-widget' ) . '</p>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: Google account email address */
					__( 'Connected as %s', 'google-reviews-widget' ),
					Connection::account_email()
				)
			) . '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'gbrw_disconnect' );
			echo '<input type="hidden" name="action" value="gbrw_disconnect" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Disconnect', 'google-reviews-widget' ) . '</button>';
			echo '</form>';
			echo '</div>';

			return;
		}

		echo '<p class="gbrw-status gbrw-status--warn">' . esc_html__( 'Not connected', 'google-reviews-widget' ) . '</p>';
		echo '<p>' . esc_html__( 'Connect the Google account that owns or manages your Business Profile. Your reviews are imported into this site and stay on your own server.', 'google-reviews-widget' ) . '</p>';

		$last_error = Connection::last_error();

		if ( '' !== $last_error ) {
			echo '<p class="gbrw-status gbrw-status--error">' . esc_html( $last_error ) . '</p>';
		}

		$mode = Credentials::mode();

		if ( Credentials::MODE_NONE === $mode ) {
			echo '<p><button type="button" class="button button-primary button-hero" disabled>';
			echo esc_html__( 'Connect Google', 'google-reviews-widget' );
			echo '</button></p>';
			echo '<div class="gbrw-notice gbrw-notice--warn">';
			echo '<p><strong>' . esc_html__( 'This copy of the plugin is not configured.', 'google-reviews-widget' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Connecting is normally a single click and needs nothing from you. This build was packaged without its connection address, which is a fault on our side rather than anything you have done.', 'google-reviews-widget' ) . '</p>';
			echo '<p>' . esc_html__( 'Please contact support and quote "connect service not configured".', 'google-reviews-widget' ) . '</p>';
			echo '</div>';
		} elseif ( Credentials::MODE_OWN === $mode && ! Credentials::redirect_uri_usable() ) {
			echo '<p><button type="button" class="button button-primary button-hero" disabled>';
			echo esc_html__( 'Connect Google', 'google-reviews-widget' );
			echo '</button></p>';
			echo '<div class="gbrw-notice gbrw-notice--warn">';
			echo '<p><strong>' . esc_html__( 'This site\'s address cannot be used with Google sign-in.', 'google-reviews-widget' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Google only accepts HTTPS redirect URIs on real public domains, plus http://localhost. Development hostnames ending in .local or .test are rejected. This limitation applies only when using your own Google credentials.', 'google-reviews-widget' ) . '</p>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: this site's redirect URI */
					__( 'This site would use: %s', 'google-reviews-widget' ),
					Credentials::redirect_uri()
				)
			) . '</p>';
			echo '<p>' . esc_html__( 'Either expose this site over a public HTTPS URL, or use a hosted connect service, which works on any address.', 'google-reviews-widget' ) . '</p>';
			echo '</div>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'gbrw_connect' );
			echo '<input type="hidden" name="action" value="gbrw_connect" />';
			echo '<button type="submit" class="button button-primary button-hero">';
			echo esc_html__( 'Connect Google', 'google-reviews-widget' );
			echo '</button>';
			echo '</form>';
		}

		echo '<h3>' . esc_html__( 'What you will need', 'google-reviews-widget' ) . '</h3>';
		echo '<ul class="gbrw-list">';
		echo '<li>' . esc_html__( 'A Google account that is an owner or manager of the Business Profile.', 'google-reviews-widget' ) . '</li>';
		echo '<li>' . esc_html__( 'The business listing must be verified in Google Business Profile.', 'google-reviews-widget' ) . '</li>';
		echo '<li>' . esc_html__( 'Google Business Profile API access approved for the Cloud project in use (this is a separate application to Google, and takes about 14 days).', 'google-reviews-widget' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'If you manage the listing for a client, ask them to add you as a manager in Google Business Profile first.', 'google-reviews-widget' ) . '</p>';

		echo '</div>';

		self::render_credentials_panel();
	}

	/**
	 * The "use your own Google Cloud project" panel.
	 *
	 * @return void
	 */
	private static function render_credentials_panel(): void {
		// Customers never see this panel. Connecting is one click through the
		// hosted service; asking them for Google Cloud credentials would lose
		// the sale. It appears only when GBRW_DEV_MODE is switched on in
		// wp-config.php, or when credentials were already saved on this site.
		$dev_mode = defined( 'GBRW_DEV_MODE' ) && GBRW_DEV_MODE;

		if ( ! $dev_mode && ! Credentials::has_own_credentials() ) {
			return;
		}

		$has = Credentials::has_own_credentials();

		echo '<div class="gbrw-panel">';
		echo '<h2>' . esc_html__( 'Your own Google credentials', 'google-reviews-widget' ) . '</h2>';
		echo '<p>' . esc_html__( 'Use a Google Cloud project you control instead of a hosted connect service. Suitable for development and for anyone who prefers not to depend on a third party.', 'google-reviews-widget' ) . '</p>';

		echo '<h3>' . esc_html__( 'Redirect URI', 'google-reviews-widget' ) . '</h3>';
		echo '<p>' . esc_html__( 'Add this exact URI to your OAuth client in Google Cloud Console, under "Authorised redirect URIs":', 'google-reviews-widget' ) . '</p>';
		echo '<p><input type="text" class="large-text code" readonly onfocus="this.select()" value="' . esc_attr( Credentials::redirect_uri() ) . '" /></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gbrw_save_credentials' );
		echo '<input type="hidden" name="action" value="gbrw_save_credentials" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="gbrw-client-id">' . esc_html__( 'Client ID', 'google-reviews-widget' ) . '</label></th><td>';
		echo '<input type="text" id="gbrw-client-id" name="client_id" class="large-text code" autocomplete="off" value="' . esc_attr( Credentials::client_id() ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="gbrw-client-secret">' . esc_html__( 'Client secret', 'google-reviews-widget' ) . '</label></th><td>';
		echo '<input type="password" id="gbrw-client-secret" name="client_secret" class="large-text code" autocomplete="off" value="" />';
		echo '<p class="description">';
		echo $has
			? esc_html__( 'A secret is stored and encrypted. Leave blank to keep it, or paste a new one to replace it.', 'google-reviews-widget' )
			: esc_html__( 'Stored encrypted. It is never shown again and never sent to the browser.', 'google-reviews-widget' );
		echo '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save credentials', 'google-reviews-widget' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( $has ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px;">';
			wp_nonce_field( 'gbrw_clear_credentials' );
			echo '<input type="hidden" name="action" value="gbrw_clear_credentials" />';
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Remove credentials', 'google-reviews-widget' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'This also disconnects Google. Imported reviews are kept.', 'google-reviews-widget' ) . '</p>';
			echo '</form>';
		}

		echo '<div class="gbrw-notice gbrw-notice--warn">';
		echo '<p><strong>' . esc_html__( 'Keep your OAuth consent screen published.', 'google-reviews-widget' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'While an app is in "Testing" mode Google expires its refresh tokens after 7 days, which means reconnecting every week. Set the consent screen to "In production" to avoid that.', 'google-reviews-widget' ) . '</p>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * The settings form.
	 *
	 * @return void
	 */
	private static function render_settings_form(): void {
		$settings = Settings::all();

		$intervals = array(
			'6h'     => __( 'Every 6 hours', 'google-reviews-widget' ),
			'12h'    => __( 'Every 12 hours', 'google-reviews-widget' ),
			'daily'  => __( 'Once a day (recommended)', 'google-reviews-widget' ),
			'weekly' => __( 'Once a week', 'google-reviews-widget' ),
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="gbrw_save_settings" />';

		echo '<div class="gbrw-panel">';
		echo '<h2>' . esc_html__( 'Sync and display', 'google-reviews-widget' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="gbrw-sync-interval">' . esc_html__( 'Check for new reviews', 'google-reviews-widget' ) . '</label></th><td>';
		echo '<select id="gbrw-sync-interval" name="sync_interval">';
		foreach ( $intervals as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $settings['sync_interval'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Google reviews change slowly. Daily is enough for almost every business.', 'google-reviews-widget' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Review dates', 'google-reviews-widget' ) . '</th><td>';
		echo '<fieldset>';
		printf(
			'<label><input type="radio" name="date_display" value="relative"%s> %s</label><br>',
			checked( $settings['date_display'], 'relative', false ),
			esc_html__( 'Relative — "3 weeks ago"', 'google-reviews-widget' )
		);
		printf(
			'<label><input type="radio" name="date_display" value="absolute"%s> %s</label>',
			checked( $settings['date_display'], 'absolute', false ),
			esc_html__( 'Absolute — "12 June 2026"', 'google-reviews-widget' )
		);
		echo '</fieldset></td></tr>';

		self::checkbox_row(
			'show_attribution',
			__( 'Google attribution', 'google-reviews-widget' ),
			__( 'Show the "Reviews from Google" attribution', 'google-reviews-widget' ),
			__( 'Keep this on. Attribution is required when displaying Google review content.', 'google-reviews-widget' ),
			(bool) $settings['show_attribution']
		);

		self::checkbox_row(
			'propagate_deletions',
			__( 'Deleted reviews', 'google-reviews-widget' ),
			__( 'Hide reviews that have been removed from Google', 'google-reviews-widget' ),
			__( 'Only applied after a fully successful sync, so a failed run can never wipe your reviews.', 'google-reviews-widget' ),
			(bool) $settings['propagate_deletions']
		);

		echo '</tbody></table></div>';

		echo '<div class="gbrw-panel">';
		echo '<h2>' . esc_html__( 'Advanced', 'google-reviews-widget' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		self::checkbox_row(
			'debug_logging',
			__( 'Debug logging', 'google-reviews-widget' ),
			__( 'Write detailed diagnostics to the log file', 'google-reviews-widget' ),
			__( 'Tokens and personal data are always redacted before anything is written.', 'google-reviews-widget' ),
			(bool) $settings['debug_logging']
		);

		self::checkbox_row(
			'delete_data_on_uninstall',
			__( 'On uninstall', 'google-reviews-widget' ),
			__( 'Delete all reviews and settings when this plugin is deleted', 'google-reviews-widget' ),
			__( 'Off by default. Leaving it off means reinstalling restores everything.', 'google-reviews-widget' ),
			(bool) $settings['delete_data_on_uninstall']
		);

		echo '</tbody></table></div>';

		submit_button( __( 'Save settings', 'google-reviews-widget' ) );
		echo '</form>';
	}

	/**
	 * Output one checkbox row in a form table.
	 *
	 * @param string $name        Field name.
	 * @param string $title       Row heading.
	 * @param string $label       Checkbox label.
	 * @param string $description Help text.
	 * @param bool   $checked     Current value.
	 * @return void
	 */
	private static function checkbox_row( string $name, string $title, string $label, string $description, bool $checked ): void {
		echo '<tr><th scope="row">' . esc_html( $title ) . '</th><td><fieldset>';
		printf(
			'<label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
		echo '<p class="description">' . esc_html( $description ) . '</p>';
		echo '</fieldset></td></tr>';
	}

	/**
	 * System health, so support questions can be answered from one screen.
	 *
	 * @return void
	 */
	private static function render_system_status(): void {
		$key_source = Crypto::key_source();

		$key_labels = array(
			'constant'    => __( 'Dedicated encryption key', 'google-reviews-widget' ),
			'salts'       => __( 'Derived from this site\'s WordPress salts', 'google-reviews-widget' ),
			'unavailable' => __( 'Unavailable — the Sodium extension is missing', 'google-reviews-widget' ),
		);

		echo '<div class="gbrw-panel">';
		echo '<h2>' . esc_html__( 'System status', 'google-reviews-widget' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';

		self::status_row( __( 'Plugin version', 'google-reviews-widget' ), GBRW_VERSION );
		self::status_row( __( 'Database schema', 'google-reviews-widget' ), (string) get_option( 'gbrw_db_version', 0 ) );
		self::status_row( __( 'PHP version', 'google-reviews-widget' ), PHP_VERSION );

		$mode_labels = array(
			Credentials::MODE_MANAGED => sprintf(
				/* translators: %s: connect service base URL */
				__( 'Hosted connect service — %s', 'google-reviews-widget' ),
				Credentials::connect_service_url()
			),
			Credentials::MODE_OWN     => __( 'This site\'s own Google credentials', 'google-reviews-widget' ),
			Credentials::MODE_NONE    => __( 'Not configured — the Connect button is disabled', 'google-reviews-widget' ),
		);

		self::status_row(
			__( 'Connection method', 'google-reviews-widget' ),
			$mode_labels[ Credentials::mode() ] ?? ''
		);
		self::status_row(
			__( 'Token encryption', 'google-reviews-widget' ),
			$key_labels[ $key_source ] ?? $key_source
		);

		echo '</tbody></table>';

		if ( 'salts' === $key_source ) {
			echo '<p class="description">';
			// Plain-language for customers. The GBRW_ENCRYPTION_KEY constant that
			// avoids this is documented for developers, not surfaced here: asking
			// a customer to edit wp-config.php is exactly the friction that stops
			// a plugin selling.
			echo esc_html__( 'Your Google connection is encrypted using this site\'s built-in security keys. If those keys are ever regenerated, you will simply need to reconnect Google once.', 'google-reviews-widget' );
			echo '</p>';
		}

		if ( Crypto::has_weak_salts() ) {
			echo '<div class="gbrw-notice gbrw-notice--warn"><p>';
			echo esc_html__( 'This site is still using WordPress\'s default security keys, which are publicly known. Ask your developer or host to regenerate them before connecting Google.', 'google-reviews-widget' );
			echo '</p></div>';
		}

		echo '</div>';
	}

	/**
	 * Output one status row.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 * @return void
	 */
	private static function status_row( string $label, string $value ): void {
		printf(
			'<tr><td><strong>%1$s</strong></td><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}
}
