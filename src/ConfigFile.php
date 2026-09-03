<?php
/**
 * Deriving and rendering a `migration.yaml` from an assembled package folder.
 *
 * Three commands need to write this file and they reach it by different roads:
 *
 *   - `migrate_app --generate-config`        reads the package, and knows the
 *                                            destination because WordPress is
 *                                            loaded around it.
 *   - `migrate_app_remote --generate-config` reads the same package, and must
 *                                            NOT know the destination — see
 *                                            below.
 *   - `migrate_app_pull`                     never reads a package, because it
 *                                            just built one; it carries facts
 *                                            straight off the live origin.
 *
 * What they share is everything after the facts are in hand: which theme paths
 * actually survive the merge, which `wp-content` subdirectories are present,
 * and how the result is rendered. That shared tail lives here. Before this
 * class there were two copies of it, they had already drifted — one rendered
 * through `Yaml::dump()` and the other hand-built its lines — and the theme
 * bug fixed below existed in both.
 *
 * Deliberately free of both WordPress and WP-CLI, so `tests/probe.php` can
 * exercise it with no bootstrap at all.
 *
 * @package MigrateApp
 */

namespace MigrateApp;

class ConfigFile {

	/**
	 * Theme paths for the config, in a form that survives the merge.
	 *
	 * The obvious implementation — name the active theme's directory — is wrong
	 * whenever the origin runs a theme out of a SUBDIRECTORY of the themes root.
	 * WordPress supports that: `search_theme_directories()` descends a second
	 * level when a child holds no `style.css`, and records the theme as
	 * `subdir/theme`. So `template` can legitimately read `themes/rem`, meaning
	 * `wp-content/themes/themes/rem`.
	 *
	 * `MigrateAppCommand::merge_into()` places a single theme at
	 * `WP_CONTENT_DIR/themes/` . basename( $src ), which flattens that to
	 * `wp-content/themes/rem`. The database still says `themes/rem`, so the
	 * destination looks for a directory that no longer exists and the site
	 * renders unstyled — and worse, a *different* theme of the same basename
	 * may already be sitting there, in which case it silently loads the wrong
	 * code. Observed on a real package, where both copies existed and differed.
	 *
	 * The container form is the only one that keeps the nesting, because
	 * `merge_into()` copies each child of a container verbatim. So a nested
	 * active theme forces the container.
	 *
	 * @param string      $folder     Package folder.
	 * @param string|null $template   Active `template` option.
	 * @param string|null $stylesheet Active `stylesheet` option.
	 * @return array<int,string> Relative paths.
	 */
	public static function theme_paths( $folder, $template, $stylesheet ) {
		$folder = rtrim( (string) $folder, '/' );
		$slugs  = array_unique( array_filter( array( (string) $template, (string) $stylesheet ) ) );

		if ( self::is_nested( $template, $stylesheet ) ) {
			// The nesting has to be preserved, and only the container does that.
			return is_dir( $folder . '/wp-content/themes' ) ? array( 'wp-content/themes' ) : array();
		}

		$paths = array();
		foreach ( $slugs as $slug ) {
			if ( is_dir( $folder . '/wp-content/themes/' . $slug ) ) {
				$paths[] = 'wp-content/themes/' . $slug;
			}
		}

		// Nothing resolved — the options are missing, or the directories are
		// not in the package. Fall back to the container rather than to nothing.
		if ( ! $paths && is_dir( $folder . '/wp-content/themes' ) ) {
			$paths[] = 'wp-content/themes';
		}

		return $paths;
	}

	/**
	 * Does the active theme live in a subdirectory of the themes root?
	 *
	 * Exposed separately so a command can explain the container it just wrote,
	 * which otherwise looks like the generator failing to detect a theme.
	 *
	 * @param string|null $template   Active `template` option.
	 * @param string|null $stylesheet Active `stylesheet` option.
	 * @return bool
	 */
	public static function is_nested( $template, $stylesheet ) {
		foreach ( array( (string) $template, (string) $stylesheet ) as $slug ) {
			if ( '' !== $slug && false !== strpos( $slug, '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the config map from facts that are already in hand.
	 *
	 * @param string $folder Package folder.
	 * @param array  $facts  origin_url, target_url, template, stylesheet,
	 *                       database (absolute or relative), prefix.
	 * @return array<string,string|array>
	 */
	public static function build( $folder, array $facts ) {
		$folder = rtrim( (string) $folder, '/' );

		$get = function ( $key ) use ( $facts ) {
			return isset( $facts[ $key ] ) ? (string) $facts[ $key ] : '';
		};

		$themes = self::theme_paths( $folder, $get( 'template' ), $get( 'stylesheet' ) );

		return array(
			'origin_url'   => $get( 'origin_url' ),
			'target_url'   => $get( 'target_url' ),
			'theme_path'   => 1 === count( $themes ) ? $themes[0] : $themes,
			'plugin_path'  => is_dir( $folder . '/wp-content/plugins' ) ? 'wp-content/plugins' : '',
			'uploads_path' => is_dir( $folder . '/wp-content/uploads' ) ? 'wp-content/uploads' : '',
			'database'     => self::relative( $get( 'database' ), $folder ),
			'table_prefix' => $get( 'prefix' ),
		);
	}

	/**
	 * Build and render in one step.
	 *
	 * @param string $folder Package folder.
	 * @param array  $facts  See build().
	 * @param array  $header Comment lines emitted above the document.
	 * @return string
	 */
	public static function render( $folder, array $facts, array $header = array() ) {
		return Yaml::dump( self::build( $folder, $facts ), $header );
	}

	/**
	 * Express a path relative to the package folder when it sits inside it.
	 *
	 * @param string $path   Absolute or relative path.
	 * @param string $folder Package folder.
	 * @return string
	 */
	private static function relative( $path, $folder ) {
		if ( '' === $path ) {
			return '';
		}

		return 0 === strpos( $path, $folder . '/' )
			? substr( $path, strlen( $folder ) + 1 )
			: $path;
	}
}
