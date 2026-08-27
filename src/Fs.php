<?php
/**
 * Path resolution and additive directory merging for migrate_app.
 *
 * "Additive" is the whole point: the source wins for the files it ships, and
 * the destination keeps everything the source never mentions. That is what
 * makes it safe to drop one migrated theme into a wp-content that already has
 * five others in it.
 *
 * @package MigrateApp
 */

namespace MigrateApp;

class Fs {

	/**
	 * Join path segments with a single separator, preserving a leading slash.
	 *
	 * @param string ...$parts Segments.
	 * @return string
	 */
	public static function join( ...$parts ) {
		$clean = array();
		foreach ( $parts as $i => $part ) {
			$part = (string) $part;
			if ( '' === $part ) {
				continue;
			}
			$clean[] = 0 === count( $clean ) ? rtrim( $part, '/' ) : trim( $part, '/' );
		}
		$joined = implode( '/', $clean );
		return '' === $joined ? '/' : $joined;
	}

	/**
	 * Resolve a path from migration.yaml.
	 *
	 * Absolute paths pass through untouched. Relative paths resolve against the
	 * source folder, which is what an operator writing `wp-content/themes/recap`
	 * in the yaml means.
	 *
	 * @param string $path Path as written in the config.
	 * @param string $base Source folder absolute path.
	 * @return string
	 */
	public static function resolve( $path, $base ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			return '';
		}

		// Absolute POSIX, or Windows drive letter, or UNC.
		if ( '/' === $path[0] || preg_match( '#^[A-Za-z]:[\\\\/]#', $path ) || 0 === strpos( $path, '\\\\' ) ) {
			return rtrim( $path, '/' );
		}

