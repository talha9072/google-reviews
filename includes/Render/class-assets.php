<?php
/**
 * Front-end asset loading.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the widget stylesheet only on pages that actually contain a widget, and
 * the carousel script only when a carousel is present.
 *
 * Grid, list, and badge widgets ship zero JavaScript.
 */
class Assets {

	/**
	 * Whether the handles have already been defined.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register handles early so they can be enqueued from inside a shortcode.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_handles' ) );
	}

	/**
	 * Define the handles without enqueuing them.
	 *
	 * @return void
	 */
	public static function register_handles(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		wp_register_style(
			'gbrw-widget',
			GBRW_PLUGIN_URL . 'assets/css/widget.css',
			array(),
			GBRW_VERSION
		);

		wp_register_script(
			'gbrw-carousel',
			GBRW_PLUGIN_URL . 'assets/js/carousel.js',
			array(),
			GBRW_VERSION,
			true
		);
	}

	/**
	 * Enqueue what this widget needs.
	 *
	 * Called during rendering, which can be after wp_head. WordPress prints a
	 * late-enqueued stylesheet in the footer rather than dropping it, which is
	 * what makes this safe inside page builders that render content late.
	 *
	 * @param array<string, mixed> $settings Sanitised widget settings.
	 * @return void
	 */
	public static function enqueue_for( array $settings ): void {
		self::register_handles();

		wp_enqueue_style( 'gbrw-widget' );

		if ( 'carousel' === ( $settings['layout'] ?? '' ) ) {
			wp_enqueue_script( 'gbrw-carousel' );
		}
	}
}
