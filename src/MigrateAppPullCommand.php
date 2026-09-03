<?php
/**
 * `wp migrate_app_pull <folder> --from=<target>`
 *
 * Runs on the operator's machine. Brings a remote WordPress site home as a
 * package folder — the same folder shape `wp migrate_app` and
 * `wp migrate_app_remote` already read.
 *
 * That shared folder shape is the whole point. This class knows nothing about
 * migrating and the two install commands know nothing about pulling; the folder
 * on disk is the only thing they agree on. So the tool reduces to two steps in
 * any combination:
 *
 *     wp migrate_app_pull ./site --from=@old        # step 1, once
 *     wp migrate_app ./site                         # step 2, into a local WP
 *     wp migrate_app_remote ./site --to=@new        # step 2, into a remote WP
 *
 * Server-to-server is those two lines and nothing else. There is deliberately
 * no `A -> B` command: routing through this machine means the two hosts never
 * need to reach each other, neither host ever holds your key, and the folder
 * left behind is both a resumable checkpoint and a standalone backup of the
 * origin.
 *
 * The origin is treated as read-only. This command runs `wp db export` and
 * reads options; it never imports, never rewrites a URL, never writes a row.
 * The single file it creates on the far end is a temporary dump, removed on the
 * way out — including when the run fails.
 *
 * Registered `before_wp_load` because the machine you type this on usually has
 * no WordPress at all.
 *
 * @package MigrateApp
 */

namespace MigrateApp;

use WP_CLI;
use WP_CLI\Utils;

class MigrateAppPullCommand {

	/** @var bool */
	private $dry_run = false;

	/** @var array<int,array<string,string>> Per-step results for the closing table. */
	private $steps = array();

	/** @var array<int,string> Warnings replayed at the end. */
	private $notes = array();

	/** @var string The `wp` invocation to use on the far end. */
	private $remote_wp = 'wp';

	/** @var string|null Remote path of the temp dump, so the failure path can remove it. */
	private $remote_dump = null;

	/** @var Ssh|null Kept so the shutdown path can reach the origin. */
	private $ssh = null;

	/** @var string|null UTC timestamp of the moment the file copy started. */
	private $files_at = null;

	/** @var string|null UTC timestamp of the moment the database dump completed. */
	private $db_at = null;

