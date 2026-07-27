<?php
/**
 * The Google OAuth authorization-code flow.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Google;

use GoogleReviews\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Starts authorization, handles the return trip, and refreshes access tokens.
 *
 * Two modes, chosen by Credentials::mode():
 *
 * MANAGED — the customer clicks once. The browser goes to our connect service,
 *           which sends them to Google, exchanges the code with our client
 *           secret, and hands the tokens back through a one-time ticket. The
 *           customer needs no Google Cloud project, and Google never sees this
 *           site's URL, so local and staging sites work too.
 *
 * OWN     — the site owner supplied their own client ID and secret, so the
 *           exchange happens here. Development and advanced use only.
 */
class OAuth {

	private const AUTH_ENDPOINT     = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_ENDPOINT    = 'https://oauth2.googleapis.com/token';
	private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/userinfo';

	private const STATE_TRANSIENT = 'gbrw_oauth_state_';
	private const STATE_TTL       = 900;

	/**
	 * Scopes requested at authorization time.
	 *
	 * The business.manage scope is what actually reads reviews; openid and email
	 * are only used to show the connected account back to the user.
	 */
	private const SCOPES = 'https://www.googleapis.com/auth/business.manage openid email';

	/**
	 * Where to send the browser to begin authorization.
	 *
	 * @return string|null Null when no credentials are configured.
	 */
	public static function authorize_url(): ?string {
		$mode = Credentials::mode();

		if ( Credentials::MODE_MANAGED === $mode ) {
			return self::managed_authorize_url();
		}

		if ( Credentials::MODE_OWN === $mode ) {
			return self::own_authorize_url();
		}

		return null;
	}

	/**
	 * Hand off to the connect service.
	 *
	 * @return string
	 */
	private static function managed_authorize_url(): string {
		return add_query_arg(
			rawurlencode_deep(
				array(
					'site'   => Credentials::site_origin(),
					'nonce'  => self::issue_state(),
					'return' => Credentials::redirect_uri(),
				)
			),
			Credentials::connect_service_url() . '/authorize.php'
		);
	}

	/**
	 * Go straight to Google using the site's own client ID.
	 *
	 * @return string
	 */
	private static function own_authorize_url(): string {
		return add_query_arg(
			rawurlencode_deep(
				array(
					'client_id'              => Credentials::client_id(),
					'redirect_uri'           => Credentials::redirect_uri(),
					'response_type'          => 'code',
					'scope'                  => self::SCOPES,
					'access_type'            => 'offline',
					// Force a refresh token even on a repeat authorization.
					'prompt'                 => 'consent',
					'include_granted_scopes' => 'true',
					'state'                  => self::issue_state(),
				)
			),
			self::AUTH_ENDPOINT
		);
	}

	/**
	 * Generate and remember a one-time state/nonce value.
	 *
	 * @return string
	 */
	private static function issue_state(): string {
		$state = wp_generate_password( 32, false, false );

		set_transient( self::STATE_TRANSIENT . get_current_user_id(), $state, self::STATE_TTL );

		return $state;
	}

	/**
	 * Consume the stored state, returning it exactly once.
	 *
	 * @return string Empty string when absent.
	 */
	private static function take_state(): string {
		$key    = self::STATE_TRANSIENT . get_current_user_id();
		$stored = get_transient( $key );

		delete_transient( $key );

		return is_string( $stored ) ? $stored : '';
	}

