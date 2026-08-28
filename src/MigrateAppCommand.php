<?php
/**
 * `wp migrate_app <folder_name>`
 *
 * This class is a sequencer, not an engine. Every byte-level operation —
 * exporting, importing, serialization-aware replacement — is delegated to
 * WP-CLI core, which already handles PHP serialization correctly. What lives
 * here is the ordering, the prefix reconciliation, and the undo.
 *
 * @package MigrateApp
 */

namespace MigrateApp;

use WP_CLI;
use WP_CLI\Utils;

class MigrateAppCommand {

	/** @var bool */
	private $dry_run = false;

	/** @var string|null Absolute path to the pre-flight database backup. */
	private $backup_path = null;

	/** @var array<int,array<string,string>> Per-step results for the closing table. */
	private $steps = array();

	/** @var array<int,string> Warnings collected along the way, replayed at the end. */
	private $notes = array();

	/**
	 * Migrate an uploaded WordPress package into this installation.
	 *
	 * Reads `<folder_name>/migration.yaml`, backs up the current database,
	 * imports the package database, rewrites the origin URL to the target URL in
	 * a serialization-safe way, and merges the package's theme, plugin and
	 * uploads directories into this site's wp-content.
	 *
	 * ## OPTIONS
	 *
	 * <folder_name>
	 * : Name of the uploaded package folder, relative to the WordPress root
	 * (or an absolute path). Example: `my_site_to_migrated`.
	 *
	 * [--config=<path>]
	 * : Path to the migration config. Defaults to `<folder_name>/migration.yaml`.
	 *
	 * [--generate-config]
	 * : Inspect the folder, write a `migration.yaml` filled in from whatever
	 * manifest it finds — Duplicator's `dup-archive__*.txt` or a Fiction Drafts
	 * `manifest.json` — and exit without migrating anything. Works without a
	 * manifest too, by scanning the dump.
	 *
	 * [--dry-run]
	 * : Run every check and report every action without writing anything.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * [--skip-db]
	 * : Do not touch the database. Files only.
	 *
	 * [--skip-files]
	 * : Do not copy any files. Database only.
	 *
	 * [--skip-backup]
	 * : Do not export a backup first. Strongly discouraged.
	 *
	 * [--backup-dir=<path>]
	 * : Where to write the backup. Defaults to the WordPress root.
	 *
	 * [--cleanup]
	 * : On success, delete the source folder. It contains `dup-installer/`,
	 * which is remotely reachable and should not stay in a live webroot.
	 *
	 * ## EXAMPLES
	 *
	 *     # Let the command write the config for you, then read it before running.
	 *     $ wp migrate_app my_site_to_migrated --generate-config
	 *
	 *     # See exactly what would happen.
	 *     $ wp migrate_app my_site_to_migrated --dry-run
	 *
	 *     # Do it.
	 *     $ wp migrate_app my_site_to_migrated --yes
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$folder_name   = isset( $args[0] ) ? $args[0] : '';
		$this->dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$folder = $this->resolve_folder( $folder_name );

		$dup = new Duplicator( $folder );

		if ( Utils\get_flag_value( $assoc_args, 'generate-config', false ) ) {
			$this->generate_config( $folder, $dup, $assoc_args );
			return;
		}

		$config_path = Utils\get_flag_value( $assoc_args, 'config', $folder . '/migration.yaml' );
		$config      = $this->load_config( $config_path, $folder, $dup );

		$this->preflight( $folder, $config, $dup, $assoc_args );
		$this->confirm( $config, $dup, $assoc_args );

		$skip_db    = (bool) Utils\get_flag_value( $assoc_args, 'skip-db', false );
		$skip_files = (bool) Utils\get_flag_value( $assoc_args, 'skip-files', false );

		try {
			if ( ! $skip_db ) {
				$this->backup( $assoc_args );
				$this->import_database( $config );
				$this->replace_urls( $config );
			} else {
				$this->step( 'database', 'skipped', '--skip-db' );
			}

			if ( ! $skip_files ) {
				$this->migrate_files( $config );
			} else {
				$this->step( 'files', 'skipped', '--skip-files' );
			}

			if ( ! $skip_db ) {
				$this->finish( $config );
			}
		} catch ( \Exception $e ) {
			$this->report();
			$this->print_restore_hint();
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$this->report();
		$this->post_run_notices( $folder, $dup, $config, $assoc_args );
	}

	/* ---------------------------------------------------------------------
	 * Resolution and configuration
	 * ------------------------------------------------------------------ */

	/**
	 * Find the source folder by the name the operator gave.
	 *
	 * @param string $folder_name Name or path.
	 * @return string Absolute path.
	 */
	private function resolve_folder( $folder_name ) {
		$folder_name = trim( (string) $folder_name );

		if ( '' === $folder_name ) {
			WP_CLI::error( 'You must name the folder to migrate. Example: wp migrate_app my_site_to_migrated' );
		}

		$candidate = Fs::resolve( $folder_name, untrailingslashit( ABSPATH ) );

		if ( ! is_dir( $candidate ) ) {
			WP_CLI::error(
				sprintf(
					"Folder not found: %s\nExpected it inside the WordPress root at %s",
					$candidate,
					untrailingslashit( ABSPATH )
				)
			);
		}

		$real     = realpath( $candidate );
		$abs_real = realpath( untrailingslashit( ABSPATH ) );

		if ( $real && $abs_real && $real === $abs_real ) {
			WP_CLI::error( 'The source folder cannot be the WordPress root itself.' );
		}

		WP_CLI::log( WP_CLI::colorize( '%bSource folder:%n ' ) . $real );

		return $real ? $real : $candidate;
	}

