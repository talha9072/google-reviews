<?php
/**
 * Maps namespaced class names onto the includes/ directory.
 *
 * @package GoogleReviews
 */

namespace GoogleReviews;

defined( 'ABSPATH' ) || exit;

/**
 * Autoloader.
 *
 * `GoogleReviews\Sub\Namespace\ClassName` resolves to
 * `includes/Sub/Namespace/class-class-name.php`.
 */
class Autoloader {

	private const PREFIX = 'GoogleReviews\\';

	/**
	 * Mixed-case acronyms, folded to a single word before splitting.
	 *
	 * All-caps acronyms (API, REST, HTML) are handled by the regex below;
	 * these are the ones that would otherwise split in the wrong place.
	 *
	 * @var array<string, string>
	 */
	private const ACRONYMS = array(
		'OAuth' => 'Oauth',
	);

	/**
	 * Register the autoloader with SPL.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Resolve and load a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function autoload( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$segments = explode( '\\', $relative );
		$class    = array_pop( $segments );

		$directory = '' === implode( '', $segments )
			? ''
			: implode( '/', $segments ) . '/';

		$path = GBRW_PLUGIN_DIR . 'includes/' . $directory . 'class-' . self::to_kebab( $class ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * Convert a CamelCase class name to kebab-case.
	 *
	 * Handles acronyms: `OAuthClient` becomes `oauth-client`, not `o-auth-client`.
	 *
	 * @param string $name Class name.
	 * @return string Kebab-cased name.
	 */
	private static function to_kebab( string $name ): string {
		$name = strtr( $name, self::ACRONYMS );
		$name = preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $name );
		$name = preg_replace( '/([A-Z]+)([A-Z][a-z])/', '$1-$2', (string) $name );

		return strtolower( (string) $name );
	}
}