		return self::join( $base, $path );
	}

	/**
	 * Normalise a value that may be a single string or a list into a list.
	 *
	 * `theme_path` is the reason this exists: the reference package's active
	 * theme is `recap-child`, whose parent `recap` must come along or the site
	 * renders naked.
	 *
	 * @param mixed $value Scalar or array.
	 * @return array
	 */
	public static function to_list( $value ) {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'trim', array_map( 'strval', $value ) ), 'strlen' ) );
		}
		return array( trim( (string) $value ) );
	}

	/**
	 * Recursively merge $src into $dst without removing anything already in $dst
	 * that the source does not ship.
	 *
	 * Uses rsync when available (fast, preserves modes) and falls back to a PHP
	 * walk otherwise, because shared hosts routinely disable proc_open.
	 *
	 * @param string $src     Source directory.
	 * @param string $dst     Destination directory.
	 * @param bool   $dry_run When true, count what would happen and write nothing.
	 * @return array{files:int,dirs:int,method:string}
	 * @throws \RuntimeException When the source is not a readable directory.
	 */
	public static function merge_dir( $src, $dst, $dry_run = false ) {
		if ( ! is_dir( $src ) ) {
			throw new \RuntimeException( sprintf( 'Source directory does not exist: %s', $src ) );
		}

		$stats = self::count_tree( $src );

		if ( $dry_run ) {
			return array(
				'files'  => $stats['files'],
				'dirs'   => $stats['dirs'],
				'method' => 'dry-run',
			);
		}

		if ( ! is_dir( $dst ) && ! mkdir( $dst, 0755, true ) && ! is_dir( $dst ) ) {
			throw new \RuntimeException( sprintf( 'Cannot create destination directory: %s', $dst ) );
		}

		if ( self::can_rsync() ) {
			// Trailing slash on src copies *contents*; no --delete, so the merge stays additive.
			//
			// -I (--ignore-times) is not optional here. rsync's default quick check
			// skips a file whose size and mtime match the destination's — and -a
			// preserves mtimes, so a source file that differs in content but not in
			// size silently loses to the destination's stale copy. In a migration the
			// source is authoritative, so every file transfers. It also keeps this
			// branch behaviourally identical to the PHP fallback below, which always
			// overwrites.
			$cmd = sprintf( 'rsync -aI %s/ %s/ 2>&1', escapeshellarg( rtrim( $src, '/' ) ), escapeshellarg( rtrim( $dst, '/' ) ) );
			exec( $cmd, $output, $code );
			if ( 0 === $code ) {
				return array(
					'files'  => $stats['files'],
					'dirs'   => $stats['dirs'],
					'method' => 'rsync',
				);
			}
			// Fall through to the PHP walk rather than failing the migration.
		}

		self::copy_tree( $src, $dst );

		return array(
			'files'  => $stats['files'],
			'dirs'   => $stats['dirs'],
			'method' => 'php',
		);
	}

	/**
	 * Is rsync usable in this environment?
	 *
	 * @return bool
	 */
	public static function can_rsync() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) ) {
			return false;
		}
		if ( 0 === stripos( PHP_OS, 'WIN' ) ) {
			return false;
		}
		exec( 'command -v rsync 2>/dev/null', $out, $code );
		return 0 === $code && ! empty( $out );
	}

	/**
	 * Recursive copy that overwrites files but never removes extras.
	 *
	 * @param string $src Source directory.
	 * @param string $dst Destination directory.
	 * @return void
	 * @throws \RuntimeException On unrecoverable copy failure.
	 */
	public static function copy_tree( $src, $dst ) {
		$dir = opendir( $src );
		if ( false === $dir ) {
			throw new \RuntimeException( sprintf( 'Cannot open directory: %s', $src ) );
		}

		if ( ! is_dir( $dst ) && ! mkdir( $dst, 0755, true ) && ! is_dir( $dst ) ) {
			closedir( $dir );
			throw new \RuntimeException( sprintf( 'Cannot create directory: %s', $dst ) );
		}

		while ( false !== ( $entry = readdir( $dir ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$from = $src . '/' . $entry;
			$to   = $dst . '/' . $entry;

			if ( is_link( $from ) ) {
				// Symlinks in a migration package are almost always a packaging
				// accident; skip rather than follow them out of the tree.
				continue;
			}

			if ( is_dir( $from ) ) {
				self::copy_tree( $from, $to );
				continue;
			}

			if ( ! copy( $from, $to ) ) {
				closedir( $dir );
				throw new \RuntimeException( sprintf( 'Failed to copy %s -> %s', $from, $to ) );
			}
			$perms = fileperms( $from );
			if ( false !== $perms ) {
				@chmod( $to, $perms & 0777 );
			}
		}

		closedir( $dir );
	}

	/**
	 * Count files, directories and bytes under a path.
	 *
	 * @param string $path Directory.
	 * @return array{files:int,dirs:int,bytes:int}
	 */
	public static function count_tree( $path ) {
		$files = 0;
		$dirs  = 0;
		$bytes = 0;

		if ( ! is_dir( $path ) ) {
			return compact( 'files', 'dirs', 'bytes' );
		}

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $it as $item ) {
			if ( $item->isDir() ) {
				$dirs++;
				continue;
			}
			$files++;
			$bytes += (int) $item->getSize();
		}

		return compact( 'files', 'dirs', 'bytes' );
	}

	/**
	 * Human-readable byte size.
	 *
	 * @param int $bytes Size.
	 * @return string
	 */
	public static function human_bytes( $bytes ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		$bytes = (float) $bytes;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return sprintf( '%.1f %s', $bytes, $units[ $i ] );
	}

	/**
	 * Does this path look like a single WordPress theme (rather than a folder of themes)?
	 *
	 * @param string $path Directory.
	 * @return bool
	 */
	public static function is_theme_dir( $path ) {
		if ( ! is_file( $path . '/style.css' ) ) {
			return false;
		}
		// A container that happens to hold a stray style.css is still a container.
		return ! self::has_child_matching( $path, array( __CLASS__, 'looks_like_theme' ) );
	}

	/**
	 * Does this path look like a single plugin (rather than a folder of plugins)?
	 *
	 * The header check alone is not enough: `wp-content/plugins/hello.php` is
	 * Hello Dolly, a genuine single-file plugin sitting at the *container* root.
	 * Every real plugins directory therefore answers "yes" to the header test.
	 * So a directory is only one plugin when it has the header AND holds no
	 * child directory that is itself a plugin.
	 *
	 * @param string $path Directory.
	 * @return bool
	 */
	public static function is_plugin_dir( $path ) {
		if ( ! self::looks_like_plugin( $path ) ) {
			return false;
		}
		return ! self::has_child_matching( $path, array( __CLASS__, 'looks_like_plugin' ) );
	}

	/**
	 * Header-only test: does this directory contain a plugin bootstrap file?
	 *
	 * @param string $path Directory.
	 * @return bool
	 */
	public static function looks_like_plugin( $path ) {
		foreach ( (array) glob( $path . '/*.php' ) as $file ) {
			$head = (string) file_get_contents( $file, false, null, 0, 8192 );
			if ( preg_match( '/^[ \t\/*#@]*Plugin Name\s*:/mi', $head ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Header-only test: does this directory contain a theme stylesheet?
	 *
	 * @param string $path Directory.
	 * @return bool
	 */
	public static function looks_like_theme( $path ) {
		return is_file( $path . '/style.css' );
	}

	/**
	 * Is any immediate child directory itself a theme/plugin?
	 *
	 * @param string   $path      Directory.
	 * @param callable $predicate Test applied to each child.
	 * @return bool
	 */
	private static function has_child_matching( $path, $predicate ) {
		foreach ( (array) glob( rtrim( $path, '/' ) . '/*', GLOB_ONLYDIR ) as $child ) {
			if ( call_user_func( $predicate, $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursively delete a directory. Used only by --cleanup, and only ever
	 * against the source folder the operator named.
	 *
	 * @param string $path Directory.
	 * @return bool
	 */
	public static function rmdir_recursive( $path ) {
		if ( ! is_dir( $path ) ) {
			return false;
		}

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $it as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() );
			} else {
				@unlink( $item->getPathname() );
			}
		}

		return @rmdir( $path );
	}
}
