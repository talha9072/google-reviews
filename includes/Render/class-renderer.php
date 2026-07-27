<?php
/**
 * Turns a widget plus its reviews into HTML.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Render;

use GoogleReviews\Data\ReviewsRepository;
use GoogleReviews\Settings;
use GoogleReviews\Widget\SelectionEngine;
use GoogleReviews\Widget\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side rendering, so review text is real HTML in the page for search
 * engines and works with JavaScript disabled.
 *
 * Review content is emitted through esc_html() only. There is no code path in
 * this class that outputs review text as markup, which is what makes stored XSS
 * through a review structurally impossible.
 */
class Renderer {

	/**
	 * Render a widget.
	 *
	 * @param int                  $widget_id Widget ID (0 for an unsaved preview).
	 * @param array<string, mixed> $settings  Sanitised settings.
	 * @return string HTML.
	 */
	public static function render( int $widget_id, array $settings ): string {
		$settings = SettingsSchema::sanitize( $settings );
		$reviews  = SelectionEngine::resolve( $widget_id, $settings );

		if ( 'badge' !== $settings['layout'] && empty( $reviews ) ) {
			return self::empty_state();
		}

		$stats = ReviewsRepository::stats( (int) $settings['location_id'] );

		$classes = array(
			'gbrw-root',
			'gbrw-layout-' . $settings['layout'],
		);

		if ( $settings['card_shadow'] ) {
			$classes[] = 'gbrw-has-shadow';
		}

		if ( ! $settings['inherit_font'] ) {
			$classes[] = 'gbrw-own-font';
		}

		$html  = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( self::css_variables( $settings ) ) . '">';
		$html .= self::header( $settings, $stats );

		switch ( $settings['layout'] ) {
			case 'badge':
				$html .= self::badge( $settings, $stats );
				break;

			case 'carousel':
				$html .= self::carousel( $settings, $reviews );
				break;

			default:
				$html .= self::track( $settings, $reviews );
				break;
		}

		$html .= self::attribution();
		$html .= '</div>';

		return $html;
	}

	/**
	 * Build the inline custom-property block that carries every style setting.
	 *
	 * Values are numbers and validated hex colours only — the schema rejects
	 * anything else, so nothing can escape the style attribute.
	 *
	 * @param array<string, mixed> $settings Sanitised settings.
	 * @return string
	 */
	private static function css_variables( array $settings ): string {
		$vars = array(
			'--gbrw-cols-d'     => (int) $settings['columns_desktop'],
			'--gbrw-cols-t'     => (int) $settings['columns_tablet'],
			'--gbrw-cols-m'     => (int) $settings['columns_mobile'],
			'--gbrw-gap'        => (int) $settings['gap'] . 'px',
			'--gbrw-card-bg'    => (string) $settings['card_background'],
			'--gbrw-card-bd'    => (string) $settings['card_border'],
			'--gbrw-radius'     => (int) $settings['card_radius'] . 'px',
			'--gbrw-padding'    => (int) $settings['card_padding'] . 'px',
			'--gbrw-font-size'  => (int) $settings['font_size'] . 'px',
			'--gbrw-text'       => (string) $settings['text_color'],
			'--gbrw-muted'      => (string) $settings['muted_color'],
			'--gbrw-star'       => (string) $settings['star_color'],
			'--gbrw-star-empty' => (string) $settings['star_empty_color'],
		);

		if ( $settings['max_width'] > 0 ) {
			$vars['--gbrw-max-width'] = (int) $settings['max_width'] . 'px';
		}

		$out = '';

		foreach ( $vars as $name => $value ) {
			$out .= $name . ':' . $value . ';';
		}

		return $out;
	}

