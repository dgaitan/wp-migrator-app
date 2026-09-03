<?php
/**
 * `wp migrate_app_remote <folder> --to=<target>`
 *
 * Runs on the operator's machine. Pushes a package to a remote WordPress
 * install and runs the ALREADY-VERIFIED `migrate_app` command there.
 *
 * The division of labour matters. This class never migrates anything: it
 * preflights the far end, moves bytes, takes an undo home, and then hands off
 * to WP-CLI's own `--ssh`, which builds `ssh <flags> <host> 'cd <path>; wp
 * <args>'` and propagates the exit code. The migration itself is byte-identical
 * to a local run — same sequencer, same prefix reconciliation, same
 * serialization-safe replacement.
 *
 * Registered `before_wp_load` because the machine you type this on usually has
 * no WordPress at all.
 *
 * @package MigrateApp
 */

namespace MigrateApp;

use WP_CLI;
use WP_CLI\Utils;

class MigrateAppRemoteCommand {

	/** @var bool */
	private $dry_run = false;

	/** @var array<int,array<string,string>> Per-step results for the closing table. */
	private $steps = array();

	/** @var array<int,string> Warnings replayed at the end. */
	private $notes = array();

	/** @var string The `wp` invocation to use on the far end. */
	private $remote_wp = 'wp';

	/** @var string|null Local path of the backup we pulled home. */
	private $local_backup = null;

	/** @var string|null Remote path of the same backup. */
	private $remote_backup = null;

	/**
	 * Push a package to a remote WordPress install and migrate it there.
	 *
	 * Checks the far end can do the job before moving a byte, uploads the
	 * package to a staging directory outside the webroot, takes a database
	 * backup and brings it home BEFORE anything destructive happens, then runs
	 * `wp migrate_app` on the remote over WP-CLI's own SSH transport.
	 *
	 * ## OPTIONS
	 *
	 * <folder>
	 * : The package directory on THIS machine — the one holding `migration.yaml`
	 * and `dup-installer/`.
	 *
	 * [--to=<target>]
	 * : Where to migrate to. Either a WP-CLI alias (`@prod`) or a connection
	 * string in the same grammar `--ssh` accepts:
	 * `[<scheme>:][<user>@]<host>[:<port>][<path>]`. Required for everything
	 * except `--generate-config`, which never leaves this machine.
	 *
	 * [--generate-config]
	 * : Write a `migration.yaml` into the package folder and exit. Reads only
	 * the package, so it needs no local WordPress and no network — which is the
	 * whole point, since the local command's `--generate-config` runs
	 * `after_wp_load` and so cannot be reached on a machine that has no
	 * WordPress installed. `target_url` is written EMPTY on purpose: the
	 * destination's own `home_url()` is used at import time.
	 *
	 * [--force]
	 * : With `--generate-config`, overwrite an existing `migration.yaml`.
	 *
	 * [--remote-path=<path>]
	 * : Absolute path to the WordPress root on the remote. Overrides the path
	 * in the connection string or alias.
	 *
	 * [--identity=<file>]
	 * : SSH private key. Defaults to your ssh-agent / `~/.ssh/config`.
	 *
	 * [--proxyjump=<spec>]
	 * : ProxyJump host, passed to ssh as `-J`.
	 *
	 * [--staging=<path>]
	 * : Remote directory to upload into. Defaults to `$HOME/.migrate-app` on
	 * the remote, which is outside the webroot and therefore not reachable
	 * over HTTP.
	 *
	 * [--wp-binary=<command>]
	 * : How to invoke WP-CLI on the remote when plain `wp` is not on its PATH.
	 * Example: `--wp-binary='/usr/local/bin/ea-php81 ~/wp-cli.phar'`.
	 *
	 * [--push-only]
	 * : Upload and stop. Nothing on the remote is modified.
	 *
	 * [--skip-push]
	 * : The package is already staged on the remote. Skip the upload and run.
	 *
	 * [--backup-dir=<path>]
	 * : Where to write the backup on THIS machine. Defaults to the working
	 * directory.
	 *
	 * [--dry-run]
	 * : Preflight and report. Transfers nothing, changes nothing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * [--cleanup]
	 * : On success, delete the staging directory and the uploaded tool from the
	 * remote.
	 *
	 * [--force-unlock]
	 * : Take over a lock left behind by an interrupted run. Check the far end is
	 * not still importing first.
	 *
	 * [--cleanup-only]
	 * : Delete the staging directory and stop. Nothing is uploaded and no
	 * migration runs. Use this to get the source database dump off the server
	 * after a migration you ran without `--cleanup`.
	 *
	 * [--skip-db]
	 * : Passed through. Files only.
	 *
	 * [--skip-files]
	 * : Passed through. Database only.
	 *
	 * [--config=<path>]
	 * : Passed through to the migration. Resolved ON THE REMOTE — an absolute
	 * remote path, or one relative to the remote WordPress root. Defaults to
	 * `migration.yaml` inside the staged package.
	 *
	 * ## EXAMPLES
	 *
	 *     # Write the config first. No WordPress and no network needed.
	 *     $ wp migrate_app_remote ./my_site --generate-config
	 *
	 *     # See what would happen, touching nothing.
	 *     $ wp migrate_app_remote ./my_site --to=@prod --dry-run
	 *
	 *     # Upload now, migrate later.
	 *     $ wp migrate_app_remote ./my_site --to=@prod --push-only
	 *     $ wp migrate_app_remote ./my_site --to=@prod --skip-push
	 *
	 *     # Get the dump off the server afterwards.
	 *     $ wp migrate_app_remote ./my_site --to=@prod --cleanup-only
	 *
	 *     # Do it, with an explicit connection string.
	 *     $ wp migrate_app_remote ./my_site \
	 *         --to=deploy@example.com:22/home/deploy/public_html --yes
	 *
	 * @when before_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );

		/*
		 * Generating the config comes before resolve_local(), which refuses a
		 * folder that has no migration.yaml. That refusal is correct for a
		 * migration and absurd for the command whose job is to create the file.
		 */
		if ( Utils\get_flag_value( $assoc_args, 'generate-config', false ) ) {
			$this->generate_config( $this->resolve_package_dir( isset( $args[0] ) ? $args[0] : '' ), $assoc_args );
			return;
		}