	/**
	 * Handle the return trip onto the settings screen.
	 *
	 * Runs on admin_init so it can redirect before any output.
	 *
	 * @return void
	 */
	public static function maybe_handle_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- CSRF is covered by the single-use state/nonce validated below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'gbrw-settings' !== $page ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$ticket = isset( $_GET['gbrw_ticket'] ) ? sanitize_text_field( wp_unslash( $_GET['gbrw_ticket'] ) ) : '';
		$error  = isset( $_GET['gbrw_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gbrw_error'] ) ) : '';
		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state  = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';

		if ( '' === $error && isset( $_GET['error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $error ) {
			self::fail( self::explain_authorization_error( $error ) );
		}

		if ( '' !== $ticket ) {
			self::complete_managed( $ticket );
		}

		if ( '' !== $code ) {
			self::complete_own( $code, $state );
		}
	}

	/**
	 * Redeem a connect-service ticket and store the resulting tokens.
	 *
	 * @param string $ticket One-time ticket from the connect service.
	 * @return never
	 */
	private static function complete_managed( string $ticket ) {
		$nonce = self::take_state();

		if ( '' === $nonce ) {
			self::fail( __( 'This connection attempt could not be verified, or it took too long. Please try again.', 'google-reviews-widget' ) );
		}

		$response = wp_remote_post(
			Credentials::connect_service_url() . '/claim.php',
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'ticket' => $ticket,
					'site'   => Credentials::site_origin(),
					'nonce'  => $nonce,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'Could not reach the connect service.', array( 'reason' => $response->get_error_message() ) );

			self::fail( __( 'Could not reach the connection service. Check that this server can make outbound HTTPS requests, then try again.', 'google-reviews-widget' ) );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			self::fail( __( 'The connection service returned an unreadable response.', 'google-reviews-widget' ) );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || isset( $data['error'] ) ) {
			$reason = isset( $data['error'] ) ? (string) $data['error'] : 'unknown';

			Logger::error( 'The connect service refused the ticket.', array( 'reason' => $reason ) );

			self::fail( __( 'The connection could not be completed. Please start again from this page.', 'google-reviews-widget' ) );
		}

		self::store_tokens(
			isset( $data['access_token'] ) ? (string) $data['access_token'] : '',
			isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '',
			isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600,
			isset( $data['scope'] ) ? (string) $data['scope'] : '',
			isset( $data['email'] ) ? (string) $data['email'] : ''
		);
	}

	/**
	 * Exchange an authorization code using the site's own credentials.
	 *
	 * @param string $code  Authorization code from Google.
	 * @param string $state State parameter returned by Google.
	 * @return never
	 */
	private static function complete_own( string $code, string $state ) {
		$expected = self::take_state();

		if ( '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::fail( __( 'The authorization response could not be verified. Please start the connection again.', 'google-reviews-widget' ) );
		}

		$secret = Credentials::client_secret();

		if ( null === $secret ) {
			self::fail( __( 'The stored Google client secret could not be read.', 'google-reviews-widget' ) );
		}

		$tokens = self::token_request(
			array(
				'code'          => $code,
				'client_id'     => Credentials::client_id(),
				'client_secret' => $secret,
				'redirect_uri'  => Credentials::redirect_uri(),
				'grant_type'    => 'authorization_code',
			)
		);

		if ( is_wp_error( $tokens ) ) {
			self::fail( $tokens->get_error_message() );
		}

		self::store_tokens(
			$tokens['access_token'],
			$tokens['refresh_token'],
			$tokens['expires_in'],
			$tokens['scope'],
			self::fetch_account_email( $tokens['access_token'] )
		);
	}

	/**
	 * Validate and persist a freshly issued token set, then redirect.
	 *
	 * @param string $access_token  Access token.
	 * @param string $refresh_token Refresh token.
	 * @param int    $expires_in    Access token lifetime.
	 * @param string $scope         Granted scopes.
	 * @param string $email         Connected account email.
	 * @return never
	 */
	private static function store_tokens( string $access_token, string $refresh_token, int $expires_in, string $scope, string $email ) {
		if ( '' === $access_token ) {
			self::fail( __( 'Google did not return an access token. Please try connecting again.', 'google-reviews-widget' ) );
		}

		if ( '' === $refresh_token ) {
			self::fail( __( 'Google did not return a refresh token. Remove this app from your Google account permissions and connect again.', 'google-reviews-widget' ) );
		}

		if ( false === strpos( $scope, 'business.manage' ) ) {
			self::fail( __( 'The Business Profile permission was not granted. Please connect again and leave every permission ticked.', 'google-reviews-widget' ) );
		}

		if ( ! Connection::store( $access_token, $refresh_token, $expires_in, $email, $scope ) ) {
			self::fail( __( 'The connection could not be saved securely. Check that the Sodium extension is enabled.', 'google-reviews-widget' ) );
		}

		wp_safe_redirect( add_query_arg( 'gbrw_connected', '1', admin_url( 'admin.php?page=gbrw-settings' ) ) );
		exit;
	}

	/**
	 * Exchange the stored refresh token for a fresh access token.
	 *
	 * @return string|\WP_Error The new access token, or an error.
	 */
	public static function refresh_access_token() {
		$refresh = Connection::refresh_token();

		if ( null === $refresh ) {
			return new \WP_Error( 'gbrw_no_refresh_token', __( 'No Google refresh token is stored. Please reconnect.', 'google-reviews-widget' ) );
		}

		$tokens = Credentials::MODE_MANAGED === Credentials::mode()
			? self::managed_refresh( $refresh )
			: self::own_refresh( $refresh );

		if ( is_wp_error( $tokens ) ) {
			if ( 'gbrw_invalid_grant' === $tokens->get_error_code() ) {
				Connection::mark_failed(
					Connection::STATUS_REVOKED,
					__( 'Google access was revoked or expired. Please reconnect.', 'google-reviews-widget' )
				);
			}

			return $tokens;
		}

		// A refresh response never includes a new refresh token; keep the old one.
		Connection::store(
			$tokens['access_token'],
			$refresh,
			$tokens['expires_in'],
			Connection::account_email(),
			'' !== $tokens['scope'] ? $tokens['scope'] : self::SCOPES
		);

		return $tokens['access_token'];
	}

	/**
	 * Refresh via the connect service.
	 *
	 * @param string $refresh_token Stored refresh token.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function managed_refresh( string $refresh_token ) {
		$response = wp_remote_post(
			Credentials::connect_service_url() . '/refresh.php',
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'refresh_token' => $refresh_token,
					'site'          => Credentials::site_origin(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'gbrw_http_error',
				__( 'Could not reach the connection service. Your reviews will keep displaying from this site in the meantime.', 'google-reviews-widget' )
			);
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'gbrw_bad_response', __( 'The connection service returned an unreadable response.', 'google-reviews-widget' ) );
		}

		if ( isset( $data['error'] ) ) {
			$code = (string) $data['error'];

			return new \WP_Error(
				'invalid_grant' === $code ? 'gbrw_invalid_grant' : 'gbrw_token_error',
				self::explain_token_error( $code, isset( $data['error_description'] ) ? (string) $data['error_description'] : '' )
			);
		}

		return array(
			'access_token' => isset( $data['access_token'] ) ? (string) $data['access_token'] : '',
			'expires_in'   => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600,
			'scope'        => isset( $data['scope'] ) ? (string) $data['scope'] : '',
		);
	}

	/**
	 * Refresh directly against Google using the site's own credentials.
	 *
	 * @param string $refresh_token Stored refresh token.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function own_refresh( string $refresh_token ) {
		$secret = Credentials::client_secret();

		if ( null === $secret ) {
			return new \WP_Error( 'gbrw_no_secret', __( 'The stored Google client secret could not be read.', 'google-reviews-widget' ) );
		}

		return self::token_request(
			array(
				'refresh_token' => $refresh_token,
				'client_id'     => Credentials::client_id(),
				'client_secret' => $secret,
				'grant_type'    => 'refresh_token',
			)
		);
	}

	/**
	 * POST to Google's token endpoint and normalise the response.
	 *
	 * @param array<string, string> $body Request body.
	 * @return array<string, mixed>|\WP_Error Token set, or an error.
	 */
	private static function token_request( array $body ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 20,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::error( 'Google token request failed.', array( 'reason' => $response->get_error_message() ) );

			return new \WP_Error(
				'gbrw_http_error',
				__( 'Could not reach Google. Check that this server can make outbound HTTPS requests.', 'google-reviews-widget' )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'gbrw_bad_response', __( 'Google returned an unreadable response.', 'google-reviews-widget' ) );
		}

		if ( 200 !== $status || isset( $data['error'] ) ) {
			$code = isset( $data['error'] ) ? (string) $data['error'] : 'http_' . $status;

			Logger::error(
				'Google rejected the token request.',
				array(
					'error'  => $code,
					'status' => $status,
				)
			);

			return new \WP_Error(
				'invalid_grant' === $code ? 'gbrw_invalid_grant' : 'gbrw_token_error',
				self::explain_token_error( $code, isset( $data['error_description'] ) ? (string) $data['error_description'] : '' )
			);
		}

		return array(
			'access_token'  => isset( $data['access_token'] ) ? (string) $data['access_token'] : '',
			'refresh_token' => isset( $data['refresh_token'] ) ? (string) $data['refresh_token'] : '',
			'expires_in'    => isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600,
			'scope'         => isset( $data['scope'] ) ? (string) $data['scope'] : '',
		);
	}

	/**
	 * Explain why authorization did not complete.
	 *
	 * @param string $code Error code from Google or the connect service.
	 * @return string Human-readable message.
	 */
	private static function explain_authorization_error( string $code ): string {
		if ( 'access_denied' === $code ) {
			return __( 'Authorization was cancelled in Google.', 'google-reviews-widget' );
		}

		return self::explain_token_error( $code, '' );
	}

	/**
	 * Turn a Google error code into something a site owner can act on.
	 *
	 * @param string $code        Google error code.
	 * @param string $description Google error description.
	 * @return string Human-readable message.
	 */
	private static function explain_token_error( string $code, string $description ): string {
		$known = array(
			'invalid_client'        => __( 'Google rejected the client ID or client secret. Check both values in your Google Cloud Console credentials.', 'google-reviews-widget' ),
			'redirect_uri_mismatch' => __( 'The redirect URI does not match the one registered in Google Cloud Console. Copy the exact URI shown on this page into your OAuth client.', 'google-reviews-widget' ),
			'invalid_grant'         => __( 'Google rejected the authorization. This usually means access was revoked, or the app is still in Testing mode, where Google expires access after 7 days. Please reconnect.', 'google-reviews-widget' ),
			'unauthorized_client'   => __( 'This OAuth client is not allowed to use this grant type. Make sure the credential type is "Web application".', 'google-reviews-widget' ),
			'network_error'         => __( 'The connection service could not reach Google. Please try again shortly.', 'google-reviews-widget' ),
		);

		if ( isset( $known[ $code ] ) ) {
			return $known[ $code ];
		}

		return '' !== $description
			? sprintf(
				/* translators: 1: Google error code, 2: Google error description */
				__( 'Google returned "%1$s": %2$s', 'google-reviews-widget' ),
				$code,
				$description
			)
			: sprintf(
				/* translators: %s: Google error code */
				__( 'Google returned an error: %s', 'google-reviews-widget' ),
				$code
			);
	}

	/**
	 * Look up the email address of the account that granted access.
	 *
	 * Best effort: a missing email is cosmetic, not fatal.
	 *
	 * @param string $access_token Valid access token.
	 * @return string Email address, or an empty string.
	 */
	private static function fetch_account_email( string $access_token ): string {
		$response = wp_remote_get(
			self::USERINFO_ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) && isset( $data['email'] ) ? (string) $data['email'] : '';
	}

	/**
	 * Abort the connection attempt with a message on the settings screen.
	 *
	 * @param string $message Operator-facing reason.
	 * @return never
	 */
	private static function fail( string $message ) {
		set_transient( 'gbrw_oauth_error_' . get_current_user_id(), $message, 120 );

		wp_safe_redirect( admin_url( 'admin.php?page=gbrw-settings' ) );
		exit;
	}
}
