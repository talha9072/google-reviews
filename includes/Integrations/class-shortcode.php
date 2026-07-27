<?php
/**
 * The [google_reviews_widget] shortcode.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Integrations;

use GoogleReviews\Data\WidgetsRepository;
use GoogleReviews\Render\Assets;
use GoogleReviews\Render\Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Works everywhere: Divi's Code Module, Elementor's Shortcode widget, Gutenberg,
 * classic editor, and template files via do_shortcode().
 */
class Shortcode {

	public const TAG = 'google_reviews_widget';

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render a widget by ID.
	 *
	 * Never throws and never emits a PHP error onto a customer's page: an
	 * unknown or unpublished widget renders as nothing.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string HTML.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array( 'id' => 0 ),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);

		$id = (int) $atts['id'];

		if ( $id <= 0 ) {
			return self::admin_hint( __( 'Add an id attribute, for example [google_reviews_widget id="1"].', 'google-reviews-widget' ) );
		}

		$widget = WidgetsRepository::find( $id );

		if ( ! $widget ) {
			return self::admin_hint(
				sprintf(
					/* translators: %d: widget ID */
					__( 'Google Reviews widget %d no longer exists.', 'google-reviews-widget' ),
					$id
				)
			);
		}

		if ( 'paused' === $widget->status ) {
			return '';
		}

		$settings = WidgetsRepository::published_settings( $widget );

		if ( empty( $settings ) ) {
			return self::admin_hint(
				sprintf(
					/* translators: %d: widget ID */
					__( 'Google Reviews widget %d has not been published yet.', 'google-reviews-widget' ),
					$id
				)
			);
		}

		Assets::enqueue_for( $settings );

		return Renderer::render( $id, $settings );
	}

	/**
	 * A message only administrators can see, so visitors never meet a
	 * configuration problem.
	 *
	 * @param string $message Explanation.
	 * @return string
	 */
	private static function admin_hint( string $message ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return '<div class="gbrw-root gbrw-empty"><p>' . esc_html( $message ) . '</p></div>';
	}
}