		$local  = $this->resolve_local( isset( $args[0] ) ? $args[0] : '' );
		$target = (string) Utils\get_flag_value( $assoc_args, 'to', '' );

		if ( '' === $target ) {
			WP_CLI::error( 'You must say where to migrate to. Example: --to=@prod' );
		}

		$ssh = Ssh::from_target( $target, $assoc_args );

		$this->remote_wp = (string) Utils\get_flag_value( $assoc_args, 'wp-binary', 'wp' );

		WP_CLI::log( WP_CLI::colorize( '%bLocal package:%n ' ) . $local );
		WP_CLI::log( WP_CLI::colorize( '%bRemote target:%n ' ) . $ssh->label() );

		$facts   = $this->preflight( $ssh, $local, $assoc_args );
		$staging = $facts['staging'];
		$package = $staging . '/package/' . basename( $local );
		$tool    = $staging . '/tool';

		if ( Utils\get_flag_value( $assoc_args, 'cleanup-only', false ) ) {
			/*
			 * Cleanup is its own verb. Making the operator re-run a no-op
			 * migration to delete a database dump off a live server is the kind
			 * of friction that ends with the dump never being deleted.
			 */
			$this->cleanup( $ssh, $staging );
			$this->report();
			$ssh->disconnect();
			WP_CLI::success( sprintf( 'Staging removed from %s', $ssh->host() ) );
			return;
		}

		$this->confirm( $ssh, $facts, $local, $assoc_args );

		$push_only = (bool) Utils\get_flag_value( $assoc_args, 'push-only', false );
		$skip_push = (bool) Utils\get_flag_value( $assoc_args, 'skip-push', false );

		try {
			if ( ! $skip_push ) {
				$this->push( $ssh, $local, $package, $tool );
			} else {
				$this->step( 'upload', 'skipped', '--skip-push' );
			}

			if ( $push_only ) {
				$this->step( 'migrate', 'skipped', '--push-only' );
				$this->report();
				WP_CLI::success(
					sprintf(
						"Package staged at %s\nRun it when you are ready:\n    wp migrate_app_remote %s --to=%s --skip-push",
						$package,
						basename( $local ),
						$target
					)
				);
				$ssh->disconnect();
				return;
			}

			$this->lock( $ssh, $staging, $assoc_args );

			if ( ! Utils\get_flag_value( $assoc_args, 'skip-db', false ) ) {
				$this->backup( $ssh, $staging, $assoc_args );
			}

			$this->migrate( $ssh, $package, $tool, $assoc_args );

			$this->unlock( $ssh, $staging );

			if ( Utils\get_flag_value( $assoc_args, 'cleanup', false ) ) {
				$this->cleanup( $ssh, $staging );
			}
		} catch ( \Exception $e ) {
			$this->unlock( $ssh, $staging );
			$this->report();
			$this->print_restore_hint( $ssh );
			$ssh->disconnect();
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$this->report();
		$ssh->disconnect();

		if ( $this->dry_run ) {
			WP_CLI::success( 'Dry run complete. Nothing was transferred and nothing was changed.' );
			return;
		}

		WP_CLI::success( sprintf( 'Migrated into %s', $ssh->label() ) );

		if ( $this->local_backup ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%bYour undo is on this machine:%n ' ) . $this->local_backup );
		}
	}

	/**
	 * Resolve and sanity-check the local package directory.
	 *
	 * @param string $folder Path as typed.
	 * @return string Absolute path.
	 */
	private function resolve_local( $folder ) {
		$path = $this->resolve_package_dir( $folder );

		if ( ! is_file( $path . '/migration.yaml' ) ) {
			WP_CLI::error(
				sprintf(
					"No migration.yaml in %s\n"
						. "It has to exist here, on this machine — it travels with the package and the remote reads the uploaded copy.\n\n"
						. "Write one now, from the package alone (no WordPress and no network needed):\n"
						. '    wp migrate_app_remote %s --generate-config',
					$path,
					$folder
				)
			);
		}

		return $path;
	}

	/**
	 * Resolve the package folder without requiring a config inside it.
	 *
	 * Split out of resolve_local() so `--generate-config` can reach a folder
	 * that does not have a migration.yaml yet — which is every folder it is
	 * ever pointed at.
	 *
	 * @param string $folder Folder as typed.
	 * @return string Absolute path.
	 */
	private function resolve_package_dir( $folder ) {
		$folder = trim( (string) $folder );

		if ( '' === $folder ) {
			WP_CLI::error( 'Name the package folder on this machine. Example: wp migrate_app_remote ./my_site --to=@prod' );
		}

		$path = Fs::resolve( $folder, getcwd() );

		if ( ! is_dir( $path ) ) {
			/*
			 * The most confusing way to land here is `wp --ssh=@prod
			 * migrate_app_remote ./pkg --to=@prod`. WP-CLI intercepts --ssh
			 * before dispatch, so THIS command runs on the remote and looks for
			 * the package there. A guard inside the command cannot catch it —
			 * Runner strips --ssh from argv on the way — so the hint goes here,
			 * on the error the operator actually sees.
			 */
			WP_CLI::error(
				sprintf(
					"Not a directory: %s\nIf you passed a global --ssh, drop it. WP-CLI would run this command ON the remote and look for the package there; name the target with --to instead.",
					$path
				)
			);
		}

		$real = realpath( $path );

		return $real ? $real : $path;
	}