	/**
	 * Optional header showing the aggregate rating.
	 *
	 * @param array<string, mixed>              $settings Sanitised settings.
	 * @param array{average: float, total: int} $stats   Aggregate rating data.
	 * @return string
	 */
	private static function header( array $settings, array $stats ): string {
		if ( ! $settings['show_header'] || 'badge' === $settings['layout'] ) {
			return '';
		}

		$html  = '<div class="gbrw-header">';
		$html .= '<span class="gbrw-header__score">' . esc_html( number_format_i18n( $stats['average'], 1 ) ) . '</span>';
		$html .= self::stars( $stats['average'] );
		$html .= '<span class="gbrw-header__count">' . esc_html(
			sprintf(
				/* translators: %s: number of reviews */
				_n( '%s review', '%s reviews', $stats['total'], 'google-reviews-widget' ),
				number_format_i18n( $stats['total'] )
			)
		) . '</span>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * The rating badge layout.
	 *
	 * @param array<string, mixed>              $settings Sanitised settings.
	 * @param array{average: float, total: int} $stats   Aggregate rating data.
	 * @return string
	 */
	private static function badge( array $settings, array $stats ): string {
		$html  = '<div class="gbrw-badge">';
		$html .= '<div class="gbrw-badge__score">' . esc_html( number_format_i18n( $stats['average'], 1 ) ) . '</div>';
		$html .= '<div class="gbrw-badge__body">';
		$html .= self::stars( $stats['average'] );
		$html .= '<div class="gbrw-badge__count">' . esc_html(
			sprintf(
				/* translators: %s: number of reviews */
				_n( 'Based on %s review', 'Based on %s reviews', $stats['total'], 'google-reviews-widget' ),
				number_format_i18n( $stats['total'] )
			)
		) . '</div>';
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Grid and list layouts.
	 *
	 * @param array<string, mixed> $settings Sanitised settings.
	 * @param array<int, object>   $reviews  Review rows.
	 * @return string
	 */
	private static function track( array $settings, array $reviews ): string {
		$html = '<div class="gbrw-track">';

		foreach ( $reviews as $review ) {
			$html .= self::card( $settings, $review );
		}

		return $html . '</div>';
	}

	/**
	 * Carousel layout.
	 *
	 * Renders the same cards as the grid, wrapped in controls. With JavaScript
	 * unavailable it degrades to a horizontally scrollable strip rather than
	 * showing nothing.
	 *
	 * @param array<string, mixed> $settings Sanitised settings.
	 * @param array<int, object>   $reviews  Review rows.
	 * @return string
	 */
	private static function carousel( array $settings, array $reviews ): string {
		$config = wp_json_encode(
			array(
				'autoplay' => (bool) $settings['autoplay'],
				'interval' => (int) $settings['autoplay_interval'],
			)
		);

		$html = '<div class="gbrw-carousel" data-gbrw-carousel="' . esc_attr( (string) $config ) . '">';

		if ( $settings['show_arrows'] ) {
			$html .= '<button type="button" class="gbrw-arrow gbrw-arrow--prev" aria-label="' . esc_attr__( 'Previous review', 'google-reviews-widget' ) . '">&#8249;</button>';
		}

		$html .= '<div class="gbrw-track" role="group" aria-roledescription="carousel">';

		foreach ( $reviews as $review ) {
			$html .= self::card( $settings, $review );
		}

		$html .= '</div>';

		if ( $settings['show_arrows'] ) {
			$html .= '<button type="button" class="gbrw-arrow gbrw-arrow--next" aria-label="' . esc_attr__( 'Next review', 'google-reviews-widget' ) . '">&#8250;</button>';
		}

		if ( $settings['show_dots'] ) {
			$html .= '<div class="gbrw-dots" aria-hidden="true"></div>';
		}

		return $html . '</div>';
	}

	/**
	 * One review card.
	 *
	 * @param array<string, mixed> $settings Sanitised settings.
	 * @param object               $review   Review row.
	 * @return string
	 */
	private static function card( array $settings, $review ): string {
		$name = '' !== (string) $review->reviewer_name
			? (string) $review->reviewer_name
			: __( 'Google user', 'google-reviews-widget' );

		$html = '<article class="gbrw-card">';

		$html .= '<header class="gbrw-card__head">';

		if ( $settings['show_avatar'] ) {
			$html .= self::avatar( $review, $name );
		}

		$html .= '<div class="gbrw-card__meta">';
		$html .= '<span class="gbrw-card__name">' . esc_html( $name ) . '</span>';

		if ( $settings['show_date'] ) {
			$html .= self::date( (string) $review->source_created_at );
		}

		$html .= '</div></header>';

		if ( $settings['show_rating'] ) {
			$html .= self::stars( (float) $review->star_rating );
		}

		$html .= self::text( (string) $review->review_text, (int) $settings['text_limit'] );

		if ( $settings['show_reply'] && '' !== (string) $review->owner_reply_text ) {
			$html .= '<div class="gbrw-reply">';
			$html .= '<span class="gbrw-reply__label">' . esc_html__( 'Response from the owner', 'google-reviews-widget' ) . '</span>';
			$html .= '<p class="gbrw-reply__text">' . esc_html( (string) $review->owner_reply_text ) . '</p>';
			$html .= '</div>';
		}

		return $html . '</article>';
	}

	/**
	 * Reviewer avatar, falling back to initials.
	 *
	 * @param object $review Review row.
	 * @param string $name   Display name.
	 * @return string
	 */
	private static function avatar( $review, string $name ): string {
		$photo = (string) $review->reviewer_photo_url;

		if ( '' !== $photo && wp_http_validate_url( $photo ) ) {
			return '<img class="gbrw-avatar" src="' . esc_url( $photo ) . '" alt="" width="40" height="40" loading="lazy" referrerpolicy="no-referrer" />';
		}

		$initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );

		return '<span class="gbrw-avatar gbrw-avatar--initial" aria-hidden="true">' . esc_html( strtoupper( $initial ) ) . '</span>';
	}

	/**
	 * Star rating, with an accessible text equivalent.
	 *
	 * @param float $rating Rating out of five.
	 * @return string
	 */
	private static function stars( float $rating ): string {
		$rounded = (int) round( $rating );
		$html    = '<span class="gbrw-stars">';

		$html .= '<span class="gbrw-sr">' . esc_html(
			sprintf(
				/* translators: %s: rating out of five */
				__( 'Rated %s out of 5', 'google-reviews-widget' ),
				number_format_i18n( $rating, 1 )
			)
		) . '</span>';

		for ( $i = 1; $i <= 5; $i++ ) {
			$class = $i <= $rounded ? 'gbrw-star gbrw-star--on' : 'gbrw-star';
			$html .= '<span class="' . esc_attr( $class ) . '" aria-hidden="true">&#9733;</span>';
		}

		return $html . '</span>';
	}

	/**
	 * Review body text.
	 *
	 * Truncation happens on a word boundary and is multibyte-safe, so it cannot
	 * split an emoji or a non-Latin character in half.
	 *
	 * @param string $text  Raw review text.
	 * @param int    $limit Character limit, 0 for no limit.
	 * @return string
	 */
	private static function text( string $text, int $limit ): string {
		$text = trim( $text );

		if ( '' === $text ) {
			return '';
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

		if ( $limit > 0 && $length > $limit ) {
			$truncated = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
			$space     = function_exists( 'mb_strrpos' ) ? mb_strrpos( $truncated, ' ' ) : strrpos( $truncated, ' ' );

			if ( false !== $space && $space > (int) ( $limit * 0.6 ) ) {
				$truncated = function_exists( 'mb_substr' ) ? mb_substr( $truncated, 0, $space ) : substr( $truncated, 0, $space );
			}

			$text = rtrim( $truncated ) . "\u{2026}";
		}

		// esc_html() first, then nl2br() on the already-escaped string: review
		// content can never be interpreted as markup.
		return '<p class="gbrw-text">' . nl2br( esc_html( $text ) ) . '</p>';
	}

	/**
	 * Review date, absolute or relative according to the site setting.
	 *
	 * @param string $timestamp MySQL UTC datetime.
	 * @return string
	 */
	private static function date( string $timestamp ): string {
		if ( '' === $timestamp || '0000-00-00 00:00:00' === $timestamp ) {
			return '';
		}

		$time = strtotime( $timestamp . ' UTC' );

		if ( false === $time ) {
			return '';
		}

		if ( 'relative' === Settings::get( 'date_display', 'relative' ) ) {
			$label = sprintf(
				/* translators: %s: human-readable time difference, e.g. "3 weeks" */
				__( '%s ago', 'google-reviews-widget' ),
				human_time_diff( $time, time() )
			);
		} else {
			$label = date_i18n( (string) get_option( 'date_format' ), $time );
		}

		return '<time class="gbrw-card__date" datetime="' . esc_attr( gmdate( 'c', $time ) ) . '">' . esc_html( $label ) . '</time>';
	}

	/**
	 * Required Google attribution.
	 *
	 * @return string
	 */
	private static function attribution(): string {
		if ( ! Settings::get( 'show_attribution', true ) ) {
			return '';
		}

		return '<div class="gbrw-attribution">' . esc_html__( 'Reviews from Google', 'google-reviews-widget' ) . '</div>';
	}

	/**
	 * Shown when no review matches the widget's rules.
	 *
	 * Deliberately quiet: a widget with nothing to show must not leave a broken
	 * gap on a customer's page.
	 *
	 * @return string
	 */
	private static function empty_state(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return '<div class="gbrw-root gbrw-empty"><p>'
			. esc_html__( 'No reviews match this widget\'s rules yet. Only administrators see this message.', 'google-reviews-widget' )
			. '</p></div>';
	}
}
