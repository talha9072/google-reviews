<?php
/**
 * Registers the admin menu and routes each screen.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level "Google Reviews" menu and its subpages.
 */
class AdminMenu {

	private const CAPABILITY = 'manage_options';

	/**
	 * Hook into the admin.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the menu and its subpages.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		add_menu_page(
			__( 'Google Reviews', 'google-reviews-widget' ),
			__( 'Google Reviews', 'google-reviews-widget' ),
			self::CAPABILITY,
			'gbrw-dashboard',
			array( DashboardPage::class, 'render' ),
			'dashicons-star-filled',
			26
		);

		add_submenu_page(
			'gbrw-dashboard',
			__( 'Dashboard', 'google-reviews-widget' ),
			__( 'Dashboard', 'google-reviews-widget' ),
			self::CAPABILITY,
			'gbrw-dashboard',
			array( DashboardPage::class, 'render' )
		);

		add_submenu_page(
			'gbrw-dashboard',
			__( 'Reviews', 'google-reviews-widget' ),
			__( 'Reviews', 'google-reviews-widget' ),
			self::CAPABILITY,
			'gbrw-reviews',
			array( ReviewsPage::class, 'render' )
		);

		add_submenu_page(
			'gbrw-dashboard',
			__( 'Widgets', 'google-reviews-widget' ),
			__( 'Widgets', 'google-reviews-widget' ),
			self::CAPABILITY,
			'gbrw-widgets',
			array( WidgetsPage::class, 'render' )
		);

		add_submenu_page(
			'gbrw-dashboard',
			__( 'Settings', 'google-reviews-widget' ),
			__( 'Settings', 'google-reviews-widget' ),
			self::CAPABILITY,
			'gbrw-settings',
			array( SettingsPage::class, 'render' )
		);
	}

	/**
	 * Load admin styles on this plugin's screens only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'gbrw-' ) ) {
			return;
		}

		wp_enqueue_style(
			'gbrw-admin',
			GBRW_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			GBRW_VERSION
		);
	}

	/**
	 * Screens that arrive in a later phase.
	 *
	 * @return void
	 */
	public static function render_placeholder(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'google-reviews-widget' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing, no state change.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$titles = array(
			'gbrw-reviews' => __( 'Reviews', 'google-reviews-widget' ),
			'gbrw-widgets' => __( 'Widgets', 'google-reviews-widget' ),
		);

		$bodies = array(
			'gbrw-reviews' => __( 'Once you connect Google and import a location, every review lands here — searchable, filterable by rating and language, with hide and feature controls.', 'google-reviews-widget' ),
			'gbrw-widgets' => __( 'This is where you will build widgets: pick a layout, choose reviews, restyle it, preview it, and copy the shortcode.', 'google-reviews-widget' ),
		);

		$title = $titles[ $page ] ?? __( 'Google Reviews', 'google-reviews-widget' );
		$body  = $bodies[ $page ] ?? '';

		echo '<div class="wrap gbrw-wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		echo '<div class="gbrw-panel gbrw-panel--muted">';
		echo '<p><strong>' . esc_html__( 'Not built yet.', 'google-reviews-widget' ) . '</strong></p>';
		echo '<p>' . esc_html( $body ) . '</p>';
		echo '<p>' . esc_html__( 'Connecting Google comes first — everything on this screen depends on imported review data.', 'google-reviews-widget' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=gbrw-settings' ) ) . '">';
		echo esc_html__( 'Go to Settings', 'google-reviews-widget' );
		echo '</a></p>';
		echo '</div></div>';
	}
}
