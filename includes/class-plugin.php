<?php
/**
 * Plugin bootstrap and hook wiring.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Central bootstrap.
 *
 * Deliberately thin: it wires hooks and hands off. No business logic lives here.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether hooks have already been registered.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Retrieve the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Wire up the plugin. Safe to call more than once.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( Install::class, 'maybe_upgrade' ) );
		add_filter( 'plugin_action_links_' . GBRW_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );

		Integrations\Shortcode::register();
		Render\Assets::register();

		if ( is_admin() ) {
			Admin\AdminMenu::register();
			Admin\SettingsPage::register();
			Admin\WidgetsPage::register();
			Admin\ReviewsPage::register();
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'google-reviews-widget',
			false,
			dirname( GBRW_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string> Filtered links.
	 */
	public function add_action_links( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=gbrw-settings' ) ),
			esc_html__( 'Settings', 'google-reviews-widget' )
		);

		array_unshift( $links, $settings );

		return $links;
	}
}