	/**
	 * Load and validate migration.yaml, filling gaps from the Duplicator manifest.
	 *
	 * @param string     $config_path Path to the config.
	 * @param string     $folder      Source folder.
	 * @param Duplicator $dup         Manifest reader.
	 * @return array Normalised config.
	 */
	private function load_config( $config_path, $folder, Duplicator $dup ) {
		if ( ! is_file( $config_path ) ) {
			WP_CLI::error(
				sprintf(
					"No migration config at: %s\nRun `wp migrate_app %s --generate-config` to create one.",
					$config_path,
					basename( $folder )
				)
			);
		}

		try {
			$raw = Yaml::parse_file( $config_path );
		} catch ( \Exception $e ) {
			WP_CLI::error( sprintf( 'Could not parse %s: %s', $config_path, $e->getMessage() ) );
			return array();
		}

		WP_CLI::log( WP_CLI::colorize( '%bConfig:%n        ' ) . $config_path . WP_CLI::colorize( ' %K(yaml backend: ' . Yaml::$backend . ')%n' ) );

		$origin = $this->normalize_url( isset( $raw['origin_url'] ) ? $raw['origin_url'] : '' );
		$target = $this->normalize_url( isset( $raw['target_url'] ) ? $raw['target_url'] : '' );

		if ( '' === $origin ) {
			$detected = $dup->detect_origin_url();
			if ( $detected ) {
				$origin = $this->normalize_url( $detected );
				$this->note( sprintf( 'origin_url was empty; detected %s from the Duplicator package.', $origin ) );
			}
		}

		if ( '' === $target ) {
			$target = $this->normalize_url( home_url() );
			$this->note( sprintf( 'target_url was empty; using this site\'s home_url() %s.', $target ) );
		}

		$database = isset( $raw['database'] ) ? (string) $raw['database'] : '';
		$database = '' !== $database ? Fs::resolve( $database, $folder ) : (string) $dup->find_database();

		$config = array(
			'folder'       => $folder,
			'origin_url'   => $origin,
			'target_url'   => $target,
			'database'     => $database,
			'theme_paths'  => $this->resolve_list( isset( $raw['theme_path'] ) ? $raw['theme_path'] : null, $folder ),
			'plugin_paths' => $this->resolve_list( isset( $raw['plugin_path'] ) ? $raw['plugin_path'] : null, $folder ),
			'uploads_path' => isset( $raw['uploads_path'] ) && '' !== $raw['uploads_path']
				? Fs::resolve( $raw['uploads_path'], $folder )
				: '',
			'src_prefix'   => isset( $raw['table_prefix'] ) && $raw['table_prefix']
				? (string) $raw['table_prefix']
				: (string) $dup->detect_prefix( $database ),
		);

		if ( '' === $config['origin_url'] ) {
			WP_CLI::error( 'origin_url is required and could not be detected. Set it in migration.yaml.' );
		}
		if ( '' === $config['target_url'] ) {
			WP_CLI::error( 'target_url is required. Set it in migration.yaml.' );
		}

		return $config;
	}

	/**
	 * Resolve a config value that may be a string or a list into absolute paths.
	 *
	 * @param mixed  $value Raw config value.
	 * @param string $base  Source folder.
	 * @return array<int,string>
	 */
	private function resolve_list( $value, $base ) {
		$out = array();
		foreach ( Fs::to_list( $value ) as $item ) {
			$out[] = Fs::resolve( $item, $base );
		}
		return $out;
	}

	/**
	 * Render configured paths relative to the source folder, for the summary table.
	 *
	 * Absolute paths in the table are noise — the operator already knows where
	 * the folder is, and the interesting part is which subdirectory came across.
	 *
	 * @param array  $paths  Absolute paths.
	 * @param string $folder Source folder.
	 * @return string
	 */
	private function render_paths( array $paths, $folder ) {
		$rendered = array();
		foreach ( array_filter( $paths ) as $path ) {
			$rendered[] = 0 === strpos( $path, $folder . '/' )
				? substr( $path, strlen( $folder ) + 1 )
				: $path;
		}
		return $rendered ? implode( ', ', $rendered ) : '(none)';
	}

	/**
	 * Trim and strip the trailing slash so `https://a.com/` and `https://a.com`
	 * cannot produce `https://b.com//wp-content/...` across the whole site.
	 *
	 * @param mixed $url Raw URL.
	 * @return string
	 */
	private function normalize_url( $url ) {
		return rtrim( trim( (string) $url ), '/' );
	}

	/* ---------------------------------------------------------------------
	 * --generate-config
	 * ------------------------------------------------------------------ */