	/* ---------------------------------------------------------------------
	 * --generate-config
	 * ------------------------------------------------------------------ */

	/**
	 * Write a migration.yaml for a package, without WordPress and without SSH.
	 *
	 * This exists because the local command cannot do it here. `migrate_app` is
	 * registered `after_wp_load`, so on a machine with no WordPress installed
	 * WP-CLI never dispatches it at all — the operator sees a bootstrap error,
	 * not a missing-config error, and no amount of flags gets them past it. The
	 * documented workaround was to borrow an unrelated WordPress install with
	 * `--path=`, which works and reads like an apology.
	 *
	 * It deliberately does NOT connect to the target, even though this command
	 * has a `--to` and knows how to use it:
	 *
	 *   - `target_url` is written EMPTY regardless, because the importer falls
	 *     back to the destination's own `home_url()` and a literal that goes
	 *     stale rewrites every URL in the database to the wrong host. Reading
	 *     the value over SSH would make it accurate today and a liability the
	 *     day the package is installed somewhere else. Blank never goes stale.
	 *   - Nothing else in the file describes the destination. It is entirely
	 *     derived from the package.
	 *
	 * So the network buys nothing and costs the offline case. `--dry-run` is
	 * where you go to see the far end.
	 *
	 * @param string $folder     Package folder on this machine.
	 * @param array  $assoc_args Flags.
	 * @return void
	 */
	private function generate_config( $folder, $assoc_args ) {
		$path = $folder . '/migration.yaml';

		if ( is_file( $path ) && ! Utils\get_flag_value( $assoc_args, 'force', false ) ) {
			WP_CLI::error(
				sprintf(
					"migration.yaml already exists: %s\nRe-run with --force to overwrite it, or read it and run the migration.",
					$path
				)
			);
		}

		$dup      = new Duplicator( $folder );
		$database = $dup->find_database();

		if ( ! $database ) {
			WP_CLI::error(
				sprintf(
					"No SQL dump found in %s\nA package needs one. Check the folder is the extracted package root.",
					$folder
				)
			);
		}

		$origin = $dup->detect_origin_url( $database );
		$themes = $dup->detect_themes( $database );
		$prefix = $dup->detect_prefix( $database );

		$facts = array(
			'origin_url' => $origin ? $origin : '',
			// Empty on purpose. See the docblock.
			'target_url' => '',
			'template'   => $themes['template'],
			'stylesheet' => $themes['stylesheet'],
			'database'   => $database,
			'prefix'     => $prefix ? $prefix : '',
		);

		$header = array(
			'migration.yaml — generated by `wp migrate_app_remote --generate-config`',
			'Read every line before running the migration. Paths are relative to this folder.',
			'',
			'target_url is EMPTY on purpose: the remote uses its own home_url() at import',
			'time. Set it only to send the site somewhere other than where it is installed.',
			'table_prefix is the SOURCE prefix; the destination keeps its own.',
		);

		if ( $dup->has_manifest() ) {
			$versions = $dup->versions();
			$header[] = '';
			$header[] = sprintf(
				'Source: %s (%s, WP %s, PHP %s)',
				(string) $dup->blogname(),
				$dup->format_label(),
				isset( $versions['wp'] ) ? $versions['wp'] : '?',
				isset( $versions['php'] ) ? $versions['php'] : '?'
			);
		}

		if ( ConfigFile::is_nested( $themes['template'], $themes['stylesheet'] ) ) {
			$header[] = '';
			$header[] = sprintf(
				'The origin runs its active theme from a SUBDIRECTORY of the themes root (%s),',
				(string) $themes['stylesheet']
			);
			$header[] = 'so theme_path is the container. Naming the theme directly would flatten the';
			$header[] = 'nesting on the destination and the database would point at a path not there.';
		}

		$yaml = ConfigFile::render( $folder, $facts, $header );

		if ( $this->dry_run ) {
			WP_CLI::log( "\n" . $yaml );
			WP_CLI::success( sprintf( '--dry-run: would write %s', $path ) );
			return;
		}

		if ( false === file_put_contents( $path, $yaml ) ) {
			WP_CLI::error( sprintf( 'Could not write %s', $path ) );
		}

		WP_CLI::log( "\n" . $yaml );

		if ( ! $origin ) {
			WP_CLI::warning( 'origin_url could not be detected. Set it by hand — the migration will refuse to run without it.' );
		}

		if ( ConfigFile::is_nested( $themes['template'], $themes['stylesheet'] ) ) {
			WP_CLI::warning(
				sprintf(
					'Active theme is nested (%s), so theme_path is the whole themes container. That is correct — see the comment in the file.',
					(string) $themes['stylesheet']
				)
			);
		}

		WP_CLI::success(
			sprintf(
				"Wrote %s\nRead it, then: wp migrate_app_remote %s --to=<target> --dry-run",
				$path,
				basename( $folder )
			)
		);
	}

