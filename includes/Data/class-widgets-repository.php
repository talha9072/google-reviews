<?php
/**
 * Reads and writes widgets.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews\Data;

use GoogleReviews\Install;
use GoogleReviews\Widget\SettingsSchema;

defined( 'ABSPATH' ) || exit;

// This class queries the plugin's own tables, for which WordPress provides no
// higher-level API. Every statement is bound with prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Widget storage.
 *
 * Draft settings and published settings are separate columns, so editing a
 * widget never changes what visitors are already seeing.
 */
class WidgetsRepository {

	/**
	 * Fetch one widget.
	 *
	 * @param int $id Widget ID.
	 * @return object|null
	 */
	public static function find( int $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM `' . esc_sql( Install::table( 'widgets' ) ) . '` WHERE id = %d', $id )
		);
	}

	/**
	 * Fetch every widget, newest first.
	 *
	 * @return array<int, object>
	 */
	public static function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM `' . esc_sql( Install::table( 'widgets' ) ) . '` ORDER BY id DESC' );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Create a widget from default settings.
	 *
	 * @param string $name Widget name.
	 * @return int New widget ID, or 0 on failure.
	 */
	public static function create( string $name ): int {
		global $wpdb;

		$now      = gmdate( 'Y-m-d H:i:s' );
		$settings = SettingsSchema::defaults();

		$inserted = $wpdb->insert(
			Install::table( 'widgets' ),
			array(
				'name'                    => $name,
				'status'                  => 'draft',
				'layout_type'             => $settings['layout'],
				'selection_mode'          => $settings['selection_mode'],
				'settings_json'           => (string) wp_json_encode( $settings ),
				'published_settings_json' => null,
				'settings_version'        => 1,
				'created_at'              => $now,
				'updated_at'              => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Save draft settings. Does not affect what visitors see.
	 *
	 * @param int                  $id       Widget ID.
	 * @param string               $name     Widget name.
	 * @param array<string, mixed> $settings Raw settings.
	 * @return bool
	 */
	public static function save_draft( int $id, string $name, array $settings ): bool {
		global $wpdb;

		$clean = SettingsSchema::sanitize( $settings );

		$updated = $wpdb->update(
			Install::table( 'widgets' ),
			array(
				'name'           => $name,
				'layout_type'    => $clean['layout'],
				'selection_mode' => $clean['selection_mode'],
				'settings_json'  => (string) wp_json_encode( $clean ),
				'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Copy the draft to the published slot, making it live.
	 *
	 * @param int $id Widget ID.
	 * @return bool
	 */
	public static function publish( int $id ): bool {
		global $wpdb;

		$widget = self::find( $id );

		if ( ! $widget ) {
			return false;
		}

		$updated = $wpdb->update(
			Install::table( 'widgets' ),
			array(
				'status'                  => 'published',
				'published_settings_json' => (string) $widget->settings_json,
				'published_at'            => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'              => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Change a widget's status.
	 *
	 * @param int    $id     Widget ID.
	 * @param string $status One of draft, published, paused.
	 * @return bool
	 */
	public static function set_status( int $id, string $status ): bool {
		global $wpdb;

		if ( ! in_array( $status, array( 'draft', 'published', 'paused' ), true ) ) {
			return false;
		}

		$updated = $wpdb->update(
			Install::table( 'widgets' ),
			array(
				'status'     => $status,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Delete a widget and its manual review picks.
	 *
	 * @param int $id Widget ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		$wpdb->delete( Install::table( 'widget_reviews' ), array( 'widget_id' => $id ), array( '%d' ) );

		return false !== $wpdb->delete( Install::table( 'widgets' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * The settings a visitor should see.
	 *
	 * @param object $widget Widget row.
	 * @return array<string, mixed>
	 */
	public static function published_settings( $widget ): array {
		$json = (string) ( $widget->published_settings_json ?? '' );

		if ( '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? SettingsSchema::sanitize( $decoded ) : array();
	}

	/**
	 * The settings the editor should show.
	 *
	 * @param object $widget Widget row.
	 * @return array<string, mixed>
	 */
	public static function draft_settings( $widget ): array {
		$decoded = json_decode( (string) ( $widget->settings_json ?? '' ), true );

		return is_array( $decoded ) ? SettingsSchema::sanitize( $decoded ) : SettingsSchema::defaults();
	}
}