	/**
	 * Pull a remote WordPress site into a local package folder.
	 *
	 * Step one of two. The folder this writes is immediately usable by
	 * `wp migrate_app <folder>` on a local install, or by
	 * `wp migrate_app_remote <folder> --to=<target>` against a remote one — no
	 * hand-editing in between.
	 *
	 * Files are pulled BEFORE the database, on purpose. A live site changes
	 * under you, and of the two possible inconsistencies the harmless one is an
	 * uploaded file no row references yet. The reverse — a row pointing at a
	 * file that was never copied — is a broken page.
	 *
	 * ## OPTIONS
	 *
	 * <folder>
	 * : Local directory to create and fill. Refused if it already holds a
	 * package, unless you pass `--force`.
	 *
	 * --from=<target>
	 * : Where to pull from. Either a WP-CLI alias (`@old`) or a connection
	 * string in the same grammar `--ssh` accepts:
	 * `[<scheme>:][<user>@]<host>[:<port>][<path>]`.
	 *
	 * [--remote-path=<path>]
	 * : Absolute path to the WordPress root on the origin. Overrides the path
	 * in the connection string or alias.
	 *
	 * [--identity=<file>]
	 * : SSH private key. Defaults to your ssh-agent / `~/.ssh/config`.
	 *
	 * [--proxyjump=<spec>]
	 * : ProxyJump host, passed to ssh as `-J`.
	 *
	 * [--wp-binary=<command>]
	 * : How to invoke WP-CLI on the origin when plain `wp` is not on its PATH.
	 * Example: `--wp-binary='/usr/local/bin/ea-php81 ~/wp-cli.phar'`.
	 *
	 * [--skip-uploads]
	 * : Leave `wp-content/uploads` behind. The usual reason to reach for this
	 * is a media library measured in tens of gigabytes.
	 *
	 * [--skip-files]
	 * : Database only. No themes, plugins or uploads.
	 *
	 * [--skip-db]
	 * : Files only. No database dump, and no `database:` key in the manifest.
	 *
	 * [--stream-db]
	 * : EXPERIMENTAL. Export the database straight down the SSH pipe instead of
	 * writing a temp file on the origin first. Only for origins with nowhere to
	 * write. Cannot resume, and completeness can only be checked from the dump's
	 * trailing marker rather than an exact byte count — so it is a weaker
	 * guarantee than the default path. Not yet exercised against a live origin.
	 *
	 * [--exclude=<patterns>]
	 * : Extra comma-separated rsync exclude patterns, on top of the defaults
	 * (caches and backup-plugin archives).
	 *
	 * [--dry-run]
	 * : Preflight, measure and report. Transfers nothing, writes nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * [--force]
	 * : Write into the folder even if it already holds a package.
	 *
	 * ## EXAMPLES
	 *
	 *     # Measure the job without moving a byte.
	 *     $ wp migrate_app_pull ./old-site --from=@old --dry-run
	 *
	 *     # Bring it home.
	 *     $ wp migrate_app_pull ./old-site --from=@old
	 *
	 *     # Then install it — locally...
	 *     $ wp migrate_app ./old-site
	 *
	 *     # ...or into another server. This pair IS server-to-server.
	 *     $ wp migrate_app_remote ./old-site --to=@new
	 *
	 *     # Skip a huge media library and copy it separately.
	 *     $ wp migrate_app_pull ./old-site --from=@old --skip-uploads
	 *
	 * @when before_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$folder = isset( $args[0] ) ? (string) $args[0] : '';
		$from   = (string) Utils\get_flag_value( $assoc_args, 'from', '' );

		if ( '' === $from ) {
			WP_CLI::error( 'You must say where to pull from. Example: --from=@old' );
		}

		$local = $this->resolve_destination( $folder, $assoc_args );
		$ssh   = Ssh::from_target( $from, $assoc_args );

		$this->ssh       = $ssh;
		$this->remote_wp = (string) Utils\get_flag_value( $assoc_args, 'wp-binary', 'wp' );

		WP_CLI::log( WP_CLI::colorize( '%bOrigin:%n      ' ) . $ssh->label() );
		WP_CLI::log( WP_CLI::colorize( '%bInto:%n        ' ) . $local );

		$facts = $this->preflight( $ssh, $assoc_args );

		$this->confirm( $ssh, $facts, $local, $assoc_args );

		// 0700, not 0755. This folder is about to hold a production database and
		// a site's whole uploads tree; on a shared machine the default umask
		// hands all of it to every other account.
		if ( ! $this->dry_run && ! is_dir( $local ) && ! mkdir( $local, 0700, true ) && ! is_dir( $local ) ) {
			WP_CLI::error( sprintf( 'Could not create %s', $local ) );
		}

		try {
			if ( ! Utils\get_flag_value( $assoc_args, 'skip-files', false ) ) {
				$this->pull_files( $ssh, $facts, $local, $assoc_args );
			} else {
				$this->step( 'files', 'skipped', '--skip-files' );
			}

			// Database last: see the note on ordering in the docblock.
			if ( ! Utils\get_flag_value( $assoc_args, 'skip-db', false ) ) {
				$dump = $this->pull_database( $ssh, $facts, $local, $assoc_args );
			} else {
				$dump = '';
				$this->step( 'database', 'skipped', '--skip-db' );
			}

			$this->write_manifest( $facts, $local, $dump, $assoc_args );
		} catch ( \RuntimeException $e ) {
			$this->cleanup_remote( $ssh );
			$this->report();
			$ssh->disconnect();
			WP_CLI::error( $e->getMessage() );
		}

		$this->cleanup_remote( $ssh );
		$this->report();
		$ssh->disconnect();

		if ( $this->dry_run ) {
			WP_CLI::success( 'Dry run complete. Nothing was transferred and nothing was written.' );
			return;
		}

		$this->closing_advice( $local );
	}

	/* ---------------------------------------------------------------------
	 * Destination
	 * ------------------------------------------------------------------ */