	/**
	 * Everything we need to know about the far end, before moving a byte.
	 *
	 * @param Ssh    $ssh        Transport.
	 * @param string $local      Local package directory.
	 * @param array  $assoc_args Flags.
	 * @return array<string,string|int>
	 */
	private function preflight( $ssh, $local, $assoc_args ) {
		WP_CLI::log( WP_CLI::colorize( "\n%bChecking the remote...%n" ) );

		$home = $ssh->exec_or_fail( 'printf %s "$HOME"', 'Reading the remote home directory' );

		if ( '' === $home ) {
			$home = $ssh->path();
		}

		$staging = (string) Utils\get_flag_value( $assoc_args, 'staging', $home . '/.migrate-app' );
		$staging = rtrim( $staging, '/' );

		// WordPress must actually be where the operator said it is. Everything
		// downstream — the migration, the backup, the restore hint — assumes it.
		$has_config = $ssh->exec( sprintf( 'test -f %s && echo yes || echo no', escapeshellarg( $ssh->path() . '/wp-config.php' ) ) );

		if ( 'yes' !== trim( $has_config['out'] ) ) {
			WP_CLI::error(
				sprintf(
					"No wp-config.php at %s on %s.\nPoint --remote-path at the WordPress root.",
					$ssh->path(),
					$ssh->host()
				)
			);
		}

		$this->step( 'wordpress', 'ok', $ssh->path() );

		// WP-CLI on the far end. Without it there is nothing to hand off to.
		$wp_check = $ssh->exec( sprintf( 'cd %s && %s cli version 2>&1', escapeshellarg( $ssh->path() ), $this->remote_wp ) );

		if ( 0 !== $wp_check['code'] ) {
			WP_CLI::error(
				sprintf(
					"WP-CLI does not run on %s as `%s`.\n%s\nEither install it there, or upload the phar and point at it:\n    scp \"\$(command -v wp)\" %s:~/wp-cli.phar\n    wp migrate_app_remote ... --wp-binary='php ~/wp-cli.phar'",
					$ssh->host(),
					$this->remote_wp,
					trim( $wp_check['out'] . $wp_check['err'] ),
					$ssh->host_target()
				)
			);
		}

		$this->step( 'wp-cli', 'ok', trim( $wp_check['out'] ) );

		$php_version = $ssh->exec( 'php -r "echo PHP_VERSION;" 2>/dev/null' );
		$php_version = trim( $php_version['out'] );

		if ( '' !== $php_version && version_compare( $php_version, '7.4', '<' ) ) {
			WP_CLI::error( sprintf( 'Remote PHP is %s. This tool needs 7.4 or newer.', $php_version ) );
		}

		$this->step( 'php', 'ok', '' !== $php_version ? $php_version : 'version not reported' );

		$this->check_open_basedir( $ssh, $staging );

		$this->step( 'transfer', 'ok', $this->transfer_method( $ssh ) );

		$bytes = Ssh::dir_bytes( $local );
		$this->check_space( $ssh, $staging, $bytes );

		return array(
			'staging' => $staging,
			'bytes'   => $bytes,
			'home'    => $home,
		);
	}

	/**
	 * Verify the remote PHP can actually read the staging directory.
	 *
	 * On cPanel-class hosts `open_basedir` confines PHP to `~/public_html` plus
	 * a tmp dir. A package staged in `~/.migrate-app` is then invisible to the
	 * very process that has to read it, and the failure surfaces much later as
	 * a confusing "file not found" from inside the migration.
	 *
	 * @param Ssh    $ssh     Transport.
	 * @param string $staging Remote staging directory.
	 * @return void
	 */
	private function check_open_basedir( $ssh, $staging ) {
		$result = $ssh->exec( 'php -r "echo ini_get(\'open_basedir\');" 2>/dev/null' );
		$value  = trim( $result['out'] );

		if ( '' === $value ) {
			$this->step( 'open_basedir', 'ok', 'unrestricted' );
			return;
		}

		$allowed = array_filter( array_map( 'trim', explode( PATH_SEPARATOR, $value ) ) );

		foreach ( $allowed as $prefix ) {
			if ( 0 === strpos( $staging . '/', rtrim( $prefix, '/' ) . '/' ) ) {
				$this->step( 'open_basedir', 'ok', 'staging is inside ' . $prefix );
				return;
			}
		}

		WP_CLI::error(
			sprintf(
				"Remote PHP has open_basedir=%s, which does not include the staging directory %s.\n"
				. "PHP could not read the package there. Stage somewhere it is allowed — inside the webroot is the usual answer:\n"
				. "    --staging=%s/.migrate-app\n"
				. 'and use --cleanup so the dump does not stay under a live webroot.',
				$value,
				$staging,
				$ssh->path()
			)
		);
	}

	/**
	 * Refuse to start a transfer that cannot finish.
	 *
	 * Budgets three times the package size: the upload itself, the database
	 * backup, and the headroom MySQL wants while importing.
	 *
	 * @param Ssh    $ssh     Transport.
	 * @param string $staging Remote staging directory.
	 * @param int    $bytes   Local package size.
	 * @return void
	 */
	private function check_space( $ssh, $staging, $bytes ) {
		$parent = dirname( $staging );
		$result = $ssh->exec( sprintf( 'df -Pk %s 2>/dev/null | tail -1', escapeshellarg( $parent ) ) );
		$parts  = preg_split( '/\s+/', trim( $result['out'] ) );

		if ( ! is_array( $parts ) || count( $parts ) < 4 || ! ctype_digit( $parts[3] ) ) {
			$this->step( 'disk', 'WARN', 'could not read free space on the remote' );
			$this->note( 'Free space on the remote could not be read. If the upload dies partway, this is why.' );
			return;
		}

		$free   = (int) $parts[3] * 1024;
		$needed = $bytes * 3;

		if ( $free < $needed ) {
			WP_CLI::error(
				sprintf(
					"Not enough room on %s.\nPackage %s, free %s, and the import needs roughly %s including the backup.",
					$ssh->host(),
					Fs::human_bytes( $bytes ),
					Fs::human_bytes( $free ),
					Fs::human_bytes( $needed )
				)
			);
		}

		$this->step( 'disk', 'ok', sprintf( '%s free, %s to send', Fs::human_bytes( $free ), Fs::human_bytes( $bytes ) ) );
	}

