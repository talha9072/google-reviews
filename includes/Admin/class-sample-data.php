<?php
/**
 * Loads realistic sample reviews so the widget side can be built and demonstrated
 * before Google access is approved.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Admin;

use GoogleReviews\Install;
use GoogleReviews\Logger;

defined( 'ABSPATH' ) || exit;

// This class works against the plugin's own tables, for which WordPress offers
// no higher-level API, and the rows are written once rather than read hot.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Sample data is clearly marked in the database (source = 'sample') so it can
 * never be confused with, or survive alongside, real imported reviews.
 */
class SampleData {

	public const SOURCE = 'sample';

	/**
	 * Whether sample data is currently loaded.
	 *
	 * @return bool
	 */
	public static function is_loaded(): bool {
		return self::count() > 0;
	}

	/**
	 * How many sample reviews exist.
	 *
	 * @return int
	 */
	public static function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM `' . esc_sql( Install::table( 'reviews' ) ) . '` WHERE source = %s',
				self::SOURCE
			)
		);
	}

	/**
	 * Insert the sample location and its reviews.
	 *
	 * @return int Number of reviews created.
	 */
	public static function load(): int {
		global $wpdb;

		if ( self::is_loaded() ) {
			return 0;
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		$wpdb->insert(
			Install::table( 'locations' ),
			array(
				'source_account_id'  => 'sample',
				'source_location_id' => 'sample/demo-location',
				'business_name'      => __( 'Sample Business (demo data)', 'google-reviews-widget' ),
				'address'            => '12 Example Street, London',
				'status'             => 'active',
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$location_id = (int) $wpdb->insert_id;

		if ( $location_id <= 0 ) {
			return 0;
		}

		$created = 0;

		foreach ( self::reviews() as $index => $review ) {
			$days_ago = ( $index + 1 ) * 9;

			$inserted = $wpdb->insert(
				Install::table( 'reviews' ),
				array(
					'location_id'        => $location_id,
					'source'             => self::SOURCE,
					'source_review_id'   => 'sample-' . $index,
					'reviewer_name'      => $review['name'],
					'reviewer_photo_url' => '',
					'star_rating'        => $review['rating'],
					'review_text'        => $review['text'],
					'review_language'    => $review['lang'],
					'source_created_at'  => gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) ),
					'owner_reply_text'   => $review['reply'],
					'imported_at'        => $now,
					'last_seen_at'       => $now,
					'created_at'         => $now,
					'updated_at'         => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false !== $inserted ) {
				++$created;
			}
		}

		self::refresh_location_stats( $location_id );

		Logger::info( 'Sample reviews loaded.', array( 'count' => $created ) );

		return $created;
	}

	/**
	 * Remove every trace of the sample data.
	 *
	 * @return void
	 */
	public static function remove(): void {
		global $wpdb;

		$wpdb->delete( Install::table( 'reviews' ), array( 'source' => self::SOURCE ), array( '%s' ) );
		$wpdb->delete( Install::table( 'locations' ), array( 'source_account_id' => 'sample' ), array( '%s' ) );

		Logger::info( 'Sample reviews removed.' );
	}

	/**
	 * Recalculate the cached rating summary on the location row.
	 *
	 * @param int $location_id Location ID.
	 * @return void
	 */
	private static function refresh_location_stats( int $location_id ): void {
		global $wpdb;

		$table = Install::table( 'reviews' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT AVG(star_rating) AS average, COUNT(*) AS total FROM `' . esc_sql( $table ) . '` WHERE location_id = %d AND deleted_at IS NULL',
				$location_id
			)
		);

		$wpdb->update(
			Install::table( 'locations' ),
			array(
				'average_rating'     => $row ? round( (float) $row->average, 1 ) : 0,
				'total_review_count' => $row ? (int) $row->total : 0,
				'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $location_id ),
			array( '%f', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * The sample review content.
	 *
	 * Deliberately varied: different lengths, ratings, languages, an empty-text
	 * review, an owner reply, and emoji — so layout and filtering bugs surface
	 * during development rather than in production.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function reviews(): array {
		return array(
			array(
				'name'   => 'Sarah Whitfield',
				'rating' => 5,
				'lang'   => 'en',
				'reply'  => 'Thank you Sarah, that is lovely to hear. See you next time!',
				'text'   => 'Absolutely first class from start to finish. I was nervous about booking but the whole team put me at ease immediately, explained every step, and never once made me feel rushed. The results speak for themselves. I have already recommended them to two friends.',
			),
			array(
				'name'   => 'James O\'Connor',
				'rating' => 5,
				'lang'   => 'en',
				'reply'  => '',
				'text'   => "Booked last minute and they still fitted me in. Professional, friendly and genuinely knew what they were talking about. Can't fault it.",
			),
			array(
				'name'   => 'Priya Raghunathan',
				'rating' => 5,
				'lang'   => 'en',
				'reply'  => '',
				'text'   => "Six months on and I'm still delighted 😊 Worth every penny. The follow-up care is what really sets them apart — nobody else bothered to check in afterwards.",
			),
			array(
				'name'   => 'Tom B.',
				'rating' => 4,
				'lang'   => 'en',
				'reply'  => '',
				'text'   => "Very good overall. Only reason it's not five stars is the parking situation, which honestly isn't their fault. Service itself was excellent.",
			),
			array(
				'name'   => 'Aisha Rahman',
				'rating' => 5,
				'lang'   => 'en',
				'reply'  => '',
				'text'   => 'I cannot recommend this place highly enough.',
			),
			array(
				'name'   => 'Daniel Mercer',
				'rating' => 5,
				'lang'   => 'en',
				'reply'  => 'Much appreciated Daniel — glad we could help.',
				'text'   => "Second time using them and the standard hasn't slipped at all. Clear pricing, no surprises on the invoice, and they actually turned up when they said they would. That last part seems rare these days.",
			),
			array(
				'name'   => 'Chloé Bernard',
				'rating' => 5,
				'lang'   => 'fr',
				'reply'  => '',
				'text'   => 'Service impeccable et personnel très accueillant. Je reviendrai sans hésiter.',
			),
			array(
				'name'   => 'محمد الأحمد',
				'rating' => 5,
				'lang'   => 'ar',
				'reply'  => '',
				'text'   => 'خدمة ممتازة وفريق محترف جدا. أنصح به بشدة.',
			),
			array(
				'name'   => 'Rebecca Lyons',
				'rating' => 4,
				'lang'   => 'en',
				'reply'  => '',
				'text'   => "Really pleased. Took slightly longer than quoted but they kept me updated the whole way through, which I'd rather have than being left in the dark.",
			),
			array(
				'name'   => 'Michael Adeyemi',
				'rating' => 5,
				'lang'   => 'en',
				'reply'  => '',
				'text'   => "Outstanding. Genuinely the best experience I've had with any business like this. The attention to detail is on another level and you can tell they actually care about getting it right rather than just getting it done.",
			),
			array(
				'name'   => 'Linda Hargreaves',
				'rating' => 5,
				'lang'   => 'en',
				'reply'  => '',
				'text'   => '',
			),
			array(
				'name'   => 'Wei Zhang',
				'rating' => 5,
				'lang'   => 'zh',
				'reply'  => '',
				'text'   => '非常专业的团队，服务态度很好，结果也超出预期。强烈推荐给大家。',
			),
			array(
				'name'   => 'Gary Middleton',
				'rating' => 3,
				'lang'   => 'en',
				'reply'  => 'Sorry to hear this Gary — please get in touch and we will put it right.',
				'text'   => 'Mixed experience. The work itself was fine but communication beforehand could have been better. Staff were polite when I raised it.',
			),
			array(
				'name'   => 'Emily Fraser',
				'rating' => 5,
				'lang'   => 'en',
				'reply'  => '',
				'text'   => 'Lovely people, spotless premises, and they explained everything in plain English rather than jargon. Exactly what you want.',
			),
		);
	}
}