	/**
	 * Work out the local folder to fill. Creation happens later, once the origin
	 * has answered.
	 *
	 * @param string $folder     Folder argument.
	 * @param array  $assoc_args Flags.
	 * @return string Absolute path.
	 */
	private function resolve_destination( $folder, $assoc_args ) {
		if ( '' === trim( $folder ) ) {
			WP_CLI::error( 'You must name a folder to pull into. Example: wp migrate_app_pull ./old-site --from=@old' );
		}

		$path = 0 === strpos( $folder, '/' ) ? $folder : Fs::join( getcwd(), $folder );
		$path = rtrim( $path, '/' );

		$occupied = is_file( $path . '/migration.yaml' ) || is_dir( $path . '/wp-content' );

		if ( $occupied && ! Utils\get_flag_value( $assoc_args, 'force', false ) ) {
			WP_CLI::error(
				sprintf(
					"%s already holds a package.\nPass --force to write into it anyway, or name a folder that does not exist yet.",
					$path
				)
			);
		}

		if ( is_file( $path ) ) {
			WP_CLI::error( sprintf( '%s is a file, not a directory.', $path ) );
		}

		/*
		 * Deliberately NOT created here. Preflight runs next and can fail for a
		 * dozen reasons — a wrong key, a wrong path, no WP-CLI on the far end —
		 * and a failed pull that leaves an empty directory behind trains the
		 * operator to ignore directories. It is created once the origin has
		 * actually answered.
		 */
		return $path;
	}

	/* ---------------------------------------------------------------------
	 * Preflight
	 * ------------------------------------------------------------------ */