	/**
	 * Which mechanism the upload will use, for the preflight table.
	 *
	 * @param Ssh $ssh Transport.
	 * @return string
	 */
	private function transfer_method( $ssh ) {
		if ( $ssh->is_docker() ) {
			return 'docker cp';
		}

		if ( Ssh::have( 'rsync' ) && $ssh->remote_has( 'rsync' ) ) {
			return 'rsync (resumable)';
		}

		$this->note( 'rsync is missing on one end, so the upload cannot resume if it drops. Installing rsync on the remote is worth it for a large package.' );

		return 'tar stream (NOT resumable)';
	}

	/**
	 * Show the operator exactly which site is about to change, then ask.
	 *
	 * The prompt names the resolved remote identity rather than the alias,
	 * because a mistyped alias is the failure this guards against and an alias
	 * name tells you nothing about where it points.
	 *
	 * @param Ssh    $ssh        Transport.
	 * @param array  $facts      Preflight findings.
	 * @param string $local      Local package directory.
	 * @param array  $assoc_args Flags.
	 * @return void
	 */
	private function confirm( $ssh, $facts, $local, $assoc_args ) {
		$home = $ssh->exec( sprintf( 'cd %s && %s option get home --skip-plugins --skip-themes 2>/dev/null', escapeshellarg( $ssh->path() ), $this->remote_wp ) );
		$size = $ssh->exec( sprintf( 'cd %s && %s db size --size_format=mb --skip-plugins --skip-themes 2>/dev/null', escapeshellarg( $ssh->path() ), $this->remote_wp ) );

		$home_url = trim( $home['out'] );
		$planned  = $this->planned_target( $local );

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%yThis will overwrite the database and wp-content of:%n' ) );
		WP_CLI::log( '    host      ' . $ssh->host_target() );
		WP_CLI::log( '    path      ' . $ssh->path() );
		WP_CLI::log( '    home URL  ' . ( '' !== $home_url ? $home_url : '(could not read)' ) );
		WP_CLI::log( '    database  ' . ( '' !== trim( $size['out'] ) ? trim( $size['out'] ) . ' MB' : '(could not read)' ) );
		WP_CLI::log( '    from      ' . $local . ' (' . Fs::human_bytes( (int) $facts['bytes'] ) . ')' );
		WP_CLI::log(
			'    URLs      ' . ( '' !== $planned['origin'] ? $planned['origin'] : '(auto-detected from the dump)' )
				. '  ->  ' . ( '' !== $planned['target'] ? $planned['target'] : 'this site\'s home URL' )
		);
		WP_CLI::log( '' );

		/*
		 * The migration rewrites every URL in the database to target_url. If the
		 * config still carries a target_url from somewhere else — a previous
		 * run, a local test, a copied file — the whole site is rewritten to a
		 * domain that is not this one, and nothing else in this prompt would
		 * have shown it. The inner command does print the value, but only after
		 * the operator has already said yes, because remote mode passes --yes.
		 */
		if ( '' !== $planned['target'] && '' !== $home_url ) {
			$planned_host = (string) parse_url( $planned['target'], PHP_URL_HOST );
			$remote_host  = (string) parse_url( $home_url, PHP_URL_HOST );

			if ( '' !== $planned_host && '' !== $remote_host && strcasecmp( $planned_host, $remote_host ) !== 0 ) {
				WP_CLI::warning(
					sprintf(
						"target_url in migration.yaml is %s, but this site's home URL is %s.\n"
							. "Every URL in the database will be rewritten to %s, not to %s.\n"
							. 'Fix target_url, or delete the value entirely and it will use this site\'s own home URL.',
						$planned['target'],
						$home_url,
						$planned_host,
						$remote_host
					)
				);
				WP_CLI::log( '' );
			}
		}

		if ( $this->dry_run ) {
			return;
		}

		if ( Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
			return;
		}

		if ( Utils\get_flag_value( $assoc_args, 'push-only', false ) ) {
			WP_CLI::confirm( 'Upload the package to this host?' );
			return;
		}

		WP_CLI::confirm( 'Migrate into this site?' );
	}

	/**
	 * The origin and target URLs the migration will actually use.
	 *
	 * Read here purely so the confirmation can show them. This command does not
	 * otherwise parse migration.yaml — the remote reads the uploaded copy, and
	 * that copy is the authority.
	 *
	 * @param string $local Local package directory.
	 * @return array{origin:string,target:string}
	 */
	private function planned_target( $local ) {
		$blank = array(
			'origin' => '',
			'target' => '',
		);

		$path = $local . '/migration.yaml';

		if ( ! is_file( $path ) ) {
			return $blank;
		}

		try {
			$raw = Yaml::parse_file( $path );
		} catch ( \Exception $e ) {
			// Not fatal here: the remote parses it for real and will say so
			// properly. This is only for the confirmation line.
			return $blank;
		}

		return array(
			'origin' => isset( $raw['origin_url'] ) ? trim( (string) $raw['origin_url'] ) : '',
			'target' => isset( $raw['target_url'] ) ? trim( (string) $raw['target_url'] ) : '',
		);
	}

	/**
	 * Upload the tool and the package.
	 *
	 * @param Ssh    $ssh     Transport.
	 * @param string $local   Local package directory.
	 * @param string $package Remote package directory.
	 * @param string $tool    Remote tool directory.
	 * @return void
	 */
	private function push( $ssh, $local, $package, $tool ) {
		WP_CLI::log( WP_CLI::colorize( "\n%bUploading the tool...%n" ) );

		$src = dirname( __DIR__ );
		$res = $ssh->push_dir( $src, $tool, $this->dry_run );
		$this->step( 'tool', $this->dry_run ? 'dry-run' : 'ok', sprintf( '%s -> %s (%s)', Fs::human_bytes( (int) $res['bytes'] ), $tool, $res['method'] ) );

		WP_CLI::log( WP_CLI::colorize( "\n%bUploading the package...%n" ) );

		$res = $ssh->push_dir( $local, $package, $this->dry_run );
		$this->step( 'upload', $this->dry_run ? 'dry-run' : 'ok', sprintf( '%s -> %s (%s)', Fs::human_bytes( (int) $res['bytes'] ), $package, $res['method'] ) );
	}

