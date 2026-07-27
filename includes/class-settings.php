<?php
/**
 * Plugin settings, stored as a single option.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Thin accessor over the `gbrw_settings` option.
 *
 * One option rather than many keeps activation cheap and means a single
 * autoloaded row instead of a dozen.
 */
class Settings {

	private const OPTION = 'gbrw_settings';

	/**
	 * Runtime cache of the decoded option.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Default values for every recognised setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// How often locations are re-synced: 6h, 12h, daily, weekly.
			'sync_interval'            => 'daily',

			// Reviews absent from a successful sync are marked deleted.
			'propagate_deletions'      => true,

			// Show the "Reviews from Google" attribution block. Required unless
			// a compliance review says otherwise — see PLAN.md section 3.
			'show_attribution'         => true,

			// 'relative' (3 weeks ago) or 'absolute' (12 June 2026).
			'date_display'             => 'relative',

			// Verbose logging. Also enabled implicitly by WP_DEBUG.
			'debug_logging'            => false,

			// Drop all tables and options when the plugin is deleted.
			// Defaults off: deleting a customer's imported reviews because they
			// removed the plugin briefly is not recoverable.
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $fallback Value returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $fallback;
	}

	/**
	 * Read every setting, with defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = array_merge( self::defaults(), $stored );

		return self::$cache;
	}

	/**
	 * Write a single setting.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value New value.
	 * @return bool Whether the option was updated.
	 */
	public static function set( string $key, $value ): bool {
		return self::update( array( $key => $value ) );
	}

	/**
	 * Write several settings at once.
	 *
	 * Unknown keys are discarded so a malformed request cannot grow the option.
	 *
	 * @param array<string, mixed> $values Settings to merge in.
	 * @return bool Whether the option was updated.
	 */
	public static function update( array $values ): bool {
		$known    = self::defaults();
		$filtered = array_intersect_key( $values, $known );

		if ( empty( $filtered ) ) {
			return false;
		}

		$merged = array_merge( self::all(), $filtered );

		self::$cache = $merged;

		return update_option( self::OPTION, $merged );
	}

	/**
	 * Forget the runtime cache. Used by tests.
	 *
	 * @return void
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * The option name, for uninstall and tests.
	 *
	 * @return string
	 */
	public static function option_name(): string {
		return self::OPTION;
	}
}