	/**
	 * Establish that the origin can actually be pulled from, and measure it.
	 *
	 * Everything here is a read. The expensive discovery is deliberately done
	 * before the confirmation prompt, so the operator is agreeing to a number
	 * rather than to a hope.
	 *
	 * @param Ssh   $ssh        Connection.
	 * @param array $assoc_args Flags.
	 * @return array<string,mixed>
	 */
	private function preflight( $ssh, $assoc_args ) {
		WP_CLI::log( WP_CLI::colorize( "\n%bChecking the origin...%n" ) );

		$root = $ssh->path();

		if ( '' === $root ) {
			WP_CLI::error( 'No path to the WordPress root on the origin. Add it to the alias, the connection string, or pass --remote-path.' );
		}

		/*
		 * Three separate questions, asked in the order that gives the clearest
		 * error. Deliberately NOT `core is-installed`: it bootstraps WordPress
		 * far enough to warn about an undefined HTTP_HOST on a CLI request, and
		 * a warning in the output of a yes/no check is a bad foundation. Reading
		 * the home URL proves config, database and installation in one go — and
		 * the value is needed anyway.
		 */
		$has_config = $ssh->exec( sprintf( 'test -f %s && echo yes || echo no', escapeshellarg( $root . '/wp-config.php' ) ) );

		/*
		 * `test -f X && echo yes || echo no` exits 0 either way, so a non-zero
		 * code here did not come from the test — it came from the transport.
		 * Distinguishing the two matters more than it looks: reporting a failed
		 * SSH handshake as "no wp-config.php there" sends the operator to check
		 * a path when the actual problem is a key, and that is a long detour.
		 */
		if ( 0 !== $has_config['code'] ) {
			WP_CLI::error(
				sprintf(
					"Could not run a command on %s (exit %d).\n%s\nThis is a connection problem, not a WordPress one. Try `ssh %s` on its own — a missing agent identity, an unknown host key, or a wrong --identity all look like this.",
					$ssh->host_target(),
					$has_config['code'],
					trim( $has_config['err'] ),
					$ssh->host_target()
				)
			);
		}

		if ( 'yes' !== trim( $has_config['out'] ) ) {
			WP_CLI::error(
				sprintf(
					"No wp-config.php at %s on %s.\nPoint --remote-path at the WordPress root.",
					$root,
					$ssh->host()
				)
			);
		}

		$wp_check = $ssh->exec( sprintf( 'cd %s && %s cli version 2>&1', escapeshellarg( $root ), $this->remote_wp ) );

		if ( 0 !== $wp_check['code'] ) {
			WP_CLI::error(
				sprintf(
					"WP-CLI does not run on %s as `%s`.\n%s\nEither install it there, or upload the phar and point at it:\n    scp \"\$(command -v wp)\" %s:~/wp-cli.phar\n    wp migrate_app_pull ... --wp-binary='php ~/wp-cli.phar'",
					$ssh->host(),
					$this->remote_wp,
					trim( $wp_check['out'] . $wp_check['err'] ),
					$ssh->host_target()
				)
			);
		}

		$this->step( 'origin', 'ok', $root );
		$this->step( 'wp-cli', 'ok', trim( $wp_check['out'] ) );

		// Multisite produces a package this tool cannot honestly install, so
		// refuse here rather than hand back something subtly broken.
		$multisite = $ssh->exec(
			sprintf( 'cd %s && %s config get MULTISITE --type=constant 2>/dev/null', escapeshellarg( $root ), $this->remote_wp )
		);

		if ( 0 === $multisite['code'] && in_array( strtolower( trim( $multisite['out'] ) ), array( '1', 'true' ), true ) ) {
			WP_CLI::error( 'The origin is a multisite network. This tool migrates single sites only — pulling one would produce a package it cannot correctly install.' );
		}

		$get = function ( $what ) use ( $ssh, $root ) {
			$r = $ssh->exec(
				sprintf( 'cd %s && %s %s --skip-plugins --skip-themes 2>/dev/null', escapeshellarg( $root ), $this->remote_wp, $what )
			);
			return 0 === $r['code'] ? trim( $r['out'] ) : '';
		};

		$home   = $get( 'option get home' );
		$prefix = $get( 'db prefix' );

		if ( '' === $home ) {
			WP_CLI::error(
				sprintf(
					"WP-CLI runs on %s but could not read the site's home URL from %s.\nThat usually means WordPress is not installed there, or wp-config.php points at a database it cannot reach.\nCheck by hand:\n    ssh %s \"cd %s && wp option get home\"",
					$ssh->host(),
					$root,
					$ssh->host_target(),
					$root
				)
			);
		}

		// WP_CONTENT_DIR can be moved. Ask, then fall back to the default.
		$content = $get( 'config get WP_CONTENT_DIR --type=constant' );
		$content = '' !== $content ? $content : $root . '/wp-content';

		$upload_path = $get( 'option get upload_path' );
		$uploads     = '' !== $upload_path
			? ( 0 === strpos( $upload_path, '/' ) ? $upload_path : $root . '/' . $upload_path )
			: $content . '/uploads';

		$template   = $get( 'option get template' );
		$stylesheet = $get( 'option get stylesheet' );

		// `wp db export` shells out to mysqldump. On hosts with
		// disable_functions=exec,proc_open — which is exactly the population
		// this tool exists for — it fails, and it fails late. Ask now.
		$can_dump = $ssh->remote_has( 'mysqldump' ) || $ssh->remote_has( 'mariadb-dump' );

		if ( ! $can_dump && ! Utils\get_flag_value( $assoc_args, 'skip-db', false ) ) {
			$this->note(
				'Neither mysqldump nor mariadb-dump is on the origin\'s PATH. `wp db export` shells out to one of them, so the database step is likely to fail. If it does, pull with --skip-db and export the database another way.'
			);
		}

		$sizes = array(
			'themes'  => $ssh->remote_dir_bytes( $content . '/themes' ),
			'plugins' => $ssh->remote_dir_bytes( $content . '/plugins' ),
			'uploads' => $ssh->remote_dir_bytes( $uploads ),
		);

		$total = array_sum( $sizes );

		if ( Utils\get_flag_value( $assoc_args, 'skip-uploads', false ) ) {
			$total -= $sizes['uploads'];
		}

		return array(
			'root'       => $root,
			'content'    => rtrim( $content, '/' ),
			'uploads'    => rtrim( $uploads, '/' ),
			'home'       => $home,
			'prefix'     => $prefix,
			'template'   => $template,
			'stylesheet' => $stylesheet,
			'sizes'      => $sizes,
			'total'      => $total,
			'can_dump'   => $can_dump,
		);
	}

	/* ---------------------------------------------------------------------
	 * Confirmation
	 * ------------------------------------------------------------------ */