	/**
	 * Export the remote database and bring the dump home BEFORE importing.
	 *
	 * Ordering is the whole point. A backup that only exists on the machine you
	 * are about to overwrite is not an undo — if the connection dies during the
	 * migration, that is exactly the machine you cannot reach. So the dump is
	 * pulled and verified locally first, and the remote migration then runs with
	 * `--skip-backup` so the database is only dumped once.
	 *
	 * @param Ssh    $ssh        Transport.
	 * @param string $staging    Remote staging directory.
	 * @param array  $assoc_args Flags.
	 * @return void
	 */
	private function backup( $ssh, $staging, $assoc_args ) {
		$name   = sprintf( 'migrate-app-backup-%s-%s.sql', preg_replace( '/[^A-Za-z0-9._-]/', '-', $ssh->host() ), gmdate( 'Ymd-His' ) );
		$remote = $staging . '/' . $name;

		$dir   = (string) Utils\get_flag_value( $assoc_args, 'backup-dir', getcwd() );
		$local = Fs::join( $dir, $name );

		if ( $this->dry_run ) {
			$this->step( 'backup', 'dry-run', $local );
			return;
		}

		WP_CLI::log( WP_CLI::colorize( "\n%bBacking up the remote database...%n" ) );

		$ssh->exec_or_fail( sprintf( 'mkdir -p %s', escapeshellarg( $staging ) ), 'Creating the staging directory' );

		$export = $ssh->exec(
			sprintf(
				'cd %s && %s db export %s --add-drop-table --skip-plugins --skip-themes 2>&1',
				escapeshellarg( $ssh->path() ),
				$this->remote_wp,
				escapeshellarg( $remote )
			)
		);

		if ( 0 !== $export['code'] ) {
			throw new \RuntimeException(
				sprintf( "Could not back up the remote database — refusing to continue without an undo.\n%s", trim( $export['out'] . $export['err'] ) )
			);
		}

		if ( ! $ssh->pull_file( $remote, $local ) ) {
			throw new \RuntimeException(
				sprintf( "The remote backup was written to %s but could not be brought here. Refusing to continue with the undo stranded on the remote.", $remote )
			);
		}

		$this->verify_backup( $local );

		$this->local_backup  = $local;
		$this->remote_backup = $remote;

		$this->step( 'backup', 'ok', sprintf( '%s (%s)', $local, Fs::human_bytes( (int) filesize( $local ) ) ) );
		WP_CLI::success( 'Backup is on this machine: ' . $local );
	}

	/**
	 * Confirm the dump we pulled is actually a dump.
	 *
	 * A truncated backup is worse than no backup, because it manufactures
	 * confidence right before the destructive step. The plausibility test itself
	 * lives in Fs so it can be unit-probed without a remote host.
	 *
	 * @param string $path Local backup file.
	 * @return void
	 */
	private function verify_backup( $path ) {
		if ( Fs::looks_like_sql_dump( $path ) ) {
			return;
		}

		throw new \RuntimeException(
			sprintf(
				'The file at %s is empty, truncated, or does not look like a SQL dump. Refusing to continue on a backup I cannot vouch for.',
				$path
			)
		);
	}

