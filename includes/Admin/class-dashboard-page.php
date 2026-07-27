<?php
/**
 * Dashboard screen.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Admin;

use GoogleReviews\Google\Connection;
use GoogleReviews\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Overview of connection health and imported data.
 */
class DashboardPage {

	private const CAPABILITY = 'manage_options';

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'google-reviews-widget' ) );
		}

		$connected = Connection::is_connected();
		$counts    = self::counts();

		echo '<div class="wrap gbrw-wrap">';
		echo '<h1>' . esc_html__( 'Google Reviews', 'google-reviews-widget' ) . '</h1>';

		if ( ! $connected ) {
			echo '<div class="gbrw-panel gbrw-panel--cta">';
			echo '<h2>' . esc_html__( 'Connect your Google Business Profile', 'google-reviews-widget' ) . '</h2>';
			echo '<p>' . esc_html__( 'Nothing can be imported until you connect the Google account that manages your business listing.', 'google-reviews-widget' ) . '</p>';
			echo '<p><a class="button button-primary button-hero" href="' . esc_url( admin_url( 'admin.php?page=gbrw-settings' ) ) . '">';
			echo esc_html__( 'Set up the connection', 'google-reviews-widget' );
			echo '</a></p>';
			echo '</div>';
		}

		echo '<div class="gbrw-cards">';

		self::card(
			__( 'Connection', 'google-reviews-widget' ),
			$connected ? __( 'Connected', 'google-reviews-widget' ) : __( 'Not connected', 'google-reviews-widget' ),
			$connected ? 'ok' : 'warn'
		);

		self::card( __( 'Locations', 'google-reviews-widget' ), (string) $counts['locations'], 'neutral' );
		self::card( __( 'Reviews imported', 'google-reviews-widget' ), (string) $counts['reviews'], 'neutral' );
		self::card( __( 'Widgets', 'google-reviews-widget' ), (string) $counts['widgets'], 'neutral' );

		echo '</div>';

		echo '<div class="gbrw-panel">';
		echo '<h2>' . esc_html__( 'Getting started', 'google-reviews-widget' ) . '</h2>';
		echo '<ol class="gbrw-steps">';
		echo '<li>' . esc_html__( 'Connect the Google account that owns or manages your Business Profile.', 'google-reviews-widget' ) . '</li>';
		echo '<li>' . esc_html__( 'Choose which business location to import.', 'google-reviews-widget' ) . '</li>';
		echo '<li>' . esc_html__( 'Review and curate the imported reviews.', 'google-reviews-widget' ) . '</li>';
		echo '<li>' . esc_html__( 'Build a widget and pick a layout.', 'google-reviews-widget' ) . '</li>';
		echo '<li>' . esc_html__( 'Paste the shortcode into any page.', 'google-reviews-widget' ) . '</li>';
		echo '</ol>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Row counts for the summary cards.
	 *
	 * @return array<string, int>
	 */
	private static function counts(): array {
		global $wpdb;

		$counts = array(
			'locations' => 0,
			'reviews'   => 0,
			'widgets'   => 0,
		);

		foreach ( array_keys( $counts ) as $key ) {
			$table = Install::table( $key );

			// Table names come from a fixed internal list, never user input.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$value = $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '`' );

			$counts[ $key ] = null === $value ? 0 : (int) $value;
		}

		return $counts;
	}

	/**
	 * Output a summary card.
	 *
	 * @param string $label Card label.
	 * @param string $value Card value.
	 * @param string $tone  One of 'ok', 'warn', or 'neutral'.
	 * @return void
	 */
	private static function card( string $label, string $value, string $tone ): void {
		printf(
			'<div class="gbrw-card gbrw-card--%1$s"><span class="gbrw-card__label">%2$s</span><span class="gbrw-card__value">%3$s</span></div>',
			esc_attr( $tone ),
			esc_html( $label ),
			esc_html( $value )
		);
	}
}
