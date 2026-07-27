<?php
/**
 * The one definition of what a widget's settings are.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Widget;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the editor, the preview, and the renderer.
 *
 * Anything not defined here is discarded, so a malformed or hostile settings
 * blob cannot reach the renderer.
 */
class SettingsSchema {

	public const LAYOUTS = array( 'grid', 'list', 'carousel', 'badge' );

	public const ORDERS = array( 'newest', 'oldest', 'highest', 'lowest', 'longest', 'random' );

	/**
	 * Default settings for a new widget.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Content.
			'layout'            => 'grid',
			'location_id'       => 0,
			'selection_mode'    => 'auto',
			'max_reviews'       => 9,
			'min_rating'        => 4,
			'require_text'      => true,
			'min_text_length'   => 0,
			'order'             => 'newest',

			// Layout.
			'columns_desktop'   => 3,
			'columns_tablet'    => 2,
			'columns_mobile'    => 1,
			'gap'               => 16,
			'max_width'         => 0,

			// Card.
			'card_background'   => '#ffffff',
			'card_border'       => '#e5e7eb',
			'card_radius'       => 10,
			'card_padding'      => 20,
			'card_shadow'       => true,

			// Typography.
			'font_size'         => 15,
			'text_color'        => '#1f2937',
			'muted_color'       => '#6b7280',
			'inherit_font'      => true,

			// Elements.
			'show_header'       => true,
			'show_avatar'       => true,
			'show_rating'       => true,
			'show_date'         => true,
			'show_reply'        => false,
			'text_limit'        => 220,
			'star_color'        => '#fbbc04',
			'star_empty_color'  => '#dadce0',

			// Carousel.
			'autoplay'          => true,
			'autoplay_interval' => 6000,
			'show_arrows'       => true,
			'show_dots'         => true,
		);
	}

	/**
	 * Coerce arbitrary input into a valid settings array.
	 *
	 * @param array<string, mixed> $input Raw settings.
	 * @return array<string, mixed> Sanitised settings.
	 */
	public static function sanitize( array $input ): array {
		$defaults = self::defaults();
		$clean    = array();

		foreach ( $defaults as $key => $default ) {
			$value = $input[ $key ] ?? $default;

			switch ( $key ) {
				case 'layout':
					$clean[ $key ] = in_array( $value, self::LAYOUTS, true ) ? $value : $default;
					break;

				case 'order':
					$clean[ $key ] = in_array( $value, self::ORDERS, true ) ? $value : $default;
					break;

				case 'selection_mode':
					$clean[ $key ] = in_array( $value, array( 'auto', 'manual' ), true ) ? $value : $default;
					break;

				case 'card_background':
				case 'card_border':
				case 'text_color':
				case 'muted_color':
				case 'star_color':
				case 'star_empty_color':
					$clean[ $key ] = self::sanitize_color( (string) $value, (string) $default );
					break;

				default:
					if ( is_bool( $default ) ) {
						$clean[ $key ] = (bool) $value;
						break;
					}

					$clean[ $key ] = self::clamp( $key, (int) $value, (int) $default );
					break;
			}
		}

		return $clean;
	}

	/**
	 * Keep numeric settings inside sane bounds.
	 *
	 * @param string $key      Setting name.
	 * @param int    $value    Submitted value.
	 * @param int    $fallback Value to use when the key has no bounds.
	 * @return int
	 */
	private static function clamp( string $key, int $value, int $fallback ): int {
		$bounds = array(
			'max_reviews'       => array( 1, 100 ),
			'min_rating'        => array( 1, 5 ),
			'min_text_length'   => array( 0, 2000 ),
			'columns_desktop'   => array( 1, 6 ),
			'columns_tablet'    => array( 1, 4 ),
			'columns_mobile'    => array( 1, 2 ),
			'gap'               => array( 0, 80 ),
			'max_width'         => array( 0, 3000 ),
			'card_radius'       => array( 0, 40 ),
			'card_padding'      => array( 0, 60 ),
			'font_size'         => array( 10, 28 ),
			'text_limit'        => array( 0, 2000 ),
			'autoplay_interval' => array( 1500, 30000 ),
			'location_id'       => array( 0, PHP_INT_MAX ),
		);

		if ( ! isset( $bounds[ $key ] ) ) {
			return $fallback;
		}

		list( $min, $max ) = $bounds[ $key ];

		if ( $value < $min ) {
			return $min;
		}

		return $value > $max ? $max : $value;
	}

	/**
	 * Accept only hex colours, so nothing can be smuggled into a style attribute.
	 *
	 * @param string $value    Submitted colour.
	 * @param string $fallback Value to use when the colour is not valid hex.
	 * @return string
	 */
	private static function sanitize_color( string $value, string $fallback ): string {
		$value = trim( $value );

		return preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? $value : $fallback;
	}
}