	/**
	 * Hand off to the real migration over WP-CLI's own SSH transport.
	 *
	 * @param Ssh    $ssh        Transport.
	 * @param string $package    Remote package directory.
	 * @param string $tool       Remote tool directory.
	 * @param array  $assoc_args Flags.
	 * @return void
	 */
	private function migrate( $ssh, $package, $tool, $assoc_args ) {
		$flags = array( '--yes' );

		foreach ( array( 'skip-db', 'skip-files' ) as $flag ) {
			if ( Utils\get_flag_value( $assoc_args, $flag, false ) ) {
				$flags[] = '--' . $flag;
			}
		}

		if ( ! Utils\get_flag_value( $assoc_args, 'skip-db', false ) ) {
			// We already have a verified copy on this machine; dumping again
			// would double the work on a large database for no extra safety.
			$flags[] = '--skip-backup';
		}

		$config = (string) Utils\get_flag_value( $assoc_args, 'config', '' );
		if ( '' !== $config ) {
			$flags[] = '--config=' . escapeshellarg( $config );
		}

		if ( $this->dry_run ) {
			/*
			 * A dry run uploads nothing, so there is no tool on the far end to
			 * `--require`. Handing off anyway fails with "Required file
			 * 'migrate-app.php' doesn't exist", which reads like a bug in the
			 * tool rather than the expected consequence of changing nothing.
			 *
			 * Pushing the tool "just for the dry run" is not the answer either:
			 * a dry run that writes to the remote is not a dry run.
			 */
			$this->step( 'migrate', 'dry-run', 'skipped — nothing is uploaded during a dry run' );
			$this->note( "A dry run checks the far end and stops. To exercise the migration itself without changing anything:\n    --push-only, then --skip-push with the remote command's own --dry-run." );
			return;
		}

		$entry = $tool . '/migrate-app.php';

		$present = $ssh->exec( sprintf( 'test -f %s && echo yes || echo no', escapeshellarg( $entry ) ) );

		if ( 'yes' !== trim( $present['out'] ) ) {
			throw new \RuntimeException(
				sprintf( 'The tool is not on the remote at %s. Drop --skip-push, or push again.', $entry )
			);
		}

		/*
		 * The command cannot be handed off with `--require=<remote path>`, even
		 * though WP-CLI forwards every argument verbatim. `--require` is resolved
		 * during LOCAL bootstrap — before the SSH dispatch happens — so a path
		 * that only exists on the far end fails here with "Required file
		 * 'migrate-app.php' doesn't exist". The same string has to be valid on
		 * both machines, which in general it cannot be.
		 *
		 * So the require travels in a config file the remote reads instead.
		 * WP_CLI_CONFIG_PATH replaces only the GLOBAL config (~/.wp-cli/config.yml);
		 * a project wp-cli.yml in the WordPress root is found separately by
		 * walking up from cwd, and is left alone.
		 */
		$config_yml = dirname( $tool ) . '/wp-cli.yml';

		$ssh->exec_or_fail(
			sprintf(
				'printf %s > %s',
				escapeshellarg( "require:\n  - " . $entry . "\n" ),
				escapeshellarg( $config_yml )
			),
			'Writing the remote WP-CLI config'
		);

		/*
		 * A synthesised runtime alias rather than a bare `--ssh=`, because
		 * `--ssh` alone cannot carry an identity file or a ProxyJump: WP-CLI
		 * reads those from ALIAS config only — Runner::generate_ssh_command()
		 * looks at `$this->aliases[$this->alias]['key'|'proxyjump']`. Without
		 * this, `--identity` would apply to the upload and then silently not
		 * apply to the migration, which is the worst kind of half-working.
		 *
		 * WP_CLI_RUNTIME_ALIAS is WP-CLI's own mechanism for exactly this, and
		 * the alias is rebuilt from resolved parts so --remote-path applies here
		 * too.
		 */
		$alias_name = '@migrate-app-target';
		$alias_body = array( 'ssh' => $ssh->connection_string() );

		if ( '' !== $ssh->key() ) {
			$alias_body['key'] = $ssh->key();
		}

		if ( '' !== $ssh->proxyjump() ) {
			$alias_body['proxyjump'] = $ssh->proxyjump();
		}

		$needs_alias = count( $alias_body ) > 1;

		if ( $needs_alias && $this->wp_binary_has_flags() ) {
			/*
			 * The alias form requires the alias to be WP-CLI's FIRST argument,
			 * and WP_CLI_SSH_BINARY is spliced in ahead of it. A binary of
			 * `php ~/wp-cli.phar` is fine — those are the interpreter. A binary
			 * carrying a wp flag like `wp --allow-root` is not: the flag takes
			 * argv[1] and the alias is then read as a command name, failing with
			 * "'@migrate-app-target' is not a registered wp command".
			 */
			throw new \RuntimeException(
				sprintf(
					"--wp-binary carries a WP-CLI flag (%s), which cannot be combined with --identity or --proxyjump.\n"
						. "WP-CLI needs the connection alias to be its first argument, and the flag takes that slot.\n"
						. "Either move the flag to an environment variable on the remote (WP_CLI_ALLOW_ROOT=1 for --allow-root),\n"
						. 'or drop --identity and put an IdentityFile for this host in your ~/.ssh/config.',
					$this->remote_wp
				)
			);
		}

		$command = $needs_alias
			? sprintf(
				'%s %s migrate_app %s %s',
				$this->local_wp(),
				escapeshellarg( $alias_name ),
				escapeshellarg( $package ),
				implode( ' ', $flags )
			)
			: sprintf(
				'%s --ssh=%s migrate_app %s %s',
				$this->local_wp(),
				escapeshellarg( $ssh->connection_string() ),
				escapeshellarg( $package ),
				implode( ' ', $flags )
			);

		$has_global = $ssh->exec( 'test -f "$HOME/.wp-cli/config.yml" && echo yes || echo no' );

		if ( 'yes' === trim( $has_global['out'] ) ) {
			$this->note(
				'The remote has its own ~/.wp-cli/config.yml. It is bypassed for the migration run only, because that is how the uploaded tool gets loaded. A project wp-cli.yml in the WordPress root is still read.'
			);
		}

		$command = sprintf(
			'WP_CLI_SSH_PRE_CMD=%s %s',
			escapeshellarg( 'export WP_CLI_CONFIG_PATH=' . $config_yml ),
			$command
		);

		if ( $needs_alias ) {
			$command = sprintf(
				'WP_CLI_RUNTIME_ALIAS=%s %s',
				escapeshellarg( (string) json_encode( array( $alias_name => $alias_body ) ) ),
				$command
			);
		}

		if ( 'wp' !== $this->remote_wp ) {
			$command = sprintf( 'WP_CLI_SSH_BINARY=%s %s', escapeshellarg( $this->remote_wp ), $command );
		}

		WP_CLI::log( WP_CLI::colorize( "\n%bRunning the migration on the remote...%n" ) );
		WP_CLI::debug( 'Handing off: ' . $command, 'migrate_app_remote' );

		$code = Ssh::stream( $command );

		if ( 0 !== $code ) {
			throw new \RuntimeException(
				sprintf( 'The migration failed on %s (exit %d). Its own output is above.', $ssh->host(), $code )
			);
		}

		$this->step( 'migrate', 'ok', $ssh->path() );

		if ( $this->local_backup ) {
			$this->note(
				sprintf(
					"The remote run reported \"--skip-backup ... no way back\". Ignore it — that is this command telling the far end not to dump the database twice.\nA verified backup was taken and pulled here before the import: %s",
					$this->local_backup
				)
			);
		}
	}