	/**
	 * Show what was measured, then ask.
	 *
	 * @param Ssh    $ssh        Connection.
	 * @param array  $facts      Preflight findings.
	 * @param string $local      Destination folder.
	 * @param array  $assoc_args Flags.
	 * @return void
	 */
	private function confirm( $ssh, $facts, $local, $assoc_args ) {
		$free = disk_free_space( dirname( $local ) );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%yAbout to pull:%n' ) );
		WP_CLI::log( '    host      ' . $ssh->host() );
		WP_CLI::log( '    path      ' . $facts['root'] );
		WP_CLI::log( '    home URL  ' . $facts['home'] );
		WP_CLI::log( '    prefix    ' . ( '' !== $facts['prefix'] ? $facts['prefix'] : '(could not read)' ) );
		WP_CLI::log( '    theme     ' . $this->theme_label( $facts ) );
		WP_CLI::log( '    themes    ' . Fs::human_bytes( $facts['sizes']['themes'] ) );
		WP_CLI::log( '    plugins   ' . Fs::human_bytes( $facts['sizes']['plugins'] ) );
		WP_CLI::log(
			'    uploads   ' . Fs::human_bytes( $facts['sizes']['uploads'] )
				. ( Utils\get_flag_value( $assoc_args, 'skip-uploads', false ) ? '  (skipped)' : '' )
		);
		WP_CLI::log( '    ------------------------------' );
		WP_CLI::log( '    transfer  ' . Fs::human_bytes( $facts['total'] ) . '  (plus the database dump)' );
		WP_CLI::log( '    into      ' . $local );

		if ( is_float( $free ) && $free > 0 ) {
			WP_CLI::log( '    free here ' . Fs::human_bytes( (int) $free ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'The origin is read-only in this operation. Nothing is imported and no row is written there.' );
		WP_CLI::log( '' );

		// The dump lands on top of the files, and a database is not small.
		// Warn on the measured number rather than on a guess.
		if ( is_float( $free ) && $free > 0 && $facts['total'] > 0 && $free < ( $facts['total'] * 1.5 ) ) {
			WP_CLI::warning(
				sprintf(
					'Only %s free here, against %s of files plus a database dump. This may not fit.',
					Fs::human_bytes( (int) $free ),
					Fs::human_bytes( $facts['total'] )
				)
			);
			WP_CLI::log( '' );
		}

		if ( $this->dry_run ) {
			return;
		}

		WP_CLI::confirm( 'Pull this site?', $assoc_args );
	}

	/**
	 * Human label for the origin's active theme.
	 *
	 * @param array $facts Preflight findings.
	 * @return string
	 */
	private function theme_label( $facts ) {
		if ( '' === $facts['stylesheet'] ) {
			return '(could not read)';
		}

		if ( '' !== $facts['template'] && $facts['template'] !== $facts['stylesheet'] ) {
			return sprintf( '%s (child of %s)', $facts['stylesheet'], $facts['template'] );
		}

		return $facts['stylesheet'];
	}

	/* ---------------------------------------------------------------------
	 * Files
	 * ------------------------------------------------------------------ */

	/**
	 * Bring themes, plugins and uploads home.
	 *
	 * `wp-config.php` is never in scope here — the pull addresses `wp-content`
	 * subdirectories by name, so the file simply cannot be reached. That is
	 * deliberate: it holds the origin's database credentials and salts, and it
	 * has no business on this machine.
	 *
	 * @param Ssh    $ssh        Connection.
	 * @param array  $facts      Preflight findings.
	 * @param string $local      Destination folder.
	 * @param array  $assoc_args Flags.
	 * @return void
	 */
	private function pull_files( $ssh, $facts, $local, $assoc_args ) {
		$this->files_at = gmdate( 'Y-m-d H:i:s' );

		$extra = Ssh::content_noise();

		$user_exclude = (string) Utils\get_flag_value( $assoc_args, 'exclude', '' );
		if ( '' !== trim( $user_exclude ) ) {
			foreach ( explode( ',', $user_exclude ) as $pattern ) {
				$pattern = trim( $pattern );
				if ( '' !== $pattern ) {
					$extra[] = $pattern;
				}
			}
		}

		$jobs = array(
			'themes'  => array( $facts['content'] . '/themes', $local . '/wp-content/themes' ),
			'plugins' => array( $facts['content'] . '/plugins', $local . '/wp-content/plugins' ),
		);

		if ( ! Utils\get_flag_value( $assoc_args, 'skip-uploads', false ) ) {
			$jobs['uploads'] = array( $facts['uploads'], $local . '/wp-content/uploads' );
		}

		foreach ( $jobs as $name => $pair ) {
			list( $remote, $dest ) = $pair;

			if ( 0 === $ssh->remote_dir_bytes( $remote ) ) {
				$this->step( $name, 'absent', $remote );
				continue;
			}

			WP_CLI::log( WP_CLI::colorize( sprintf( "\n%%bPulling %s...%%n", $name ) ) );

			$result = $ssh->pull_dir( $remote, $dest, $extra, $this->dry_run );

			$this->step(
				$name,
				$this->dry_run ? 'dry-run' : 'ok',
				sprintf( '%s via %s', Fs::human_bytes( $result['bytes'] ), $result['method'] )
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Database
	 * ------------------------------------------------------------------ */

	/**
	 * Export the origin database and bring it home, whole.
	 *
	 * "Whole" is the hard part. A dropped connection can truncate a dump and
	 * still exit 0, and a truncated dump is worse than no dump because it
	 * manufactures confidence right before an import. Two different proofs,
	 * depending on the route: a byte-for-byte size comparison when the dump was
	 * written to a file first, and mysqldump's own trailing completion marker
	 * when it was streamed.
	 *
	 * @param Ssh    $ssh        Connection.
	 * @param array  $facts      Preflight findings.
	 * @param string $local      Destination folder.
	 * @param array  $assoc_args Flags.
	 * @return string Relative path of the dump inside the package, or ''.
	 * @throws \RuntimeException On any failure to produce a trustworthy dump.
	 */
	private function pull_database( $ssh, $facts, $local, $assoc_args ) {
		$name = sprintf( '%s-%s.sql', preg_replace( '/[^A-Za-z0-9._-]/', '-', $ssh->host() ), gmdate( 'Ymd-His' ) );
		$dest = $local . '/' . $name;

		if ( $this->dry_run ) {
			$this->step( 'database', 'dry-run', $name );
			return $name;
		}

		WP_CLI::log( WP_CLI::colorize( "\n%bExporting the origin database...%n" ) );

		$stream = (bool) Utils\get_flag_value( $assoc_args, 'stream-db', false );

		if ( $stream ) {
			$this->stream_database( $ssh, $facts, $dest );
		} else {
			$this->file_database( $ssh, $facts, $dest );
		}

		if ( ! Fs::looks_like_sql_dump( $dest ) ) {
			throw new \RuntimeException(
				sprintf( 'The file pulled to %s does not look like a SQL dump. Refusing to call it one.', $dest )
			);
		}

		// Every user, every hashed password, every API key a plugin parked in
		// the options table. Not group- or world-readable.
		@chmod( $dest, 0600 );

		$this->db_at = gmdate( 'Y-m-d H:i:s' );

		$this->step( 'database', 'ok', sprintf( '%s (%s)', $name, Fs::human_bytes( (int) filesize( $dest ) ) ) );

		return $name;
	}

	/**
	 * Dump to a temp file on the origin, then rsync it home and compare sizes.
	 *
	 * The default route, because rsync can resume the single largest file in
	 * the package and because the byte comparison is an exact proof.
	 *
	 * @param Ssh    $ssh   Connection.
	 * @param array  $facts Preflight findings.
	 * @param string $dest  Local destination file.
	 * @return void
	 * @throws \RuntimeException On export or transfer failure.
	 */
	private function file_database( $ssh, $facts, $dest ) {
		$remote = sprintf( '/tmp/migrate-app-pull-%s.sql', gmdate( 'Ymd-His' ) );

		$export = $ssh->exec(
			sprintf(
				'cd %s && %s db export %s --add-drop-table --skip-plugins --skip-themes 2>&1',
				escapeshellarg( $facts['root'] ),
				$this->remote_wp,
				escapeshellarg( $remote )
			)
		);

		if ( 0 !== $export['code'] ) {
			throw new \RuntimeException(
				sprintf(
					"Could not export the origin database.\n%s\n\nIf that mentions exec, proc_open or disable_functions, the host has blocked the shell call mysqldump needs. Pull with --skip-db and export the database another way.",
					trim( $export['out'] . $export['err'] )
				)
			);
		}

		// Recorded before the transfer, so the failure path can still clean up.
		$this->remote_dump = $remote;

		$remote_bytes = $ssh->remote_file_bytes( $remote );

		if ( 0 === $remote_bytes ) {
			throw new \RuntimeException( sprintf( 'The origin wrote an empty dump at %s.', $remote ) );
		}

		if ( ! $ssh->pull_file( $remote, $dest ) ) {
			throw new \RuntimeException( sprintf( 'The dump was written to %s on the origin but could not be brought here.', $remote ) );
		}

		$local_bytes = (int) filesize( $dest );

		if ( $local_bytes !== $remote_bytes ) {
			throw new \RuntimeException(
				sprintf(
					"The dump arrived truncated: %d bytes on the origin, %d bytes here.\nA short dump imports without complaint and loses data silently, so this is refused. Run the pull again.",
					$remote_bytes,
					$local_bytes
				)
			);
		}
	}

	/**
	 * Export straight down the pipe, for origins with nowhere to write.
	 *
	 * No resume, and no size to compare against — so completeness is proved
	 * from the dump's own trailing marker instead.
	 *
	 * @param Ssh    $ssh   Connection.
	 * @param array  $facts Preflight findings.
	 * @param string $dest  Local destination file.
	 * @return void
	 * @throws \RuntimeException On export failure or a truncated stream.
	 */
	private function stream_database( $ssh, $facts, $dest ) {
		WP_CLI::warning( 'Streaming the dump. This cannot resume if the connection drops.' );

		$code = $ssh->pull_command_output(
			sprintf(
				'cd %s && %s db export - --add-drop-table --skip-plugins --skip-themes',
				escapeshellarg( $facts['root'] ),
				$this->remote_wp
			),
			$dest
		);

		if ( 0 !== $code ) {
			throw new \RuntimeException( sprintf( 'The streamed export failed (exit %d).', $code ) );
		}

		if ( ! Fs::sql_dump_is_complete( $dest ) ) {
			throw new \RuntimeException(
				"The streamed dump has no completion marker at the end, which means it was cut short.\nA short dump imports without complaint and loses data silently, so this is refused. Try again without --stream-db so the transfer can resume."
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Manifest
	 * ------------------------------------------------------------------ */

	/**
	 * Write the `migration.yaml` that makes this folder installable.
	 *
	 * `target_url` is written empty on purpose. A pull cannot know where the
	 * package will eventually land, and both install commands already fall back
	 * to the destination's own `home_url()` when the value is blank. Guessing
	 * would be strictly worse than declining to guess — a stale target rewrites
	 * every URL in the database to the wrong domain.
	 *
	 * @param array  $facts      Preflight findings.
	 * @param string $local      Destination folder.
	 * @param string $dump       Dump filename inside the package, or ''.
	 * @param array  $assoc_args Flags.
	 * @return void
	 */
	private function write_manifest( $facts, $local, $dump, $assoc_args ) {
		$path = $local . '/migration.yaml';

		if ( $this->dry_run ) {
			$this->step( 'manifest', 'dry-run', $path );
			return;
		}

		/*
		 * Shared with both install commands. A nested active theme
		 * (`template` = `themes/rem`) has to become the container here, or the
		 * merge flattens it and the destination looks for a directory that is
		 * not there. See ConfigFile::theme_paths().
		 */
		$themes = ConfigFile::theme_paths( $local, $facts['template'], $facts['stylesheet'] );

		$lines = array(
			'# migration.yaml — generated by `wp migrate_app_pull`',
			'# Source: ' . $facts['home'] . ' (pulled ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC)',
			'# Files captured:    ' . ( null !== $this->files_at ? $this->files_at . ' UTC' : 'not pulled' ),
			'# Database captured: ' . ( null !== $this->db_at ? $this->db_at . ' UTC' : 'not pulled' ),
			'# Writes to the origin after the database timestamp are NOT in this package.',
			'# Paths are relative to this folder.',
			'#',
			'# target_url is intentionally EMPTY: the install step uses the destination',
			'# site\'s own home_url(). Set it only if you want something else.',
			'',
			'origin_url: ' . $facts['home'],
			'target_url:',
		);

		if ( 1 === count( $themes ) ) {
			$lines[] = 'theme_path: ' . $themes[0];
		} elseif ( $themes ) {
			$lines[] = 'theme_path:';
			foreach ( $themes as $theme ) {
				$lines[] = '  - ' . $theme;
			}
		} else {
			$lines[] = 'theme_path:';
		}

		$lines[] = 'plugin_path: ' . ( is_dir( $local . '/wp-content/plugins' ) ? 'wp-content/plugins' : '' );
		$lines[] = 'uploads_path: ' . ( is_dir( $local . '/wp-content/uploads' ) ? 'wp-content/uploads' : '' );
		$lines[] = 'database: ' . $dump;
		$lines[] = 'table_prefix: ' . $facts['prefix'];
		$lines[] = '';

		if ( false === file_put_contents( $path, implode( "\n", $lines ) ) ) {
			WP_CLI::error( sprintf( 'Could not write %s', $path ) );
		}

		$this->step( 'manifest', 'ok', 'migration.yaml' );
	}

	/* ---------------------------------------------------------------------
	 * Cleanup and reporting
	 * ------------------------------------------------------------------ */

	/**
	 * Remove the temp dump from the origin.
	 *
	 * Called on the success path AND the failure path. A dump left behind on a
	 * server the operator may not own is the worst kind of litter: it is a full
	 * copy of the database, and nobody is looking for it.
	 *
	 * @param Ssh $ssh Connection.
	 * @return void
	 */
	private function cleanup_remote( $ssh ) {
		if ( null === $this->remote_dump || $this->dry_run ) {
			return;
		}

		// Belt and braces on a path this command constructed itself: absolute,
		// under the directory we chose, and ending in .sql. An `rm` on a
		// production origin is not the place to trust a variable.
		if ( 0 !== strpos( $this->remote_dump, '/tmp/migrate-app-pull-' ) || '.sql' !== substr( $this->remote_dump, -4 ) ) {
			$this->note( sprintf( 'Refusing to remove an unexpected remote path: %s. Delete it by hand.', $this->remote_dump ) );
			$this->remote_dump = null;
			return;
		}

		$result = $ssh->exec( sprintf( 'rm -f %s', escapeshellarg( $this->remote_dump ) ) );

		if ( 0 === $result['code'] ) {
			$this->step( 'cleanup', 'ok', $this->remote_dump );
		} else {
			$this->note(
				sprintf(
					'Could not remove the temporary dump at %s on %s. It is a full copy of the database — delete it by hand.',
					$this->remote_dump,
					$ssh->host()
				)
			);
		}

		$this->remote_dump = null;
	}

	/**
	 * Tell the operator what they now have, and what step two looks like.
	 *
	 * @param string $local Destination folder.
	 * @return void
	 */
	private function closing_advice( $local ) {
		WP_CLI::success( sprintf( 'Pulled into %s', $local ) );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%yStep two — install it:%n' ) );
		WP_CLI::log( '' );
		WP_CLI::log( '    # into a WordPress on this machine' );
		WP_CLI::log( sprintf( '    wp migrate_app %s --dry-run', basename( $local ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( '    # or into one on another server' );
		WP_CLI::log( sprintf( '    wp migrate_app_remote %s --to=@new --dry-run', $local ) );
		WP_CLI::log( '' );

		if ( null !== $this->files_at && null !== $this->db_at ) {
			WP_CLI::log( WP_CLI::colorize( '%yCapture window:%n' ) );
			WP_CLI::log( '    files      ' . $this->files_at . ' UTC' );
			WP_CLI::log( '    database   ' . $this->db_at . ' UTC' );
			WP_CLI::log( '    Anything written to the origin after the database timestamp — orders, comments,' );
			WP_CLI::log( '    uploads — is not in this package. The gap widens for as long as you wait to install.' );
			WP_CLI::log( '' );
		}

		WP_CLI::warning(
			sprintf(
				"%s contains a full copy of the origin's database and uploads.\nTreat it like production data: do not commit it, and delete it once the migration is done and verified.",
				$local
			)
		);
	}

	/**
	 * Record a step for the closing table.
	 *
	 * @param string $step   Step name.
	 * @param string $result Outcome.
	 * @param string $detail Detail.
	 * @return void
	 */
	private function step( $step, $result, $detail = '' ) {
		$this->steps[] = array(
			'step'   => $step,
			'result' => $result,
			'detail' => $detail,
		);
	}

	/**
	 * Record a warning to replay at the end.
	 *
	 * @param string $message Warning.
	 * @return void
	 */
	private function note( $message ) {
		$this->notes[] = $message;
	}

	/**
	 * Print the closing table and any collected warnings.
	 *
	 * @return void
	 */
	private function report() {
		if ( ! $this->steps ) {
			return;
		}

		WP_CLI::log( '' );
		Utils\format_items( 'table', $this->steps, array( 'step', 'result', 'detail' ) );

		foreach ( $this->notes as $note ) {
			WP_CLI::log( '' );
			WP_CLI::warning( $note );
		}
	}
}
