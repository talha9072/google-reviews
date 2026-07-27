<?php
/**
 * Activation, database schema, and version upgrades.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the plugin's own tables.
 *
 * Reviews live in a custom table rather than a custom post type: a busy location
 * can hold thousands of reviews, and postmeta would make filtered queries slow
 * while bloating wp_posts.
 */
class Install {

	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		Logger::prepare_storage();

		if ( false === get_option( 'gbrw_installed_at' ) ) {
			add_option( 'gbrw_installed_at', gmdate( 'Y-m-d H:i:s' ) );
		}

		update_option( 'gbrw_db_version', GBRW_DB_VERSION );

		// The sync scheduler jitters each site by a stable offset so installs do
		// not all hit Google's shared project quota at the same minute.
		if ( false === get_option( 'gbrw_sync_offset' ) ) {
			add_option( 'gbrw_sync_offset', wp_rand( 0, ( DAY_IN_SECONDS - 1 ) ) );
		}
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * Scheduled work is cancelled; no data is removed.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'gbrw' );
		}
	}

	/**
	 * Re-run migrations when the stored schema version is behind the code.
	 *
	 * Covers updates applied without a deactivate/activate cycle.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored = (int) get_option( 'gbrw_db_version', 0 );

		if ( GBRW_DB_VERSION === $stored ) {
			return;
		}

		self::create_tables();
		update_option( 'gbrw_db_version', GBRW_DB_VERSION );

		Logger::info(
			'Database schema upgraded.',
			array(
				'from' => $stored,
				'to'   => GBRW_DB_VERSION,
			)
		);
	}

	/**
	 * Create or update every table via dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		foreach ( self::table_definitions( $collate ) as $sql ) {
			dbDelta( $sql );
		}

		self::maybe_add_fulltext_index();
	}

	/**
	 * Schema definitions.
	 *
	 * Note that dbDelta is strict: one field per line, two spaces after
	 * PRIMARY KEY, and every index needs a name.
	 *
	 * @param string $collate Charset and collation clause.
	 * @return array<int, string> CREATE TABLE statements.
	 */
	private static function table_definitions( string $collate ): array {
		global $wpdb;

		$prefix = $wpdb->prefix . 'gbrw_';

		$locations = "CREATE TABLE {$prefix}locations (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	source_account_id varchar(191) NOT NULL DEFAULT '',
	source_location_id varchar(191) NOT NULL,
	business_name varchar(255) NOT NULL DEFAULT '',
	address text NULL,
	website_url varchar(255) NOT NULL DEFAULT '',
	phone varchar(64) NOT NULL DEFAULT '',
	google_maps_uri varchar(255) NOT NULL DEFAULT '',
	average_rating decimal(2,1) NOT NULL DEFAULT 0.0,
	total_review_count int(10) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT 'active',
	last_sync_started_at datetime NULL,
	last_sync_completed_at datetime NULL,
	last_sync_status varchar(20) NOT NULL DEFAULT '',
	last_sync_error text NULL,
	next_sync_at datetime NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY source_location_id (source_location_id),
	KEY next_sync_at (next_sync_at),
	KEY status (status)
) {$collate};";

		$reviews = "CREATE TABLE {$prefix}reviews (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	location_id bigint(20) unsigned NOT NULL,
	source varchar(20) NOT NULL DEFAULT 'google',
	source_review_id varchar(191) NOT NULL,
	reviewer_name varchar(255) NOT NULL DEFAULT '',
	reviewer_photo_url varchar(500) NOT NULL DEFAULT '',
	reviewer_profile_url varchar(500) NOT NULL DEFAULT '',
	star_rating tinyint(3) unsigned NOT NULL DEFAULT 0,
	review_text longtext NULL,
	review_language varchar(16) NOT NULL DEFAULT '',
	source_created_at datetime NULL,
	source_updated_at datetime NULL,
	owner_reply_text longtext NULL,
	owner_reply_updated_at datetime NULL,
	is_hidden tinyint(1) unsigned NOT NULL DEFAULT 0,
	is_featured tinyint(1) unsigned NOT NULL DEFAULT 0,
	internal_note text NULL,
	imported_at datetime NOT NULL,
	last_seen_at datetime NULL,
	deleted_at datetime NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY location_source_review (location_id, source_review_id),
	KEY location_created (location_id, source_created_at),
	KEY location_filter (location_id, star_rating, is_hidden, deleted_at),
	KEY is_featured (is_featured)
) {$collate};";

		$widgets = "CREATE TABLE {$prefix}widgets (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	name varchar(191) NOT NULL DEFAULT '',
	description text NULL,
	status varchar(20) NOT NULL DEFAULT 'draft',
	layout_type varchar(32) NOT NULL DEFAULT 'grid',
	selection_mode varchar(20) NOT NULL DEFAULT 'auto',
	settings_json longtext NULL,
	published_settings_json longtext NULL,
	settings_version smallint(5) unsigned NOT NULL DEFAULT 1,
	published_at datetime NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY status (status)
) {$collate};";

		$widget_reviews = "CREATE TABLE {$prefix}widget_reviews (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	widget_id bigint(20) unsigned NOT NULL,
	review_id bigint(20) unsigned NOT NULL,
	display_order int(10) unsigned NOT NULL DEFAULT 0,
	is_pinned tinyint(1) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY widget_review (widget_id, review_id),
	KEY widget_order (widget_id, display_order)
) {$collate};";

		$sync_log = "CREATE TABLE {$prefix}sync_log (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	location_id bigint(20) unsigned NULL,
	job_type varchar(32) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT '',
	attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
	started_at datetime NULL,
	completed_at datetime NULL,
	reviews_imported int(10) unsigned NOT NULL DEFAULT 0,
	reviews_updated int(10) unsigned NOT NULL DEFAULT 0,
	reviews_removed int(10) unsigned NOT NULL DEFAULT 0,
	error_code varchar(64) NOT NULL DEFAULT '',
	error_message text NULL,
	resume_token varchar(500) NOT NULL DEFAULT '',
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY location_created (location_id, created_at),
	KEY status (status)
) {$collate};";

		return array( $locations, $reviews, $widgets, $widget_reviews, $sync_log );
	}

	/**
	 * Add the FULLTEXT index used by review search.
	 *
	 * Kept out of dbDelta, which does not understand FULLTEXT and would try to
	 * recreate the index on every run.
	 *
	 * @return void
	 */
	private static function maybe_add_fulltext_index(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'gbrw_reviews';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$existing = $wpdb->get_results(
			$wpdb->prepare( 'SHOW INDEX FROM `' . esc_sql( $table ) . '` WHERE Key_name = %s', 'review_search' )
		);

		if ( ! empty( $existing ) ) {
			return;
		}

		$wpdb->query( 'ALTER TABLE `' . esc_sql( $table ) . '` ADD FULLTEXT KEY review_search (reviewer_name, review_text)' );
		// phpcs:enable

		if ( '' !== $wpdb->last_error ) {
			// Search falls back to LIKE when the storage engine refuses FULLTEXT.
			Logger::warning( 'Could not create the review search index; falling back to LIKE search.' );
		}
	}

	/**
	 * Table name helper.
	 *
	 * @param string $name Unprefixed table name, e.g. 'reviews'.
	 * @return string Fully prefixed table name.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'gbrw_' . $name;
	}

	/**
	 * Every table this plugin owns, fully prefixed.
	 *
	 * @return array<int, string>
	 */
	public static function all_tables(): array {
		return array(
			self::table( 'sync_log' ),
			self::table( 'widget_reviews' ),
			self::table( 'widgets' ),
			self::table( 'reviews' ),
			self::table( 'locations' ),
		);
	}
}
