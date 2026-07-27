<?php
/**
 * Queries against the reviews table.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Data;

use GoogleReviews\Install;

defined( 'ABSPATH' ) || exit;

// This class queries the plugin's own tables, for which WordPress provides no
// higher-level API. Every statement is bound with prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Every read of review data goes through here.
 *
 * All dynamic SQL is assembled from an allowlist and bound with prepare(); no
 * caller-supplied string ever reaches the query text.
 */
class ReviewsRepository {

	/**
	 * Column whitelist for ordering, mapped from a safe key.
	 *
	 * @var array<string, string>
	 */
	private const ORDER_MAP = array(
		'newest'  => 'source_created_at DESC, id DESC',
		'oldest'  => 'source_created_at ASC, id ASC',
		'highest' => 'star_rating DESC, source_created_at DESC',
		'lowest'  => 'star_rating ASC, source_created_at DESC',
		'longest' => 'CHAR_LENGTH(review_text) DESC',
		'random'  => 'RAND()',
	);

	/**
	 * Fetch reviews.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<int, object> Review rows.
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$args = array_merge(
			array(
				'location_id'     => 0,
				'ids'             => array(),
				'min_rating'      => 0,
				'max_rating'      => 0,
				'require_text'    => false,
				'min_text_length' => 0,
				'language'        => '',
				'search'          => '',
				'include_hidden'  => false,
				'featured_only'   => false,
				'order'           => 'newest',
				'limit'           => 20,
				'offset'          => 0,
			),
			$args
		);

		$table  = Install::table( 'reviews' );
		$where  = array( 'deleted_at IS NULL' );
		$params = array();

		if ( ! $args['include_hidden'] ) {
			$where[] = 'is_hidden = 0';
		}

		if ( $args['featured_only'] ) {
			$where[] = 'is_featured = 1';
		}

		if ( $args['location_id'] > 0 ) {
			$where[]  = 'location_id = %d';
			$params[] = (int) $args['location_id'];
		}

		if ( ! empty( $args['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) $args['ids'] ) ) );

			if ( empty( $ids ) ) {
				return array();
			}

			$where[] = 'id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}

		if ( $args['min_rating'] > 0 ) {
			$where[]  = 'star_rating >= %d';
			$params[] = (int) $args['min_rating'];
		}

		if ( $args['max_rating'] > 0 ) {
			$where[]  = 'star_rating <= %d';
			$params[] = (int) $args['max_rating'];
		}

		if ( $args['require_text'] ) {
			$where[] = "review_text IS NOT NULL AND review_text <> ''";
		}

		if ( $args['min_text_length'] > 0 ) {
			$where[]  = 'CHAR_LENGTH(review_text) >= %d';
			$params[] = (int) $args['min_text_length'];
		}

		if ( '' !== $args['language'] ) {
			$where[]  = 'review_language = %s';
			$params[] = (string) $args['language'];
		}

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(reviewer_name LIKE %s OR review_text LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		$order = self::ORDER_MAP[ $args['order'] ] ?? self::ORDER_MAP['newest'];

		$sql = 'SELECT * FROM `' . esc_sql( $table ) . '`'
			. ' WHERE ' . implode( ' AND ', $where )
			. ' ORDER BY ' . $order
			. ' LIMIT %d OFFSET %d';

		$params[] = max( 1, (int) $args['limit'] );
		$params[] = max( 0, (int) $args['offset'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count reviews matching the same arguments as query().
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int
	 */
	public static function count( array $args = array() ): int {
		$args['limit']  = 1000000;
		$args['offset'] = 0;
		$args['order']  = 'newest';

		return count( self::query( $args ) );
	}

	/**
	 * Aggregate rating data for a location, for widget headers and badges.
	 *
	 * @param int $location_id Location ID, or 0 for all locations.
	 * @return array{average: float, total: int}
	 */
	public static function stats( int $location_id = 0 ): array {
		global $wpdb;

		$table = Install::table( 'reviews' );
		$sql   = 'SELECT AVG(star_rating) AS average, COUNT(*) AS total FROM `' . esc_sql( $table ) . '`'
			. ' WHERE deleted_at IS NULL AND is_hidden = 0 AND star_rating > 0';

		if ( $location_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( $sql . ' AND location_id = %d', $location_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$row = $wpdb->get_row( $sql );
		}

		return array(
			'average' => ( $row && null !== $row->average ) ? round( (float) $row->average, 1 ) : 0.0,
			'total'   => $row ? (int) $row->total : 0,
		);
	}

	/**
	 * Flip a boolean flag on one review.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $field     Either 'is_hidden' or 'is_featured'.
	 * @param bool   $value     New value.
	 * @return bool Whether a row was updated.
	 */
	public static function set_flag( int $review_id, string $field, bool $value ): bool {
		global $wpdb;

		if ( ! in_array( $field, array( 'is_hidden', 'is_featured' ), true ) ) {
			return false;
		}

		$updated = $wpdb->update(
			Install::table( 'reviews' ),
			array(
				$field       => $value ? 1 : 0,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $review_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $updated && $updated > 0;
	}
}
