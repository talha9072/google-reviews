<?php
/**
 * Reviews inbox.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Admin;

use GoogleReviews\Data\ReviewsRepository;
use GoogleReviews\Google\Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Lists imported reviews with basic filtering and hide/feature controls.
 */
class ReviewsPage {

	private const CAPABILITY = 'manage_options';
	private const PER_PAGE   = 20;

	/**
	 * Register handlers.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_gbrw_sample_data', array( __CLASS__, 'handle_sample_data' ) );
		add_action( 'admin_post_gbrw_review_flag', array( __CLASS__, 'handle_flag' ) );
	}

	/**
	 * Load or remove the sample data set.
	 *
	 * @return void
	 */
	public static function handle_sample_data(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'google-reviews-widget' ) );
		}

		check_admin_referer( 'gbrw_sample_data' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		if ( isset( $_POST['remove'] ) ) {
			SampleData::remove();
		} else {
			SampleData::load();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=gbrw-reviews' ) );
		exit;
	}

	/**
	 * Hide or feature a single review.
	 *
	 * @return void
	 */
	public static function handle_flag(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'google-reviews-widget' ) );
		}

		check_admin_referer( 'gbrw_review_flag' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$id    = isset( $_POST['review_id'] ) ? (int) $_POST['review_id'] : 0;
		$field = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$value = isset( $_POST['value'] ) && '1' === $_POST['value'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $id > 0 ) {
			ReviewsRepository::set_flag( $id, $field, $value );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=gbrw-reviews' ) );
		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'google-reviews-widget' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filtering.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$rating = isset( $_GET['rating'] ) ? (int) $_GET['rating'] : 0;
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$args = array(
			'search'         => $search,
			'min_rating'     => $rating,
			'max_rating'     => $rating,
			'include_hidden' => true,
			'limit'          => self::PER_PAGE,
			'offset'         => ( $paged - 1 ) * self::PER_PAGE,
		);

		$reviews = ReviewsRepository::query( $args );
		$total   = ReviewsRepository::count(
			array_merge(
				$args,
				array(
					'limit'  => 100000,
					'offset' => 0,
				)
			)
		);
		$stats   = ReviewsRepository::stats();

		echo '<div class="wrap gbrw-wrap">';
		echo '<h1>' . esc_html__( 'Reviews', 'google-reviews-widget' ) . '</h1>';

		self::render_sample_panel();

		if ( 0 === $total && '' === $search && 0 === $rating ) {
			echo '</div>';
			return;
		}

		echo '<div class="gbrw-cards">';
		printf(
			'<div class="gbrw-card"><span class="gbrw-card__label">%s</span><span class="gbrw-card__value">%s</span></div>',
			esc_html__( 'Average rating', 'google-reviews-widget' ),
			esc_html( number_format_i18n( $stats['average'], 1 ) )
		);
		printf(
			'<div class="gbrw-card"><span class="gbrw-card__label">%s</span><span class="gbrw-card__value">%s</span></div>',
			esc_html__( 'Visible reviews', 'google-reviews-widget' ),
			esc_html( number_format_i18n( $stats['total'] ) )
		);
		echo '</div>';

		echo '<form method="get" class="gbrw-filters">';
		echo '<input type="hidden" name="page" value="gbrw-reviews" />';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name or text', 'google-reviews-widget' ) . '" />';
		echo '<select name="rating"><option value="0">' . esc_html__( 'All ratings', 'google-reviews-widget' ) . '</option>';

		for ( $i = 5; $i >= 1; $i-- ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $i,
				selected( $rating, $i, false ),
				esc_html( sprintf( /* translators: %d: star rating */ _n( '%d star', '%d stars', $i, 'google-reviews-widget' ), $i ) )
			);
		}

		echo '</select> ';
		submit_button( __( 'Filter', 'google-reviews-widget' ), 'secondary', '', false );
		echo '</form>';

		if ( empty( $reviews ) ) {
			echo '<div class="gbrw-panel gbrw-panel--muted"><p>' . esc_html__( 'No reviews match those filters.', 'google-reviews-widget' ) . '</p></div></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:60px">' . esc_html__( 'Rating', 'google-reviews-widget' ) . '</th>';
		echo '<th style="width:160px">' . esc_html__( 'Reviewer', 'google-reviews-widget' ) . '</th>';
		echo '<th>' . esc_html__( 'Review', 'google-reviews-widget' ) . '</th>';
		echo '<th style="width:120px">' . esc_html__( 'Date', 'google-reviews-widget' ) . '</th>';
		echo '<th style="width:170px"></th>';
		echo '</tr></thead><tbody>';

		foreach ( $reviews as $review ) {
			$hidden = (bool) $review->is_hidden;

			echo '<tr' . ( $hidden ? ' style="opacity:.5"' : '' ) . '>';
			echo '<td>' . esc_html( str_repeat( '★', (int) $review->star_rating ) ) . '</td>';
			echo '<td>' . esc_html( (string) $review->reviewer_name ) . '</td>';
			echo '<td>' . esc_html( wp_trim_words( (string) $review->review_text, 28 ) ) . '</td>';
			echo '<td>' . esc_html( (string) mysql2date( (string) get_option( 'date_format' ), (string) $review->source_created_at ) ) . '</td>';
			echo '<td style="text-align:right">';
			self::flag_button( (int) $review->id, 'is_hidden', ! $hidden, $hidden ? __( 'Unhide', 'google-reviews-widget' ) : __( 'Hide', 'google-reviews-widget' ) );
			echo ' ';
			self::flag_button( (int) $review->id, 'is_featured', ! (bool) $review->is_featured, (bool) $review->is_featured ? __( 'Unfeature', 'google-reviews-widget' ) : __( 'Feature', 'google-reviews-widget' ) );
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		$pages = (int) ceil( $total / self::PER_PAGE );

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * Small form button that flips a review flag.
	 *
	 * @param int    $review_id Review ID.
	 * @param string $field     Flag column.
	 * @param bool   $value     New value.
	 * @param string $label     Button label.
	 * @return void
	 */
	private static function flag_button( int $review_id, string $field, bool $value, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		wp_nonce_field( 'gbrw_review_flag' );
		echo '<input type="hidden" name="action" value="gbrw_review_flag" />';
		echo '<input type="hidden" name="review_id" value="' . (int) $review_id . '" />';
		echo '<input type="hidden" name="field" value="' . esc_attr( $field ) . '" />';
		echo '<input type="hidden" name="value" value="' . ( $value ? '1' : '0' ) . '" />';
		echo '<button type="submit" class="button button-small">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Panel offering the sample data set while Google is not connected.
	 *
	 * @return void
	 */
	private static function render_sample_panel(): void {
		$loaded    = SampleData::is_loaded();
		$connected = Connection::is_connected();

		if ( $connected && ! $loaded ) {
			return;
		}

		echo '<div class="gbrw-panel' . ( $loaded ? '' : ' gbrw-panel--cta' ) . '">';

		if ( $loaded ) {
			echo '<h2>' . esc_html__( 'Sample data is loaded', 'google-reviews-widget' ) . '</h2>';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of sample reviews */
					__( 'These %d reviews are demo content, not real Google reviews. Remove them before going live.', 'google-reviews-widget' ),
					SampleData::count()
				)
			) . '</p>';
		} else {
			echo '<h2>' . esc_html__( 'No reviews yet', 'google-reviews-widget' ) . '</h2>';
			echo '<p>' . esc_html__( 'Connect Google to import your real reviews. In the meantime you can load sample reviews to build and preview your widgets.', 'google-reviews-widget' ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gbrw_sample_data' );
		echo '<input type="hidden" name="action" value="gbrw_sample_data" />';

		if ( $loaded ) {
			echo '<button type="submit" name="remove" value="1" class="button">' . esc_html__( 'Remove sample reviews', 'google-reviews-widget' ) . '</button>';
		} else {
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Load sample reviews', 'google-reviews-widget' ) . '</button> ';
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=gbrw-settings' ) ) . '">' . esc_html__( 'Connect Google', 'google-reviews-widget' ) . '</a>';
		}

		echo '</form></div>';
	}
}
