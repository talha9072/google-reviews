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
 * Starts authorization, handles the callback, and refreshes access tokens.
 *
 * In MODE_OWN the token exchange happens here using the site's own client
 * secret. In MODE_MANAGED it is proxied through the hosted connect service,
 * which is the only party holding the secret.
 */
class OAuth {

	private const AUTH_ENDPOINT     = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_ENDPOINT    = 'https://oauth2.googleapis.com/token';
	private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/userinfo';

	private const STATE_TRANSIENT = 'gbrw_oauth_state_';
	private const STATE_TTL       = 600;

	/**
	 * Scopes requested at authorization time.
	 *
	 * The business.manage scope is what actually reads reviews; openid and email
	 * are only used to show the connected account back to the user.
	 */
	private const SCOPES = 'https://www.googleapis.com/auth/business.manage openid email';

	/**
	 * Build the Google consent-screen URL and remember the state token.
	 *
	 * @return string|null Null when the site has no usable credentials.
	 */
	public static function authorize_url(): ?string {
		if ( Credentials::MODE_OWN !== Credentials::mode() ) {
			// The managed flow starts at the connect service, not here.
			return null;
		}

		$state = wp_generate_password( 32, false, false );

		set_transient(
			self::STATE_TRANSIENT . get_current_user_id(),
			$state,
			self::STATE_TTL
		);

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
					'state'                  => $state,
				)
			),
			self::AUTH_ENDPOINT
		);
	}

	/**
	 * Handle Google's redirect back to the settings screen.
	 *
	 * Runs on admin_init so it can redirect before any output.
	 *
	 * @return void
	 */
	public static function maybe_handle_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- CSRF is covered by the OAuth state token validated below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'gbrw-settings' !== $page ) {
			return;
		}

		$has_code  = isset( $_GET['code'] );
		$has_error = isset( $_GET['error'] );

		if ( ! $has_code && ! $has_error ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $has_error ) {
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) );

			self::fail(
				'access_denied' === $error
					? __( 'Authorization was cancelled in Google.', 'google-reviews-widget' )
					: sprintf(
						/* translators: %s: error code returned by Google */
						__( 'Google returned an error: %s', 'google-reviews-widget' ),
						$error
					)
			);
		}

		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = get_transient( self::STATE_TRANSIENT . get_current_user_id() );

		delete_transient( self::STATE_TRANSIENT . get_current_user_id() );

		if ( ! is_string( $expected ) || '' === $expected || ! hash_equals( $expected, $state ) ) {
			self::fail( __( 'The authorization response could not be verified. Please start the connection again.', 'google-reviews-widget' ) );
		}

		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$tokens = self::exchange_code( $code );

		if ( is_wp_error( $tokens ) ) {
			self::fail( $tokens->get_error_message() );
		}

		if ( '' === $tokens['refresh_token'] ) {
			self::fail( __( 'Google did not return a refresh token. Remove this app from your Google account permissions and try connecting again.', 'google-reviews-widget' ) );
		}

		if ( false === strpos( $tokens['scope'], 'business.manage' ) ) {
			self::fail( __( 'The Business Profile permission was not granted. Please connect again and leave every permission ticked.', 'google-reviews-widget' ) );
		}

		$email = self::fetch_account_email( $tokens['access_token'] );

		$stored = Connection::store(
			$tokens['access_token'],
			$tokens['refresh_token'],
			$tokens['expires_in'],
			$email,
			$tokens['scope']
		);

		if ( ! $stored ) {
			self::fail( __( 'The connection could not be saved securely. Check that the Sodium extension is enabled.', 'google-reviews-widget' ) );
		}

		wp_safe_redirect( add_query_arg( 'gbrw_connected', '1', admin_url( 'admin.php?page=gbrw-settings' ) ) );
		exit;
	}

	/**
	 * Trade an authorization code for tokens.
	 *
	 * @param string $code Authorization code from Google.
	 * @return array<string, mixed>|\WP_Error Token set, or an error.
	 */
	private static function exchange_code( string $code ) {
		$secret = Credentials::client_secret();

		if ( null === $secret ) {
			return new \WP_Error( 'gbrw_no_secret', __( 'The stored Google client secret could not be read.', 'google-reviews-widget' ) );
		}

		return self::token_request(
			array(
				'code'          => $code,
				'client_id'     => Credentials::client_id(),
				'client_secret' => $secret,
				'redirect_uri'  => Credentials::redirect_uri(),
				'grant_type'    => 'authorization_code',
			)
		);
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

		$secret = Credentials::client_secret();

		if ( null === $secret ) {
			return new \WP_Error( 'gbrw_no_secret', __( 'The stored Google client secret could not be read.', 'google-reviews-widget' ) );
		}

		$tokens = self::token_request(
			array(
				'refresh_token' => $refresh,
				'client_id'     => Credentials::client_id(),
				'client_secret' => $secret,
				'grant_type'    => 'refresh_token',
			)
		);

		if ( is_wp_error( $tokens ) ) {
			if ( 'gbrw_invalid_grant' === $tokens->get_error_code() ) {
				Connection::mark_failed(
					Connection::STATUS_REVOKED,
					__( 'Google access was revoked or expired. Please reconnect.', 'google-reviews-widget' )
				);
			}

			return $tokens;
		}

		// A refresh response does not include a new refresh token; keep the old one.
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
	 * POST to Google's token endpoint and normalise the response.
	 *
	 * @param array<string, string> $body Request body.
	 * @return array<string, mixed>|\WP_Error Token set, or an error.
	 */
	private static function token_request( array $body ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 15,
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
			'invalid_grant'         => __( 'Google rejected the authorization. This usually means the code expired, or access was revoked. Please try connecting again.', 'google-reviews-widget' ),
			'unauthorized_client'   => __( 'This OAuth client is not allowed to use this grant type. Make sure the credential type is "Web application".', 'google-reviews-widget' ),
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