	/**
	 * Whether --wp-binary carries a WP-CLI flag rather than just an interpreter.
	 *
	 * `php ~/wp-cli.phar` is an interpreter plus a script. `wp --allow-root` is
	 * a binary plus a flag, and the flag would occupy the argument slot the
	 * connection alias needs.
	 *
	 * @return bool
	 */
	private function wp_binary_has_flags() {
		$words = array_values( array_filter( preg_split( '/\s+/', trim( $this->remote_wp ) ) ) );

		if ( ! $words ) {
			return false;
		}

		// Only TRAILING flags matter. Everything up to and including the last
		// non-flag word is the interpreter and the script — `php -d
		// memory_limit=512M /usr/local/bin/wp` is entirely fine. Anything after
		// it lands in WP-CLI's own argument list, where it would displace the
		// connection alias.
		$last_plain = -1;

		foreach ( $words as $i => $word ) {
			if ( '-' !== $word[0] ) {
				$last_plain = $i;
			}
		}

		for ( $i = $last_plain + 1; $i < count( $words ); $i++ ) {
			if ( '-' === $words[ $i ][0] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * How to launch a second local `wp`.
	 *
	 * @return string
	 */
	private function local_wp() {
		$script = isset( $GLOBALS['argv'][0] ) ? (string) $GLOBALS['argv'][0] : '';

		if ( '' === $script || ! is_file( $script ) ) {
			return 'wp';
		}

		return escapeshellarg( Utils\get_php_binary() ) . ' ' . escapeshellarg( $script );
	}

	/**
	 * Claim the remote so two migrations cannot run into one database.
	 *
	 * This matters more here than it would with a detached run, not less. The
	 * migration runs attached: if the connection drops, the remote `wp` may keep
	 * importing with nobody watching, and the operator's natural next move is to
	 * run the command again. Two concurrent imports into the same database is
	 * the failure that actually eats a site.
	 *
	 * The lock records who and when rather than a PID, because the process that
	 * matters lives on the far side and this side never learns its id.
	 *
	 * @param Ssh    $ssh        Transport.
	 * @param string $staging    Remote staging directory.
	 * @param array  $assoc_args Flags.
	 * @return void
	 */
	private function lock( $ssh, $staging, $assoc_args ) {
		if ( $this->dry_run ) {
			return;
		}

		$path    = $staging . '/.migrate-app.lock';
		$current = $ssh->exec( sprintf( 'cat %s 2>/dev/null', escapeshellarg( $path ) ) );
		$held    = trim( $current['out'] );

		if ( '' !== $held && ! Utils\get_flag_value( $assoc_args, 'force-unlock', false ) ) {
			$age = 0;
			if ( preg_match( '/started=(\d+)/', $held, $m ) ) {
				$age = time() - (int) $m[1];
			}

			$stale = $age > 6 * 3600;

			if ( ! $stale ) {
				throw new \RuntimeException(
					sprintf(
						"Another migration holds this remote:\n  %s\nIf the connection dropped, the far end may still be importing — check before you retry. Override with --force-unlock.",
						$held
					)
				);
			}

			$this->note( sprintf( 'Took over a stale lock (%d hours old): %s', (int) floor( $age / 3600 ), $held ) );
		}

		$stamp = sprintf(
			'host=%s user=%s started=%d at=%s',
			php_uname( 'n' ),
			get_current_user(),
			time(),
			gmdate( 'Y-m-d H:i:s' ) . ' UTC'
		);

		$ssh->exec_or_fail(
			sprintf( 'mkdir -p %s && printf %s > %s', escapeshellarg( $staging ), escapeshellarg( $stamp ), escapeshellarg( $path ) ),
			'Claiming the remote lock'
		);
	}

	/**
	 * Release the remote lock. Safe to call when none was taken.
	 *
	 * @param Ssh    $ssh     Transport.
	 * @param string $staging Remote staging directory.
	 * @return void
	 */
	private function unlock( $ssh, $staging ) {
		if ( $this->dry_run ) {
			return;
		}

		$ssh->exec( sprintf( 'rm -f %s', escapeshellarg( $staging . '/.migrate-app.lock' ) ) );
	}

	/**
	 * Remove the staging directory and the uploaded tool.
	 *
	 * @param Ssh    $ssh     Transport.
	 * @param string $staging Remote staging directory.
	 * @return void
	 */
	private function cleanup( $ssh, $staging ) {
		if ( $this->dry_run ) {
			$this->step( 'cleanup', 'dry-run', $staging );
			return;
		}

		// Never let a bad --staging turn into `rm -rf /`.
		if ( '' === trim( $staging, '/' ) || $staging === rtrim( $ssh->path(), '/' ) ) {
			$this->step( 'cleanup', 'WARN', 'refused — staging path looks unsafe' );
			return;
		}

		$ssh->exec( sprintf( 'rm -rf %s', escapeshellarg( $staging ) ) );
		$this->step( 'cleanup', 'ok', 'removed ' . $staging );
		$this->note( sprintf( 'The remote copy of the backup went with it. Yours is still at %s', (string) $this->local_backup ) );
	}

	/**
	 * Tell the operator how to undo, in both forms.
	 *
	 * @param Ssh $ssh Transport.
	 * @return void
	 */
	private function print_restore_hint( $ssh ) {
		if ( ! $this->local_backup ) {
			return;
		}

		WP_CLI::log( '' );
		WP_CLI::warning( 'The migration failed. Restore the remote database with either of these:' );

		if ( $this->remote_backup ) {
			WP_CLI::log( sprintf( '    wp --ssh=%s db import %s', $ssh->connection_string(), $this->remote_backup ) );
			WP_CLI::log( sprintf( "    ssh %s 'cd %s && mysql -u<user> -p <db> < %s'", $ssh->host_target(), $ssh->path(), $this->remote_backup ) );
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'A verified copy is also on this machine: ' . $this->local_backup );
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
