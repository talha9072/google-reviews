<?php
/**
 * Turns a widget's settings into a concrete, ordered list of reviews.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Widget;

use GoogleReviews\Data\ReviewsRepository;
use GoogleReviews\Install;

defined( 'ABSPATH' ) || exit;

// This class queries the plugin's own tables, for which WordPress provides no
// higher-level API. Every statement is bound with prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * The single place that decides which reviews a widget shows.
 *
 * Kept separate from rendering so it can be unit tested on its own — this is
 * where subtle bugs would otherwise hide.
 */
class SelectionEngine {

	/**
	 * Resolve the reviews for a widget.
	 *
	 * @param int                  $widget_id Widget ID (0 for a preview of unsaved settings).
	 * @param array<string, mixed> $settings  Sanitised widget settings.
	 * @return array<int, object> Ordered review rows.
	 */
	public static function resolve( int $widget_id, array $settings ): array {
		if ( 'manual' === $settings['selection_mode'] && $widget_id > 0 ) {
			return self::resolve_manual( $widget_id, $settings );
		}

		return self::resolve_auto( $settings );
	}

	/**
	 * Rule-based selection.
	 *
	 * @param array<string, mixed> $settings Sanitised widget settings.
	 * @return array<int, object>
	 */
	private static function resolve_auto( array $settings ): array {
		return ReviewsRepository::query(
			array(
				'location_id'     => (int) $settings['location_id'],
				'min_rating'      => (int) $settings['min_rating'],
				'require_text'    => (bool) $settings['require_text'],
				'min_text_length' => (int) $settings['min_text_length'],
				'order'           => (string) $settings['order'],
				'limit'           => (int) $settings['max_reviews'],
			)
		);
	}

	/**
	 * Hand-picked selection, in the order the user arranged them.
	 *
	 * Reviews that have since been hidden or deleted simply drop out, so a
	 * removed review can never leave a hole or break the layout.
	 *
	 * @param int                  $widget_id Widget ID.
	 * @param array<string, mixed> $settings  Sanitised widget settings.
	 * @return array<int, object>
	 */
	private static function resolve_manual( int $widget_id, array $settings ): array {
		global $wpdb;

		$table = Install::table( 'widget_reviews' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$picked = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT review_id, display_order FROM `' . esc_sql( $table ) . '` WHERE widget_id = %d ORDER BY display_order ASC, id ASC',
				$widget_id
			)
		);

		if ( empty( $picked ) ) {
			return array();
		}

		$ordered_ids = array_map( static fn( $row ) => (int) $row->review_id, $picked );

		$reviews = ReviewsRepository::query(
			array(
				'ids'   => $ordered_ids,
				'limit' => (int) $settings['max_reviews'],
			)
		);

		return self::sort_by_id_order( $reviews, $ordered_ids );
	}

	/**
	 * Reorder rows to match an explicit list of IDs.
	 *
	 * @param array<int, object> $reviews     Rows in arbitrary order.
	 * @param array<int, int>    $ordered_ids Desired ID order.
	 * @return array<int, object>
	 */
	private static function sort_by_id_order( array $reviews, array $ordered_ids ): array {
		$by_id = array();

		foreach ( $reviews as $review ) {
			$by_id[ (int) $review->id ] = $review;
		}

		$sorted = array();

		foreach ( $ordered_ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$sorted[] = $by_id[ $id ];
			}
		}

		return $sorted;
	}
}