	/**
	 * Write a migration.yaml derived from the Duplicator manifest and the dump.
	 *
	 * @param string     $folder     Source folder.
	 * @param Duplicator $dup        Manifest reader.
	 * @param array      $assoc_args Flags.
	 * @return void
	 */
	private function generate_config( $folder, Duplicator $dup, $assoc_args ) {
		$out_path = Utils\get_flag_value( $assoc_args, 'config', $folder . '/migration.yaml' );

		$database = $dup->find_database();
		$origin   = $dup->detect_origin_url( $database );
		$prefix   = $dup->detect_prefix( $database );
		$themes   = $dup->detect_themes( $database );

		$rel = function ( $abs ) use ( $folder ) {
			if ( ! $abs ) {
				return '';
			}
			return 0 === strpos( $abs, $folder . '/' ) ? substr( $abs, strlen( $folder ) + 1 ) : $abs;
		};

		// Themes: bring the parent along with the child, or the site renders unstyled.
		$theme_paths = array();
		foreach ( array_unique( array_filter( array( $themes['template'], $themes['stylesheet'] ) ) ) as $slug ) {
			$path = $folder . '/wp-content/themes/' . $slug;
			if ( is_dir( $path ) ) {
				$theme_paths[] = 'wp-content/themes/' . $slug;
			}
		}
		if ( ! $theme_paths && is_dir( $folder . '/wp-content/themes' ) ) {
			$theme_paths[] = 'wp-content/themes';
		}

		$data = array(
			'origin_url'   => $origin ? $origin : '',
			'target_url'   => $this->normalize_url( home_url() ),
			'theme_path'   => 1 === count( $theme_paths ) ? $theme_paths[0] : $theme_paths,
			'plugin_path'  => is_dir( $folder . '/wp-content/plugins' ) ? 'wp-content/plugins' : '',
			'uploads_path' => is_dir( $folder . '/wp-content/uploads' ) ? 'wp-content/uploads' : '',
			'database'     => $rel( $database ),
			'table_prefix' => $prefix ? $prefix : '',
		);

		$header = array(
			'migration.yaml — generated by `wp migrate_app --generate-config`',
			'Read every line before running the migration. Paths are relative to this folder.',
		);
		if ( $dup->has_manifest() ) {
			$versions = $dup->versions();
			$header[] = 'duplicator' === $dup->format()
				? sprintf(
					'Source: %s (Duplicator %s, WP %s, PHP %s)',
					(string) $dup->blogname(),
					isset( $versions['dup'] ) ? $versions['dup'] : '?',
					isset( $versions['wp'] ) ? $versions['wp'] : '?',
					isset( $versions['php'] ) ? $versions['php'] : '?'
				)
				: sprintf(
					'Source: %s (%s export, WP %s, PHP %s, MySQL %s)',
					(string) $dup->blogname(),
					$dup->format_label(),
					isset( $versions['wp'] ) ? $versions['wp'] : '?',
					isset( $versions['php'] ) ? $versions['php'] : '?',
					isset( $versions['db'] ) ? $versions['db'] : '?'
				);
		}
		$header[] = 'table_prefix is the SOURCE prefix; this site\'s own prefix is left untouched.';

		$yaml = Yaml::dump( $data, $header );

		if ( $this->dry_run ) {
			WP_CLI::log( "\n" . $yaml );
			WP_CLI::success( sprintf( '--dry-run: would write %s', $out_path ) );
			return;
		}

		if ( false === file_put_contents( $out_path, $yaml ) ) {
			WP_CLI::error( sprintf( 'Could not write %s', $out_path ) );
		}

		WP_CLI::log( "\n" . $yaml );

		foreach ( array_filter( array( $themes['template'], $themes['stylesheet'] ) ) as $slug ) {
			WP_CLI::log( WP_CLI::colorize( '%gDetected theme:%n ' ) . $slug );
		}

		WP_CLI::success( sprintf( 'Wrote %s — review it, then run: wp migrate_app %s --dry-run', $out_path, basename( $folder ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Preflight
	 * ------------------------------------------------------------------ */

	/**
	 * Everything that must be true before the first destructive write.
	 *
	 * Runs before any DROP, because a failure discovered after the tables are
	 * gone is a blank site.
	 *
	 * @param string     $folder     Source folder.
	 * @param array      $config     Normalised config.
	 * @param Duplicator $dup        Manifest reader.
	 * @param array      $assoc_args Flags.
	 * @return void
	 */
	private function preflight( $folder, $config, Duplicator $dup, $assoc_args ) {
		global $wpdb;

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%bPreflight%n' ) );

		if ( $dup->is_multisite() ) {
			WP_CLI::error(
				sprintf(
					'This is a multisite package%s. migrate_app does not migrate multisite — it would half-work, which is worse than refusing.',
					'' !== $dup->format_label() ? ' (' . $dup->format_label() . ' manifest says so)' : ''
				)
			);
		}

		if ( is_multisite() ) {
			WP_CLI::error( 'This installation is multisite. migrate_app targets single-site installations only.' );
		}

		$this->manifest_checks( $dup );

		// Database reachable.
		$probe = $wpdb->get_var( 'SELECT 1' );
		if ( '1' !== (string) $probe ) {
			WP_CLI::error( 'Cannot query the database. Check wp-config.php credentials before going further.' );
		}
		$this->ok( sprintf( 'database reachable (%s, prefix `%s`)', DB_NAME, $wpdb->prefix ) );

		// wp-content writable.
		if ( ! is_writable( WP_CONTENT_DIR ) ) {
			WP_CLI::error( sprintf( 'wp-content is not writable: %s', WP_CONTENT_DIR ) );
		}
		$this->ok( 'wp-content writable' );

		$skip_db = (bool) Utils\get_flag_value( $assoc_args, 'skip-db', false );

		if ( ! $skip_db ) {
			// Dump present and non-empty.
			if ( ! $config['database'] || ! is_file( $config['database'] ) ) {
				WP_CLI::error( sprintf( 'Database dump not found: %s', $config['database'] ? $config['database'] : '(none configured)' ) );
			}
			$dump_size = (int) filesize( $config['database'] );
			$this->ok( sprintf( 'dump found: %s (%s)', basename( $config['database'] ), Fs::human_bytes( $dump_size ) ) );

			// A zero-byte dup-manual-extract marker means Duplicator expected a manual unpack.
			if ( glob( $folder . '/dup-installer/dup-manual-extract*' ) ) {
				$this->note( 'Package is flagged for manual extraction — confirm the archive was fully unpacked into this folder.' );
			}

			// Disk space: the prefix rewrite writes a second copy of the dump, plus the backup.
			$free = @disk_free_space( untrailingslashit( ABSPATH ) );
			if ( $free && $free < $dump_size * 3 ) {
				WP_CLI::error(
					sprintf(
						'Not enough free disk space: %s free, need roughly %s (dump + rewrite + backup).',
						Fs::human_bytes( $free ),
						Fs::human_bytes( $dump_size * 3 )
					)
				);
			}
			if ( $free ) {
				$this->ok( sprintf( 'disk space: %s free', Fs::human_bytes( $free ) ) );
			}

			// Collation support — checked before anything is dropped.
			$collation = $dup->detect_collation( $config['database'] );
			if ( $collation ) {
				$known = $wpdb->get_var( $wpdb->prepare( 'SELECT COLLATION_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME = %s', $collation ) );
				if ( ! $known ) {
					WP_CLI::error(
						sprintf(
							"This server does not support the collation the dump declares: %s\nImporting would fail after the tables were already dropped. Upgrade MySQL/MariaDB, or convert the dump's collation first.",
							$collation
						)
					);
				}
				$this->ok( sprintf( 'collation %s supported', $collation ) );
			}

			// Prefix reconciliation.
			$src = (string) $config['src_prefix'];
			if ( '' === $src ) {
				$this->note( 'Could not determine the source table prefix; assuming it already matches this site.' );
			} elseif ( $src !== $wpdb->prefix ) {
				$this->ok( sprintf( 'prefix rewrite queued: `%s` -> `%s`', $src, $wpdb->prefix ) );
			} else {
				$this->ok( sprintf( 'prefix matches (`%s`)', $src ) );
			}
		}

		// Drop-ins that will run against a database they no longer know.
		foreach ( array( 'object-cache.php', 'advanced-cache.php', 'db.php' ) as $dropin ) {
			if ( is_file( WP_CONTENT_DIR . '/' . $dropin ) ) {
				$this->note(
					sprintf(
						'wp-content/%s is present. It belongs to a plugin configured for the OLD database. If the site white-screens after migration, rename it.',
						$dropin
					)
				);
			}
		}

		// File sources.
		foreach ( array( 'theme_paths', 'plugin_paths' ) as $key ) {
			foreach ( $config[ $key ] as $path ) {
				if ( ! is_dir( $path ) ) {
					WP_CLI::error( sprintf( 'Configured %s does not exist: %s', $key, $path ) );
				}
			}
		}
		if ( $config['uploads_path'] && ! is_dir( $config['uploads_path'] ) ) {
			WP_CLI::error( sprintf( 'Configured uploads_path does not exist: %s', $config['uploads_path'] ) );
		}
		$this->ok( 'configured source paths exist' );
	}

	/**
	 * Show the operator what is about to happen and get a yes.
	 *
	 * @param array      $config     Normalised config.
	 * @param Duplicator $dup        Manifest reader.
	 * @param array      $assoc_args Flags.
	 * @return void
	 */
	private function confirm( $config, Duplicator $dup, $assoc_args ) {
		global $wpdb;

		$rows = array(
			array( 'setting', 'value' ),
			array( 'source site', (string) $dup->blogname() ),
			array( 'origin_url', $config['origin_url'] ),
			array( 'target_url', $config['target_url'] ),
			array( 'database', $config['database'] ? basename( $config['database'] ) : '(skipped)' ),
			array( 'source prefix', (string) $config['src_prefix'] ),
			array( 'target prefix', $wpdb->prefix ),
			array( 'themes', $this->render_paths( $config['theme_paths'], $config['folder'] ) ),
			array( 'plugins', $this->render_paths( $config['plugin_paths'], $config['folder'] ) ),
			array( 'uploads', $this->render_paths( array( $config['uploads_path'] ), $config['folder'] ) ),
		);

		WP_CLI::log( '' );
		$formatter = new \cli\Table();
		$formatter->setHeaders( array_shift( $rows ) );
		$formatter->setRows( $rows );
		$formatter->display();

		if ( $this->notes ) {
			WP_CLI::log( '' );
			foreach ( $this->notes as $note ) {
				WP_CLI::warning( $note );
			}
			$this->notes = array();
		}

		if ( $this->dry_run ) {
			WP_CLI::log( WP_CLI::colorize( "\n%y--dry-run: nothing below this line writes anything.%n" ) );
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::warning( sprintf( 'This REPLACES the contents of database `%s` (tables prefixed `%s`) and overwrites matching files in wp-content.', DB_NAME, $wpdb->prefix ) );
		WP_CLI::confirm( 'Continue?', $assoc_args );
	}

	/* ---------------------------------------------------------------------
	 * Database
	 * ------------------------------------------------------------------ */

	/**
	 * Export the current database before anything destructive happens.
	 *
	 * @param array $assoc_args Flags.
	 * @return void
	 * @throws \RuntimeException When the export fails.
	 */
	private function backup( $assoc_args ) {
		if ( Utils\get_flag_value( $assoc_args, 'skip-backup', false ) ) {
			$this->step( 'backup', 'skipped', '--skip-backup (no undo available)' );
			$this->note( 'You ran with --skip-backup. There is no way back from here.' );
			return;
		}

		$dir  = (string) Utils\get_flag_value( $assoc_args, 'backup-dir', untrailingslashit( ABSPATH ) );
		$name = sprintf( 'migrate-app-backup-%s.sql', gmdate( 'Ymd-His' ) );
		$path = Fs::join( $dir, $name );

		if ( $this->dry_run ) {
			$this->step( 'backup', 'dry-run', $path );
			return;
		}

		WP_CLI::log( WP_CLI::colorize( "\n%bBacking up the current database...%n" ) );
		WP_CLI::runcommand(
			sprintf( 'db export %s --add-drop-table', escapeshellarg( $path ) ),
			array( 'launch' => true, 'exit_error' => false )
		);

		if ( ! is_file( $path ) || filesize( $path ) < 1 ) {
			throw new \RuntimeException( sprintf( 'Backup failed — refusing to continue without one. Expected: %s', $path ) );
		}

		$this->backup_path = $path;
		$this->step( 'backup', 'ok', sprintf( '%s (%s)', $path, Fs::human_bytes( filesize( $path ) ) ) );
		WP_CLI::success( 'Backup written to ' . $path );
	}

	/**
	 * Drop this site's tables, reconcile the prefix, and stream the dump in.
	 *
	 * @param array $config Normalised config.
	 * @return void
	 * @throws \RuntimeException On failure.
	 */
	private function import_database( $config ) {
		global $wpdb;

		$dump     = $config['database'];
		$src      = (string) $config['src_prefix'];
		$dst      = $wpdb->prefix;
		$rewrote  = null;

		if ( $this->dry_run ) {
			$tables = $this->target_tables();
			$this->step( 'drop tables', 'dry-run', sprintf( '%d tables prefixed `%s`', count( $tables ), $dst ) );
			$this->step( 'prefix rewrite', 'dry-run', ( '' !== $src && $src !== $dst ) ? sprintf( '`%s` -> `%s`', $src, $dst ) : 'not needed' );
			$this->step( 'import', 'dry-run', sprintf( '%s (%s)', basename( $dump ), Fs::human_bytes( filesize( $dump ) ) ) );
			return;
		}

		// 1. Drop only this site's own tables. Never `wp db reset` — that would
		//    take out co-tenants sharing the schema.
		$tables = $this->target_tables();
		if ( $tables ) {
			WP_CLI::log( WP_CLI::colorize( sprintf( "\n%%bDropping %d existing `%s` tables...%%n", count( $tables ), $dst ) ) );
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
			foreach ( $tables as $table ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '', $table ) . '`' );
			}
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' );
		}
		$this->step( 'drop tables', 'ok', sprintf( '%d dropped', count( $tables ) ) );

		// 2. Reconcile the prefix by rewriting the dump, never wp-config.php.
		$import_file = $dump;
		if ( '' !== $src && $src !== $dst ) {
			WP_CLI::log( WP_CLI::colorize( sprintf( "%%bRewriting table prefix `%s` -> `%s`...%%n", $src, $dst ) ) );
			$rewrote     = $this->rewrite_prefix( $dump, $src, $dst );
			$import_file = $rewrote;
			$this->step( 'prefix rewrite', 'ok', sprintf( '`%s` -> `%s`', $src, $dst ) );
		} else {
			$this->step( 'prefix rewrite', 'skipped', 'prefixes already match' );
		}

		// 3. Import. Delegated so multi-hundred-MB dumps stream through mysql
		//    instead of being read into PHP memory.
		$method = 'wp db import';
		try {
			WP_CLI::log( WP_CLI::colorize( "%bImporting database...%n" ) );
			$result = WP_CLI::runcommand(
				sprintf( 'db import %s --skip-optimization', escapeshellarg( $import_file ) ),
				array( 'launch' => true, 'exit_error' => false, 'return' => 'all' )
			);

			if ( 0 !== (int) $result->return_code ) {
				// `wp db import` runs `mysql --execute="SOURCE <file>"`. MySQL client
				// 9.x removed SOURCE from --execute, so that path fails outright on
				// modern hosts. Piping the file in on stdin works on every client
				// version, so fall back to it rather than failing the migration.
				$first_error = trim( $result->stderr . ' ' . $result->stdout );
				WP_CLI::warning( 'wp db import failed; retrying with a direct mysql pipe.' );

				$piped = $this->import_via_pipe( $import_file );
				if ( ! $piped['ok'] ) {
					throw new \RuntimeException(
						"Database import failed.\n  wp db import: " . $first_error . "\n  direct pipe:  " . $piped['error']
					);
				}
				$method = 'mysql < dump';
			}
		} finally {
			if ( $rewrote && is_file( $rewrote ) ) {
				@unlink( $rewrote );
			}
		}

		$imported = count( $this->target_tables() );
		if ( 0 === $imported ) {
			throw new \RuntimeException( sprintf( 'Import reported success but no `%s` tables exist. Restore the backup.', $dst ) );
		}

		$this->step( 'import', 'ok', sprintf( '%d tables (%s)', $imported, $method ) );
		WP_CLI::success( sprintf( 'Imported %d tables.', $imported ) );

		$this->assert_import_complete( $dump, $imported );
	}

	/**
	 * Assert the import actually landed everything it claimed to.
	 *
	 * A dump can import "successfully" while individual tables are rejected —
	 * a DEFINER clause the DB user cannot assume, an index too long for an older
	 * server, a collation only some tables use. The client returns 0 and three
	 * tables are simply missing. Counting CREATE TABLE statements in the dump and
	 * comparing catches that; so does checking that the rows WordPress cannot
	 * boot without are present.
	 *
	 * @param string $dump     Path to the original dump.
	 * @param int    $imported Tables now present with the destination prefix.
	 * @return void
	 * @throws \RuntimeException When a check that means the site is broken fails.
	 */
	private function assert_import_complete( $dump, $imported ) {
		global $wpdb;

		// 1. Table count against the dump's own CREATE TABLE statements.
		$expected = 0;
		$handle   = fopen( $dump, 'r' );
		if ( $handle ) {
			while ( ( $line = fgets( $handle ) ) !== false ) {
				if ( preg_match( '/^\s*CREATE\s+TABLE\s/i', $line ) ) {
					$expected++;
				}
			}
			fclose( $handle );
		}

		if ( $expected > 0 && $imported < $expected ) {
			$this->note(
				sprintf(
					'The dump declares %d tables but only %d imported. %d table(s) were rejected — check for DEFINER clauses, index-length limits, or per-table collations.',
					$expected,
					$imported,
					$expected - $imported
				)
			);
			$this->step( 'assert tables', 'WARN', sprintf( '%d/%d imported', $imported, $expected ) );
		} else {
			$this->step( 'assert tables', 'ok', sprintf( '%d/%d', $imported, $expected > 0 ? $expected : $imported ) );
		}

		// 2. The rows WordPress genuinely cannot run without.
		$roles = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $wpdb->prefix . 'user_roles' )
		);
		if ( ! $roles ) {
			throw new \RuntimeException(
				sprintf(
					'`%suser_roles` is missing after import. Every user would have no role and you would be locked out. Restore the backup.',
					$wpdb->prefix
				)
			);
		}

		$admins = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
				$wpdb->prefix . 'capabilities',
				'%administrator%'
			)
		);
		if ( 0 === $admins ) {
			$this->note( sprintf( 'No user carries an administrator `%scapabilities` row. Create one with: wp user create <login> <email> --role=administrator', $wpdb->prefix ) );
			$this->step( 'assert admin', 'WARN', 'no administrator found' );
		} else {
			$this->step( 'assert admin', 'ok', sprintf( '%d administrator(s)', $admins ) );
		}
	}

	/**
	 * Import a dump by piping it into the mysql client on stdin.
	 *
	 * The portable path. `wp db import` shells out to `mysql --execute="SOURCE
	 * <file>"`, and MySQL client 9.x dropped SOURCE from --execute, so that
	 * breaks on modern hosts. Redirection has worked on every client ever
	 * shipped.
	 *
	 * The password goes through the environment rather than the command line so
	 * it does not show up in `ps` output.
	 *
	 * @param string $file Dump to import.
	 * @return array{ok:bool,error:string}
	 */
	private function import_via_pipe( $file ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return array( 'ok' => false, 'error' => 'proc_open() is disabled on this host.' );
		}

		$args = array( 'mysql', '--no-auto-rehash' );

		if ( defined( 'DB_CHARSET' ) && DB_CHARSET ) {
			$args[] = '--default-character-set=' . DB_CHARSET;
		}

		// DB_HOST can be `host`, `host:port`, `host:/path/to/socket`, or `:/socket`.
		$host   = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
		$socket = '';
		$port   = '';

		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $tail ) = explode( ':', $host, 2 );
			if ( '' !== $tail && ( '/' === $tail[0] || ! ctype_digit( $tail ) ) ) {
				$socket = $tail;
			} else {
				$port = $tail;
			}
		}

		if ( $socket ) {
			$args[] = '--socket=' . $socket;
		} else {
			$args[] = '--host=' . ( '' !== $host ? $host : 'localhost' );
			if ( '' !== $port ) {
				$args[] = '--port=' . $port;
			}
			// Force TCP when a port was given, so `localhost` is not silently
			// reinterpreted as a socket connection by the client.
			if ( '' !== $port ) {
				$args[] = '--protocol=TCP';
			}
		}

		$args[] = '--user=' . DB_USER;
		$args[] = DB_NAME;

		$cmd = implode( ' ', array_map( 'escapeshellarg', $args ) );

		$descriptors = array(
			0 => array( 'file', $file, 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$env = array_merge(
			$_ENV,
			array(
				'MYSQL_PWD' => defined( 'DB_PASSWORD' ) ? DB_PASSWORD : '',
				'PATH'      => getenv( 'PATH' ) ? getenv( 'PATH' ) : '/usr/local/bin:/usr/bin:/bin',
			)
		);

		$proc = proc_open( $cmd, $descriptors, $pipes, null, $env );
		if ( ! is_resource( $proc ) ) {
			return array( 'ok' => false, 'error' => 'Could not start the mysql client.' );
		}

		stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );

		// The client warns about nothing useful on stderr when it succeeds.
		return array(
			'ok'    => 0 === $code,
			'error' => trim( (string) $stderr ),
		);
	}

	/**
	 * Tables in this schema carrying this site's prefix.
	 *
	 * @return array<int,string>
	 */
	private function target_tables() {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$rows = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Stream-rewrite the source table prefix into a temp copy of the dump.
	 *
	 * Anchored to SQL statement keywords, never a bare backtick match — a post
	 * containing a code sample with `wp_options` in backticks would otherwise be
	 * silently rewritten as data.
	 *
	 * @param string $dump Path to the source dump.
	 * @param string $src  Source prefix.
	 * @param string $dst  Destination prefix.
	 * @return string Path to the rewritten temp file.
	 * @throws \RuntimeException On IO failure.
	 */
	private function rewrite_prefix( $dump, $src, $dst ) {
		$tmp = $dump . '.migrate-app-' . getmypid() . '.tmp.sql';

		$in  = fopen( $dump, 'r' );
		$out = fopen( $tmp, 'w' );
		if ( ! $in || ! $out ) {
			if ( $in ) {
				fclose( $in );
			}
			if ( $out ) {
				fclose( $out );
			}
			throw new \RuntimeException( 'Could not open the dump for prefix rewriting.' );
		}

		$q = preg_quote( $src, '/' );

		// Identifiers, anchored to the statements that can introduce a table name.
		$identifier = '/\b(CREATE\s+TABLE|INSERT\s+INTO|REPLACE\s+INTO|ALTER\s+TABLE|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|TRUNCATE\s+TABLE|LOCK\s+TABLES|RENAME\s+TABLE|UPDATE|REFERENCES)(\s+)`' . $q . '/i';

		// Rows whose *value* embeds the prefix: wp_user_roles in options,
		// wp_capabilities / wp_user_level in usermeta. Miss these and every user
		// loses their role after the migration.
		//
		// Either quote style, and the same one back out. This accepted double
		// quotes only until 1.2.0, which meant dumps from mysqldump — and so
		// from `wp db export`, and so every package `migrate_app_pull` produces
		// — sailed through with `wp_user_roles` unrewritten. The import guard in
		// post_import_checks() caught it, but only after the destination
		// database had already been replaced.
		$meta_keys = array( 'user_roles', 'capabilities', 'user_level', 'dashboard_quick_press_last_post_id', 'user-settings', 'user-settings-time' );
		$keys_re   = implode( '|', array_map( function ( $k ) {
			return preg_quote( $k, '/' );
		}, $meta_keys ) );

		$meta_re = '/([\'"])' . $q . '(' . $keys_re . ')\1/';

		/*
		 * The same token inside PHP-serialized data, where the string carries its
		 * own byte length. `wp_` and `dst_` are different lengths, so rewriting
		 * the content alone turns s:15:"wp_capabilities" into a 16-byte string
		 * still claiming 15 — and unserialize() rejects that silently, returning
		 * false. The setting does not error; it evaporates.
		 *
		 * This is the tool's own first principle applied to its own dump
		 * rewriter: serialized data is structured data, so the length travels
		 * with the content. Runs BEFORE the plain pattern, which would otherwise
		 * consume the same match without touching the length.
		 */
		$serialized_re = '/s:(\d+):"' . $q . '(' . $keys_re . ')"/';

		while ( ( $line = fgets( $in ) ) !== false ) {
			$line = preg_replace( $identifier, '$1$2`' . $dst, $line );
			$line = preg_replace_callback(
				$serialized_re,
				function ( $m ) use ( $dst ) {
					$value = $dst . $m[2];
					return 's:' . strlen( $value ) . ':"' . $value . '"';
				},
				$line
			);
			$line = preg_replace( $meta_re, '$1' . $dst . '$2$1', $line );
			if ( false === fwrite( $out, $line ) ) {
				fclose( $in );
				fclose( $out );
				@unlink( $tmp );
				throw new \RuntimeException( 'Ran out of space while rewriting the dump.' );
			}
		}

		fclose( $in );
		fclose( $out );

		return $tmp;
	}

	/* ---------------------------------------------------------------------
	 * URL replacement
	 * ------------------------------------------------------------------ */

	/**
	 * Replace the origin URL with the target URL across every table.
	 *
	 * Three passes, because one is not enough:
	 *   1. the canonical form, serialization-aware, via wp search-replace;
	 *   2. the JSON-escaped form (`https:\/\/host`) that block and page-builder
	 *      content stores, which the serialization walker never unescapes;
	 *   3. the protocol-relative form (`//host`) that themes emit for assets.
	 *
	 * The bare domain is deliberately NOT replaced: it would rewrite e-mail
	 * addresses at that domain and any DNS strings stored in options.
	 *
	 * Each pass runs in its own process, because this one bootstrapped
	 * WordPress against the pre-import database.
	 *
	 * @param array $config Normalised config.
	 * @return void
	 * @throws \RuntimeException On failure.
	 */
	private function replace_urls( $config ) {
		$origin = $config['origin_url'];
		$target = $config['target_url'];

		if ( $origin === $target ) {
			$this->step( 'search-replace', 'skipped', 'origin and target are identical' );
			return;
		}

		$origin_host = (string) wp_parse_url( $origin, PHP_URL_HOST );
		$target_host = (string) wp_parse_url( $target, PHP_URL_HOST );

		$passes = array(
			'canonical'        => array( $origin, $target ),
			'json-escaped'     => array( str_replace( '/', '\\/', $origin ), str_replace( '/', '\\/', $target ) ),
		);

		if ( $origin_host && $target_host && $origin_host !== $target_host ) {
			$passes['protocol-relative'] = array( '//' . $origin_host, '//' . $target_host );
		}

		if ( $this->dry_run ) {
			foreach ( $passes as $label => $pair ) {
				$this->step( 'search-replace (' . $label . ')', 'dry-run', $pair[0] . '  ->  ' . $pair[1] );
			}
			return;
		}

		WP_CLI::log( WP_CLI::colorize( "\n%bRewriting URLs...%n" ) );

		foreach ( $passes as $label => $pair ) {
			// --skip-plugins/--skip-themes is load-bearing, not tidiness. This runs
			// AFTER the import but BEFORE the files are merged, so the freshly
			// imported `active_plugins` names plugins whose code is not on disk
			// yet — and any that IS on disk arrived from a different site. Booting
			// them here risks a fatal that aborts the replacement halfway through,
			// leaving the database half-rewritten. search-replace needs no plugin
			// code to do its job.
			$cmd = sprintf(
				'search-replace %s %s --all-tables-with-prefix --precise --recurse-objects --skip-columns=guid --report-changed-only --skip-plugins --skip-themes',
				escapeshellarg( $pair[0] ),
				escapeshellarg( $pair[1] )
			);

			$result = WP_CLI::runcommand(
				$cmd,
				array( 'launch' => true, 'exit_error' => false, 'return' => 'all' )
			);

			if ( 0 !== (int) $result->return_code ) {
				throw new \RuntimeException( sprintf( 'search-replace (%s) failed: %s', $label, trim( $result->stderr ) ) );
			}

			WP_CLI::log( trim( $result->stdout ) );
			$this->step( 'search-replace (' . $label . ')', 'ok', $pair[0] . '  ->  ' . $pair[1] );
		}

		// Belt and braces: siteurl/home decide whether the site is reachable at
		// all, so assert them directly rather than trusting the sweep.
		foreach ( array( 'siteurl', 'home' ) as $option ) {
			WP_CLI::runcommand(
				sprintf( 'option update %s %s --skip-plugins --skip-themes', $option, escapeshellarg( $target ) ),
				array( 'launch' => true, 'exit_error' => false )
			);
		}
		$this->step( 'siteurl/home', 'ok', $target );
	}

	/* ---------------------------------------------------------------------
	 * Files
	 * ------------------------------------------------------------------ */

	/**
	 * Merge themes, plugins and uploads into this site's wp-content.
	 *
	 * @param array $config Normalised config.
	 * @return void
	 * @throws \RuntimeException On failure.
	 */
	private function migrate_files( $config ) {
		WP_CLI::log( WP_CLI::colorize( "\n%bMerging files...%n" ) );

		foreach ( $config['theme_paths'] as $path ) {
			$this->merge_into( $path, WP_CONTENT_DIR . '/themes', Fs::is_theme_dir( $path ), 'theme' );
		}

		foreach ( $config['plugin_paths'] as $path ) {
			$this->merge_into( $path, WP_CONTENT_DIR . '/plugins', Fs::is_plugin_dir( $path ), 'plugin' );
		}

		if ( $config['uploads_path'] ) {
			$upload_dir = wp_upload_dir( null, false );
			$dst        = ! empty( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : WP_CONTENT_DIR . '/uploads';
			$this->merge_one( $config['uploads_path'], $dst, 'uploads' );
		}
	}

	/**
	 * Copy either a single theme/plugin directory, or every child of a container
	 * directory, into the destination parent.
	 *
	 * @param string $src       Source path.
	 * @param string $dst_root  Destination parent (themes/ or plugins/).
	 * @param bool   $is_single Whether $src is itself one theme/plugin.
	 * @param string $kind      Label for reporting.
	 * @return void
	 */
	private function merge_into( $src, $dst_root, $is_single, $kind ) {
		if ( $is_single ) {
			$this->merge_one( $src, $dst_root . '/' . basename( $src ), $kind . ': ' . basename( $src ) );
			return;
		}

		// A container directory: merge each child, so the destination's own
		// themes and plugins that the source never mentions are untouched.
		$children = array_filter( (array) glob( rtrim( $src, '/' ) . '/*' ), 'is_dir' );

		if ( ! $children ) {
			$this->merge_one( $src, $dst_root, $kind . ': ' . basename( $src ) );
			return;
		}

		foreach ( $children as $child ) {
			$this->merge_one( $child, $dst_root . '/' . basename( $child ), $kind . ': ' . basename( $child ) );
		}

		// Loose files at the container root (hello.php, index.php) come too.
		foreach ( array_filter( (array) glob( rtrim( $src, '/' ) . '/*.php' ), 'is_file' ) as $file ) {
			if ( ! $this->dry_run ) {
				@copy( $file, $dst_root . '/' . basename( $file ) );
			}
		}
	}

	/**
	 * Merge one directory and record the result.
	 *
	 * @param string $src   Source directory.
	 * @param string $dst   Destination directory.
	 * @param string $label Step label.
	 * @return void
	 */
	private function merge_one( $src, $dst, $label ) {
		try {
			$result = Fs::merge_dir( $src, $dst, $this->dry_run );
			$this->step(
				$label,
				$this->dry_run ? 'dry-run' : 'ok',
				sprintf( '%d files -> %s (%s)', $result['files'], $dst, $result['method'] )
			);
			WP_CLI::log( sprintf( '  %s %s (%d files)', $this->dry_run ? '~' : '+', $label, $result['files'] ) );
		} catch ( \Exception $e ) {
			$this->step( $label, 'FAILED', $e->getMessage() );
			WP_CLI::warning( sprintf( '%s: %s', $label, $e->getMessage() ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Finish and reporting
	 * ------------------------------------------------------------------ */

	/**
	 * Flush what has to be flushed for the migrated site to render.
	 *
	 * @param array $config Normalised config.
	 * @return void
	 */
	private function finish( $config ) {
		if ( $this->dry_run ) {
			$this->step( 'flush', 'dry-run', 'rewrite rules + object cache' );
			return;
		}

		WP_CLI::runcommand( 'cache flush --skip-plugins --skip-themes', array( 'launch' => true, 'exit_error' => false ) );

		// rewrite flush deliberately loads plugins and the theme — it needs them to
		// register custom post types before regenerating rules. That makes it the
		// first moment the migrated code actually executes, and a source plugin
		// incompatible with this host will fatal right here. The migration itself
		// is already done, so this must not abort; but permalinks did NOT flush,
		// and saying "ok" when the operator just watched a fatal scroll past would
		// be a lie.
		$flush = WP_CLI::runcommand(
			'rewrite flush --hard',
			array( 'launch' => true, 'exit_error' => false, 'return' => 'all' )
		);

		if ( 0 === (int) $flush->return_code ) {
			$this->step( 'flush', 'ok', 'rewrite rules + object cache' );
		} else {
			$this->step( 'flush', 'WARN', 'rewrite rules NOT flushed — a migrated plugin fataled' );
			$this->note(
				"Permalinks were not flushed: loading the migrated plugins produced a fatal error.\n"
				. "  The database and files migrated fine — this is a plugin that does not run on this host.\n"
				. "  Find it:       wp plugin list --status=active --skip-plugins --skip-themes\n"
				. "  Disable it:    wp plugin deactivate <slug> --skip-plugins --skip-themes\n"
				. "  Then retry:    wp rewrite flush --hard\n"
				. '  Error tail:    ' . $this->last_line( (string) $flush->stderr . (string) $flush->stdout )
			);
		}

		$home = WP_CLI::runcommand(
			'option get home --skip-plugins --skip-themes',
			array( 'launch' => true, 'exit_error' => false, 'return' => 'stdout' )
		);
		$this->step( 'verify home', 'ok', trim( (string) $home ) );
	}

	/**
	 * Print the per-step results table.
	 *
	 * @return void
	 */
	private function report() {
		if ( ! $this->steps ) {
			return;
		}

		WP_CLI::log( '' );
		$table = new \cli\Table();
		$table->setHeaders( array( 'step', 'result', 'detail' ) );
		$table->setRows(
			array_map(
				function ( $s ) {
					return array( $s['step'], $s['result'], $s['detail'] );
				},
				$this->steps
			)
		);
		$table->display();
	}

	/**
	 * What the operator needs to know once the dust settles.
	 *
	 * @param string     $folder     Source folder.
	 * @param Duplicator $dup        Manifest reader.
	 * @param array      $config     Normalised config.
	 * @param array      $assoc_args Flags.
	 * @return void
	 */
	private function post_run_notices( $folder, Duplicator $dup, $config, $assoc_args ) {
		WP_CLI::log( '' );

		$admins = $dup->admin_users();
		if ( $admins && ! Utils\get_flag_value( $assoc_args, 'skip-db', false ) ) {
			WP_CLI::warning(
				sprintf(
					"This site's user table now comes from the source. Log in with a SOURCE account: %s\nIf you cannot, run: wp user create <login> <email> --role=administrator",
					implode( ', ', $admins )
				)
			);
		}

		$this->warn_about_source_paths( $dup, $assoc_args );

		foreach ( $this->notes as $note ) {
			WP_CLI::warning( $note );
		}

		if ( Utils\get_flag_value( $assoc_args, 'cleanup', false ) && ! $this->dry_run ) {
			if ( Fs::rmdir_recursive( $folder ) ) {
				WP_CLI::success( sprintf( 'Removed the source folder: %s', $folder ) );
			} else {
				WP_CLI::warning( sprintf( 'Could not fully remove %s — delete it by hand.', $folder ) );
			}
		} else {
			/*
			 * "Publicly reachable" is only true when the folder is under ABSPATH.
			 * Remote mode stages packages OUTSIDE the webroot on purpose, and
			 * claiming a `$HOME/.migrate-app` path is web-exposed is both alarming
			 * and false — the kind of warning that teaches operators to ignore
			 * warnings.
			 */
			$abspath = realpath( untrailingslashit( ABSPATH ) );
			$real    = realpath( $folder );
			$in_web  = $abspath && $real && 0 === strpos( $real . '/', $abspath . '/' );

			WP_CLI::warning(
				$in_web
					? sprintf(
						"The source folder is still in your webroot and is publicly reachable:\n  %s\nIt contains dup-installer/, which is a remote code execution surface. Delete it now, or re-run with --cleanup.",
						$folder
					)
					: sprintf(
						"The source folder is still on disk:\n  %s\nIt is outside the webroot, so it is not reachable over HTTP, but it still holds a full copy of the source database. Delete it when you are done, or re-run with --cleanup.",
						$folder
					)
			);
		}

		if ( $this->backup_path ) {
			WP_CLI::log( WP_CLI::colorize( '%KRollback if needed:%n wp db import ' . $this->backup_path ) );
			WP_CLI::log( WP_CLI::colorize( '%K              or:%n ' . $this->mysql_restore_command() ) );
		}

		if ( $this->dry_run ) {
			WP_CLI::success( 'Dry run complete. Nothing was written.' );
			return;
		}

		WP_CLI::success( sprintf( 'Migration complete. Visit %s', $config['target_url'] ) );
	}

	/**
	 * Report the origin server's filesystem path if it survived into the options
	 * table, and hand over the command that clears it.
	 *
	 * This is deliberately a report and not an automatic replacement. The URL
	 * passes are safe because a scheme-qualified URL is unambiguous; an absolute
	 * filesystem path is not something to rewrite across a whole database
	 * without the operator looking at what would change first.
	 *
	 * @param Duplicator $dup        Manifest reader.
	 * @param array      $assoc_args Flags.
	 * @return void
	 */
	private function warn_about_source_paths( Duplicator $dup, $assoc_args ) {
		global $wpdb;

		if ( Utils\get_flag_value( $assoc_args, 'skip-db', false ) || $this->dry_run ) {
			return;
		}

		$src_path = $dup->source_abspath();
		if ( ! $src_path ) {
			return;
		}

		$dst_path = untrailingslashit( ABSPATH );
		if ( $src_path === $dst_path ) {
			return;
		}

		$hits = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s",
				'%' . $wpdb->esc_like( $src_path ) . '%'
			)
		);

		if ( 0 === $hits ) {
			return;
		}

		WP_CLI::warning(
			sprintf(
				"The origin server's filesystem path is still stored in %d option row(s):\n  %s\nCache plugins and upload_path keep it. URL replacement does not reach it. Review, then clear it with:\n    wp search-replace %s %s --all-tables-with-prefix --precise --recurse-objects --dry-run",
				$hits,
				$src_path,
				$src_path,
				$dst_path
			)
		);
	}

	/**
	 * Print the restore command when something blew up mid-flight.
	 *
	 * Both forms are printed on purpose. `wp db import` is the obvious one, but
	 * it is built on `mysql --execute="SOURCE <file>"`, which MySQL client 9.x
	 * rejects — the exact hosts where the import fails are the hosts where the
	 * restore hint would fail too. A rollback instruction that does not work is
	 * worse than none.
	 *
	 * @return void
	 */
	private function print_restore_hint() {
		if ( ! $this->backup_path ) {
			return;
		}
		WP_CLI::log( '' );
		WP_CLI::warning( 'The migration failed. Restore the database with either of these:' );
		WP_CLI::log( '    wp db import ' . $this->backup_path );
		WP_CLI::log( '    ' . $this->mysql_restore_command() );
	}

	/**
	 * A `mysql ... < backup` line the operator can paste, for clients where
	 * `wp db import` does not work.
	 *
	 * @return string
	 */
	private function mysql_restore_command() {
		$host = defined( 'DB_HOST' ) ? DB_HOST : 'localhost';
		$port = '';

		if ( false !== strpos( $host, ':' ) ) {
			list( $host, $tail ) = explode( ':', $host, 2 );
			if ( '' !== $tail && ctype_digit( $tail ) ) {
				$port = ' -P' . $tail;
			}
		}

		return sprintf(
			'mysql -h%s%s -u%s -p %s < %s',
			'' !== $host ? $host : 'localhost',
			$port,
			DB_USER,
			DB_NAME,
			$this->backup_path
		);
	}

	/**
	 * Record a step result.
	 *
	 * @param string $step   Step name.
	 * @param string $result ok | skipped | dry-run | FAILED.
	 * @param string $detail Free text.
	 * @return void
	 */
	private function step( $step, $result, $detail = '' ) {
		$this->steps[] = compact( 'step', 'result', 'detail' );
	}

	/**
	 * The most informative line of a noisy failure — usually the one naming the
	 * file and line that fataled.
	 *
	 * @param string $output Combined stdout/stderr.
	 * @return string
	 */
	private function last_line( $output ) {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $output ) ), 'strlen' ) );
		if ( ! $lines ) {
			return '(no output)';
		}

		// Prefer the line that actually names the fatal over the generic tail.
		foreach ( $lines as $line ) {
			if ( false !== stripos( $line, 'fatal error' ) || false !== stripos( $line, '.php on line' ) ) {
				return substr( $line, 0, 300 );
			}
		}

		return substr( end( $lines ), 0, 300 );
	}

	/**
	 * Print a green preflight line.
	 *
	 * @param string $message Text.
	 * @return void
	 */
	/**
	 * Checks that only a manifest can answer.
	 *
	 * A Duplicator package records versions but nothing about what was left out.
	 * A Fiction Drafts export records both, which makes three things checkable
	 * here that were previously only discoverable after the migration:
	 * whether this destination is old enough to choke on the source's code,
	 * whether the folder is carrying the origin's database credentials, and
	 * whether the export was ever meant to be a whole site.
	 *
	 * All three are reported, none refuse. A deliberate `database_only` export
	 * merged into a live site is a legitimate thing to want.
	 *
	 * @param Duplicator $dup Manifest reader.
	 * @return void
	 */
	private function manifest_checks( Duplicator $dup ) {
		if ( ! $dup->has_manifest() ) {
			return;
		}

		$versions = $dup->versions();

		if ( ! empty( $versions['php'] ) && version_compare( (string) $versions['php'], PHP_VERSION, '>' ) ) {
			$this->note(
				sprintf(
					'The source ran PHP %s; this server runs PHP %s. Code written for the newer version can fatal here — check the site loads before deleting the package.',
					$versions['php'],
					PHP_VERSION
				)
			);
		}

		if ( ! empty( $versions['wp'] ) ) {
			$here = (string) get_bloginfo( 'version' );
			if ( '' !== $here && version_compare( (string) $versions['wp'], $here, '>' ) ) {
				$this->note(
					sprintf(
						'The source ran WordPress %s; this site runs %s. Update this site before migrating, or the imported database may reference tables and options this version does not understand.',
						$versions['wp'],
						$here
					)
				);
			}
		}

		if ( true === $dup->includes_wp_config() ) {
			$this->note(
				sprintf(
					'The %s manifest says this export includes wp-config.php. That file holds the ORIGIN database password and all eight authentication salts. It is not merged into this site, but it is sitting in the package folder — delete the folder when you are done.',
					$dup->format_label()
				)
			);
		}

		$areas = $dup->profile_areas();
		$missing = array();
		foreach ( array( 'database', 'uploads', 'core' ) as $area ) {
			if ( array_key_exists( $area, $areas ) && ! $areas[ $area ] ) {
				$missing[] = $area;
			}
		}

		if ( $missing ) {
			$this->note(
				sprintf(
					'This is a partial export — the %s manifest records no %s. That is fine if it is what you meant; it is not a whole site.',
					$dup->format_label(),
					implode( ' and no ', $missing )
				)
			);
		}

		$this->ok( sprintf( '%s manifest read', $dup->format_label() ) );
	}

	/**
	 * Report a passing preflight check.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function ok( $message ) {
		WP_CLI::log( WP_CLI::colorize( '  %g✓%n ' ) . $message );
	}

	/**
	 * Queue a warning for replay at the confirmation prompt and at the end.
	 *
	 * @param string $message Text.
	 * @return void
	 */
	private function note( $message ) {
		$this->notes[] = $message;
	}
}
