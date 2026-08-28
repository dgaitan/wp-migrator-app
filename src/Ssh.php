<?php
/**
 * Transport for `wp migrate_app_remote`.
 *
 * This class does three things and nothing else: resolve a target (a WP-CLI
 * alias or a connection string) into its parts, run a shell command on that
 * target, and move directories and files across.
 *
 * It deliberately does NOT run the migration. WP-CLI's own `--ssh` does that —
 * `Runner::run_ssh_command()` builds `ssh <flags> <host> 'cd <path>; wp <args>'`
 * and passthru's it, propagating the exit code. Re-implementing that would mean
 * owning a second, worse SSH layer. What WP-CLI has no answer for is getting
 * the package onto the far end in the first place, and that is what lives here.
 *
 * Two backends. `ssh` uses ssh/rsync and is the real one. `docker` uses
 * `docker exec`/`docker cp` and exists because WP-CLI's `--ssh` accepts a
 * `docker:` scheme, which lets the end-to-end test drive the entire remote path
 * against a container with no sshd anywhere in sight.
 *
 * @package MigrateApp
 */

namespace MigrateApp;

use WP_CLI;
use WP_CLI\Utils;

class Ssh {

	/**
	 * Parsed connection string: scheme, user, host, port, path.
	 *
	 * @var array<string,string>
	 */
	private $bits = array();

	/** @var string Identity file, or '' for none. */
	private $key = '';

	/** @var string ProxyJump spec, or '' for none. */
	private $proxyjump = '';

	/** @var string The target exactly as the operator typed it. */
	private $raw = '';

	/** @var string|null Alias name, when the target was `@something`. */
	private $alias = null;

	/** @var string|null ControlMaster socket path, set on first connection. */
	private $control_path = null;

	/**
	 * Use one of the named constructors.
	 */
	private function __construct() {}

	/**
	 * Resolve `--to=` into a transport.
	 *
	 * Accepts a WP-CLI alias (`@prod`) or a raw connection string
	 * (`[<scheme>:][<user>@]<host>[:<port>][<path>]`) — the same grammar
	 * `--ssh` itself accepts, parsed with WP-CLI's own parser so the two can
	 * never drift.
	 *
	 * @param string $target     Alias or connection string.
	 * @param array  $assoc_args Command flags.
	 * @return self
	 */
	public static function from_target( $target, $assoc_args ) {
		$self      = new self();
		$self->raw = (string) $target;

		$connection = $self->raw;

		if ( 0 === strpos( $self->raw, '@' ) ) {
			$connection = $self->resolve_alias( $self->raw );
		}

		if ( '' === trim( $connection ) ) {
			WP_CLI::error(
				"--to is empty. Give an alias or a connection string:\n"
				. "    --to=@prod\n"
				. '    --to=deploy@example.com:22/home/deploy/public_html'
			);
		}

		$self->bits = (array) Utils\parse_ssh_url( $connection );

		if ( empty( $self->bits['host'] ) ) {
			WP_CLI::error( sprintf( 'Could not read a host out of %s', $connection ) );
		}

		$override_path = (string) Utils\get_flag_value( $assoc_args, 'remote-path', '' );
		if ( '' !== $override_path ) {
			$self->bits['path'] = $override_path;
		}

		if ( empty( $self->bits['path'] ) ) {
			WP_CLI::error(
				"No remote WordPress path. Put it at the end of the connection string or pass --remote-path:\n"
				. "    --to=deploy@example.com:22/home/deploy/public_html\n"
				. '    --to=deploy@example.com --remote-path=/home/deploy/public_html'
			);
		}

		$self->bits['path'] = rtrim( $self->bits['path'], '/' );

		$key = (string) Utils\get_flag_value( $assoc_args, 'identity', $self->key );
		if ( '' !== $key ) {
			$self->key = $key;
		}

		$jump = (string) Utils\get_flag_value( $assoc_args, 'proxyjump', $self->proxyjump );
		if ( '' !== $jump ) {
			$self->proxyjump = $jump;
		}

		return $self;
	}

	/**
	 * Look an alias up in WP-CLI's own configuration.
	 *
	 * Group aliases (`@all: [@one, @two]`) are refused outright. Fanning a
	 * destructive migration out across several sites from one flag is not a
	 * feature anyone asked for.
	 *
	 * @param string $name Alias including the leading `@`.
	 * @return string Connection string.
	 */
	private function resolve_alias( $name ) {
		$aliases = WP_CLI::get_configurator()->get_aliases();

		if ( ! isset( $aliases[ $name ] ) ) {
			$known = array_keys( $aliases );
			WP_CLI::error(
				sprintf(
					"Unknown alias %s.\nDefine it in ~/.wp-cli/config.yml:\n\n    %s:\n      ssh: user@host:22/home/user/public_html\n\n%s",
					$name,
					$name,
					$known ? 'Aliases I can see: ' . implode( ', ', $known ) : 'I cannot see any aliases at all.'
				)
			);
		}

		$alias = $aliases[ $name ];

		if ( ! is_array( $alias ) || isset( $alias[0] ) ) {
			WP_CLI::error(
				sprintf(
					'%s is a group alias. Migrating to several sites from one command is refused — name a single site.',
					$name
				)
			);
		}

		if ( empty( $alias['ssh'] ) ) {
			WP_CLI::error(
				sprintf(
					"Alias %s has no `ssh:` key, so there is nothing to connect to.\nAdd one:\n\n    %s:\n      ssh: user@host:22/home/user/public_html",
					$name,
					$name
				)
			);
		}

		$this->alias = $name;

		if ( ! empty( $alias['key'] ) ) {
			$this->key = (string) $alias['key'];
		}

		if ( ! empty( $alias['proxyjump'] ) ) {
			$this->proxyjump = (string) $alias['proxyjump'];
		}

		$connection = (string) $alias['ssh'];

		// An alias may carry the WordPress path in `path:` rather than at the
		// end of `ssh:`. Only append when `ssh:` did not already supply one.
		if ( ! empty( $alias['path'] ) && ! preg_match( '#[:@][^/]*/#', $connection ) && false === strpos( $connection, '/' ) ) {
			$connection .= '/' . ltrim( (string) $alias['path'], '/' );
		}

		return $connection;
	}

	/**
	 * The scheme WP-CLI parsed, defaulting to ssh.
	 *
	 * @return string
	 */
	public function scheme() {
		return ! empty( $this->bits['scheme'] ) ? $this->bits['scheme'] : 'ssh';
	}

	/**
	 * Whether this target is a container rather than a host.
	 *
	 * @return bool
	 */
	public function is_docker() {
		return 'docker' === $this->scheme();
	}

	/**
	 * Host name, or container name under the docker scheme.
	 *
	 * @return string
	 */
	public function host() {
		return isset( $this->bits['host'] ) ? $this->bits['host'] : '';
	}

	/**
	 * Remote WordPress root.
	 *
	 * @return string
	 */
	public function path() {
		return isset( $this->bits['path'] ) ? $this->bits['path'] : '';
	}

	/**
	 * `user@host` with the port appended when there is one — for display and
	 * for handing to ssh/rsync.
	 *
	 * @return string
	 */
	public function host_target() {
		$host = $this->host();

		if ( ! empty( $this->bits['user'] ) ) {
			$host = $this->bits['user'] . '@' . $host;
		}

		return $host;
	}

	/**
	 * One line naming exactly what we are about to talk to.
	 *
	 * @return string
	 */
	public function label() {
		$label = $this->scheme() . ':' . $this->host_target();

		if ( ! empty( $this->bits['port'] ) ) {
			$label .= ':' . $this->bits['port'];
		}

		return $label . $this->path();
	}

	/**
	 * The alias the operator named, if any.
	 *
	 * @return string|null
	 */
	public function alias() {
		return $this->alias;
	}

	/**
	 * Rebuild a connection string to hand to WP-CLI's own `--ssh`.
	 *
	 * Deliberately reconstructed from the parsed parts rather than passed
	 * through verbatim, so that `--remote-path` and alias `path:` resolution
	 * apply to the migration run too.
	 *
	 * @return string
	 */
	public function connection_string() {
		$out = $this->scheme() . ':';

		if ( ! empty( $this->bits['user'] ) ) {
			$out .= $this->bits['user'] . '@';
		}

		$out .= $this->host();

		if ( ! empty( $this->bits['port'] ) ) {
			$out .= ':' . $this->bits['port'];
		}

		return $out . $this->path();
	}

	/**
	 * The identity file in play, or '' for none.
	 *
	 * @return string
	 */
	public function key() {
		return $this->key;
	}

	/**
	 * The ProxyJump spec in play, or '' for none.
	 *
	 * @return string
	 */
	public function proxyjump() {
		return $this->proxyjump;
	}

	/**
	 * ssh(1) flags shared by every connection we open.
	 *
	 * ControlMaster means the operator authenticates once, however many probes
	 * preflight runs. The socket name uses %C — a hash — because a plain
	 * %h/%p/%r path blows past the 104-character UNIX socket limit on long
	 * hostnames and fails in a way that reads like a network problem.
	 *
	 * BatchMode is NOT set: a passphrase-protected key with no agent should get
	 * a prompt, not a silent refusal.
	 *
	 * @return array<int,string>
	 */
	private function ssh_flags() {
		$flags = array( '-o', escapeshellarg( 'ConnectTimeout=15' ) );

		$control = $this->control_path();

		if ( '' !== $control ) {
			$flags[] = '-o';
			$flags[] = escapeshellarg( 'ControlMaster=auto' );
			$flags[] = '-o';
			$flags[] = escapeshellarg( 'ControlPath=' . $control );
			$flags[] = '-o';
			$flags[] = escapeshellarg( 'ControlPersist=120' );
		}

		if ( ! empty( $this->bits['port'] ) ) {
			$flags[] = '-p';
			$flags[] = (string) (int) $this->bits['port'];
		}

		if ( '' !== $this->key ) {
			$flags[] = '-i';
			$flags[] = escapeshellarg( $this->key );
		}

		if ( '' !== $this->proxyjump ) {
			$flags[] = '-J';
			$flags[] = escapeshellarg( $this->proxyjump );
		}

		/*
		 * Escape hatch for hosts that need something unusual — an old key type,
		 * a non-default known_hosts, a jump config ssh_config cannot express.
		 * Deliberately an env var and not a flag: it is for the rare host, not
		 * the common path, and it must never read like an invitation to turn
		 * StrictHostKeyChecking off.
		 */
		$extra = (string) getenv( 'MIGRATE_APP_SSH_OPTS' );

		if ( '' !== trim( $extra ) ) {
			$flags[] = trim( $extra );
		}

		return $flags;
	}

	/**
	 * Where to put the shared-connection socket, or '' to go without one.
	 *
	 * UNIX domain socket paths cap at 104 bytes, and OpenSSH appends a random
	 * `.XXXXXXXXXXXXXXXX` suffix while the master is being established — so the
	 * budget is really ~85. `%C` alone does not save you: it expands to 40 hex
	 * characters, and macOS puts $TMPDIR at
	 * /var/folders/xx/<32 chars>/T, which blows the limit before the hostname is
	 * even considered. That failure surfaces as
	 * "unix_listener: path ... too long", after which every connection dies with
	 * exit 255 and no explanation, which reads exactly like a network problem.
	 *
	 * So: a short deterministic name, /tmp when it is usable, and if the result
	 * still would not fit, no multiplexing at all. Authenticating twice is a
	 * papercut; not connecting is not.
	 *
	 * @return string
	 */
	private function control_path() {
		if ( null !== $this->control_path ) {
			return $this->control_path;
		}

		$dir = is_dir( '/tmp' ) && is_writable( '/tmp' ) ? '/tmp' : sys_get_temp_dir();

		$token = substr(
			md5( $this->host_target() . '|' . ( isset( $this->bits['port'] ) ? $this->bits['port'] : '' ) . '|' . $this->key ),
			0,
			12
		);

		$path = Fs::join( $dir, '.mgapp-' . $token );

		// 17 for OpenSSH's ".XXXXXXXXXXXXXXXX", plus a little headroom.
		$this->control_path = ( strlen( $path ) + 20 ) < 104 ? $path : '';

		return $this->control_path;
	}

	/**
	 * The `ssh ...` prefix, without a command.
	 *
	 * @return string
	 */
	private function ssh_prefix() {
		return 'ssh -T -q ' . implode( ' ', $this->ssh_flags() );
	}

	/**
	 * Run a shell command on the target and capture its output.
	 *
	 * @param string $command Shell command, run by the remote login shell.
	 * @return array{code:int,out:string,err:string}
	 */
	public function exec( $command ) {
		if ( $this->is_docker() ) {
			$full = sprintf(
				'docker exec %s sh -c %s',
				escapeshellarg( $this->host() ),
				escapeshellarg( $command )
			);
		} else {
			$full = sprintf(
				'%s %s %s',
				$this->ssh_prefix(),
				escapeshellarg( $this->host_target() ),
				escapeshellarg( $command )
			);
		}

		return self::capture( $full );
	}

	/**
	 * Run a command on the target and abort with a useful message on failure.
	 *
	 * @param string $command Shell command.
	 * @param string $context What we were trying to do, for the error message.
	 * @return string Trimmed stdout.
	 */
	public function exec_or_fail( $command, $context ) {
		$result = $this->exec( $command );

		if ( 0 !== $result['code'] ) {
			if ( 255 === $result['code'] ) {
				WP_CLI::error(
					sprintf(
						"Cannot connect to %s.\nTry `ssh %s` on its own first — an unknown host key or a missing agent identity both look like this.",
						$this->host_target(),
						$this->host_target()
					)
				);
			}

			WP_CLI::error(
				sprintf(
					"%s failed on %s (exit %d).\n%s",
					$context,
					$this->host_target(),
					$result['code'],
					'' !== $result['err'] ? $result['err'] : $result['out']
				)
			);
		}

		return trim( $result['out'] );
	}

	/**
	 * Copy a local directory's CONTENTS into a remote directory, additively.
	 *
	 * Note the flag set. Not `-a`: on macOS that drags resource forks,
	 * `.DS_Store` and local uid/gid onto a Linux host. `--partial` is what makes
	 * a dropped 12 GB upload resumable rather than a restart.
	 *
	 * @param string $local   Local directory.
	 * @param string $remote  Remote directory. Created if absent.
	 * @param bool   $dry_run Report only.
	 * @return array{method:string,bytes:int}
	 */
	public function push_dir( $local, $remote, $dry_run = false ) {
		$local  = rtrim( $local, '/' );
		$remote = rtrim( $remote, '/' );

		if ( $dry_run ) {
			return array(
				'method' => 'dry-run',
				'bytes'  => self::dir_bytes( $local ),
			);
		}

		$this->exec_or_fail( sprintf( 'mkdir -p %s', escapeshellarg( $remote ) ), 'Creating the staging directory' );

		if ( $this->is_docker() ) {
			$cmd = sprintf(
				'docker cp %s %s',
				escapeshellarg( $local . '/.' ),
				escapeshellarg( $this->host() . ':' . $remote )
			);

			$result = self::stream( $cmd );

			if ( 0 !== $result ) {
				WP_CLI::error( sprintf( 'docker cp failed (exit %d) copying %s', $result, $local ) );
			}

			return array(
				'method' => 'docker cp',
				'bytes'  => self::dir_bytes( $local ),
			);
		}

		if ( self::have( 'rsync' ) && $this->remote_has( 'rsync' ) ) {
			/*
			 * --stats, not --info=stats1. macOS ships openrsync now, which
			 * advertises rsync 2.6.9 compatibility and rejects `--info=` outright
			 * with a usage dump — and the operator's machine is exactly where
			 * this runs. --stats gives the same "Total transferred file size"
			 * line on both, which is what makes a re-push verifiable.
			 *
			 * Not -a either: on macOS that carries resource forks, .DS_Store and
			 * local uid/gid onto a Linux host.
			 */
			$cmd = sprintf(
				'rsync -rlptDz --partial --stats --no-owner --no-group %s -e %s %s/ %s',
				implode( ' ', self::rsync_excludes() ),
				escapeshellarg( $this->ssh_prefix() ),
				escapeshellarg( $local ),
				escapeshellarg( $this->host_target() . ':' . $remote . '/' )
			);

			$result = self::stream( $cmd );

			if ( 0 !== $result ) {
				WP_CLI::error(
					sprintf(
						"rsync failed (exit %d).\nExit 23 or 24 usually means a permission problem on the far end; exit 12 a protocol mismatch.",
						$result
					)
				);
			}

			return array(
				'method' => 'rsync',
				'bytes'  => self::dir_bytes( $local ),
			);
		}

		// No rsync at one end or the other. tar over the pipe still works, but
		// it is NOT resumable — a drop means starting over. Say so out loud
		// rather than letting the operator discover it at 90%.
		WP_CLI::warning(
			'rsync is not available on both ends. Falling back to a tar stream, which cannot resume if the connection drops.'
		);

		$cmd = sprintf(
			'tar -C %s -czf - . | %s %s %s',
			escapeshellarg( $local ),
			$this->ssh_prefix(),
			escapeshellarg( $this->host_target() ),
			escapeshellarg( sprintf( 'mkdir -p %s && tar -C %s -xzf -', escapeshellarg( $remote ), escapeshellarg( $remote ) ) )
		);

		$result = self::stream( $cmd );

		if ( 0 !== $result ) {
			WP_CLI::error( sprintf( 'tar transfer failed (exit %d)', $result ) );
		}

		return array(
			'method' => 'tar',
			'bytes'  => self::dir_bytes( $local ),
		);
	}

	/**
	 * Bring a single remote file home.
	 *
	 * @param string $remote Remote file path.
	 * @param string $local  Local destination path.
	 * @return bool
	 */
	public function pull_file( $remote, $local ) {
		if ( $this->is_docker() ) {
			$cmd = sprintf(
				'docker cp %s %s',
				escapeshellarg( $this->host() . ':' . $remote ),
				escapeshellarg( $local )
			);
		} elseif ( self::have( 'rsync' ) && $this->remote_has( 'rsync' ) ) {
			$cmd = sprintf(
				'rsync -z --partial -e %s %s %s',
				escapeshellarg( $this->ssh_prefix() ),
				escapeshellarg( $this->host_target() . ':' . $remote ),
				escapeshellarg( $local )
			);
		} else {
			$cmd = sprintf(
				'%s %s %s > %s',
				$this->ssh_prefix(),
				escapeshellarg( $this->host_target() ),
				escapeshellarg( sprintf( 'cat %s', escapeshellarg( $remote ) ) ),
				escapeshellarg( $local )
			);
		}

		$result = self::stream( $cmd );

		return 0 === $result && is_file( $local ) && filesize( $local ) > 0;
	}

	/**
	 * Copy a remote directory's CONTENTS into a local directory. The mirror of
	 * `push_dir()`, arrows reversed, same three backends and the same flag set.
	 *
	 * Deliberately the same code shape rather than a clever shared helper: the
	 * two directions have different failure modes worth reading separately, and
	 * the flag set is the part that took three attempts to get right on macOS.
	 *
	 * @param string $remote  Remote directory.
	 * @param string $local   Local directory. Created if absent.
	 * @param array  $exclude Extra exclude patterns on top of the standard set.
	 * @param bool   $dry_run Report only.
	 * @return array{method:string,bytes:int}
	 */
	public function pull_dir( $remote, $local, $exclude = array(), $dry_run = false ) {
		$remote = rtrim( $remote, '/' );
		$local  = rtrim( $local, '/' );

		if ( $dry_run ) {
			return array(
				'method' => 'dry-run',
				'bytes'  => $this->remote_dir_bytes( $remote ),
			);
		}

		if ( ! is_dir( $local ) && ! mkdir( $local, 0755, true ) && ! is_dir( $local ) ) {
			WP_CLI::error( sprintf( 'Could not create %s', $local ) );
		}

		if ( $this->is_docker() ) {
			$cmd = sprintf(
				'docker cp %s %s',
				escapeshellarg( $this->host() . ':' . $remote . '/.' ),
				escapeshellarg( $local )
			);

			$result = self::stream( $cmd );

			if ( 0 !== $result ) {
				WP_CLI::error( sprintf( 'docker cp failed (exit %d) copying %s', $result, $remote ) );
			}

			return array(
				'method' => 'docker cp',
				'bytes'  => self::dir_bytes( $local ),
			);
		}

		if ( self::have( 'rsync' ) && $this->remote_has( 'rsync' ) ) {
			$cmd = sprintf(
				'rsync -rlptDz --partial --stats --no-owner --no-group %s -e %s %s/ %s/',
				implode( ' ', self::rsync_excludes( $exclude ) ),
				escapeshellarg( $this->ssh_prefix() ),
				escapeshellarg( $this->host_target() . ':' . $remote ),
				escapeshellarg( $local )
			);

			$result = self::stream( $cmd );

			if ( 0 !== $result ) {
				WP_CLI::error(
					sprintf(
						"rsync failed (exit %d) pulling %s.\nExit 23 or 24 usually means a permission problem on the far end — a directory the SSH user cannot read.",
						$result,
						$remote
					)
				);
			}

			return array(
				'method' => 'rsync',
				'bytes'  => self::dir_bytes( $local ),
			);
		}

		WP_CLI::warning(
			'rsync is not available on both ends. Falling back to a tar stream, which cannot resume if the connection drops.'
		);

		$tar_excludes = array();
		foreach ( array_merge( array( '.DS_Store', '.git' ), (array) $exclude ) as $pattern ) {
			$tar_excludes[] = '--exclude=' . escapeshellarg( $pattern );
		}

		$cmd = sprintf(
			'%s %s %s | tar -C %s -xzf -',
			$this->ssh_prefix(),
			escapeshellarg( $this->host_target() ),
			escapeshellarg( sprintf( 'tar -C %s %s -czf - .', escapeshellarg( $remote ), implode( ' ', $tar_excludes ) ) ),
			escapeshellarg( $local )
		);

		$result = self::stream( $cmd );

		if ( 0 !== $result ) {
			WP_CLI::error( sprintf( 'tar transfer failed (exit %d)', $result ) );
		}

		return array(
			'method' => 'tar',
			'bytes'  => self::dir_bytes( $local ),
		);
	}

	/**
	 * Size of a remote directory, in bytes.
	 *
	 * `du -sk`, not `du -sb`: the latter is GNU-only and this runs against
	 * whatever the host happens to ship.
	 *
	 * @param string $remote Remote directory.
	 * @return int Bytes, or 0 if the directory is absent or unreadable.
	 */
	public function remote_dir_bytes( $remote ) {
		$result = $this->exec( sprintf( 'du -sk %s 2>/dev/null', escapeshellarg( $remote ) ) );

		if ( 0 !== $result['code'] || ! preg_match( '/^\s*(\d+)/', $result['out'], $m ) ) {
			return 0;
		}

		return (int) $m[1] * 1024;
	}

	/**
	 * Run a command on the target and write its stdout straight into a local
	 * file, without staging anything on the far end.
	 *
	 * The escape hatch for origins with no writable temp directory. Nothing
	 * about it is resumable — the caller is responsible for proving the result
	 * arrived whole, because a severed pipe here still exits 0.
	 *
	 * @param string $command Remote command.
	 * @param string $local   Local destination file.
	 * @return int Exit code.
	 */
	public function pull_command_output( $command, $local ) {
		if ( $this->is_docker() ) {
			$cmd = sprintf(
				'docker exec %s sh -c %s > %s',
				escapeshellarg( $this->host() ),
				escapeshellarg( $command ),
				escapeshellarg( $local )
			);
		} else {
			$cmd = sprintf(
				'%s %s %s > %s',
				$this->ssh_prefix(),
				escapeshellarg( $this->host_target() ),
				escapeshellarg( $command ),
				escapeshellarg( $local )
			);
		}

		return self::stream( $cmd );
	}

	/**
	 * Byte size of a remote file, or 0 if it is not there.
	 *
	 * Used to prove a pulled dump arrived whole. `wc -c` rather than `stat`,
	 * whose flags differ between GNU and BSD userlands.
	 *
	 * @param string $remote Remote file path.
	 * @return int
	 */
	public function remote_file_bytes( $remote ) {
		$result = $this->exec( sprintf( 'wc -c < %s 2>/dev/null', escapeshellarg( $remote ) ) );

		if ( 0 !== $result['code'] || ! preg_match( '/(\d+)/', $result['out'], $m ) ) {
			return 0;
		}

		return (int) $m[1];
	}

	/**
	 * Whether a binary exists on the target. Cached — preflight asks twice.
	 *
	 * @param string $binary Binary name.
	 * @return bool
	 */
	public function remote_has( $binary ) {
		static $cache = array();

		$key = $this->host() . '|' . $binary;

		if ( ! isset( $cache[ $key ] ) ) {
			$result        = $this->exec( sprintf( 'command -v %s >/dev/null 2>&1 && echo yes || echo no', escapeshellarg( $binary ) ) );
			$cache[ $key ] = ( 'yes' === trim( $result['out'] ) );
		}

		return $cache[ $key ];
	}

	/**
	 * Close the shared connection so no socket outlives the command.
	 *
	 * @return void
	 */
	public function disconnect() {
		if ( $this->is_docker() || '' === (string) $this->control_path() ) {
			return;
		}

		self::capture(
			sprintf(
				'ssh -O exit -o %s %s 2>/dev/null',
				escapeshellarg( 'ControlPath=' . $this->control_path() ),
				escapeshellarg( $this->host_target() )
			)
		);
	}

	/**
	 * Exclusions that should never cross a machine boundary.
	 *
	 * @return array<int,string>
	 */
	private static function rsync_excludes( $extra = array() ) {
		$out = array();

		$patterns = array_merge(
			array( '.DS_Store', '._*', '.Spotlight-V100', '.Trashes', 'Thumbs.db', '.git' ),
			(array) $extra
		);

		foreach ( array_unique( $patterns ) as $pattern ) {
			$out[] = '--exclude=' . escapeshellarg( $pattern );
		}

		return $out;
	}

	/**
	 * What a pull should never drag home.
	 *
	 * Both categories were learned rather than imagined. Backup plugins park
	 * multi-gigabyte archives inside `wp-content`, and those archives routinely
	 * hold a full database dump of their own — pulling them copies the site's
	 * database a second time, in a form nothing downstream will ever read.
	 * Caches are regenerable by definition; carrying them costs transfer and
	 * buys nothing.
	 *
	 * `fiction-drafts-*` is here for the relocated case only. By default that
	 * plugin writes to `WP_CONTENT_DIR/fiction-drafts-<32 hex>`, a sibling of
	 * themes, plugins and uploads, which a pull never walks — but
	 * `FICTION_DRAFTS_STORAGE_DIR` can point it anywhere, including inside
	 * uploads, and its archives are full site backups.
	 *
	 * `wp-config.php` is NOT in this list. It is excluded unconditionally at the
	 * command layer, because it is not a size problem — it is credentials and
	 * salts, and an exclusion the operator can switch off would be a footgun.
	 *
	 * @return array<int,string>
	 */
	public static function content_noise() {
		return array(
			'updraft',
			'backwpup-*',
			'ai1wm-backups',
			'wpvividbackups',
			'backup-*',
			'*.wpress',
			'fiction-drafts-*',
			'cache',
			'et-cache',
			'w3tc-config',
			'wp-rocket-config',
			'debug.log',
			'node_modules',
		);
	}

	/**
	 * Whether a binary exists locally.
	 *
	 * @param string $binary Binary name.
	 * @return bool
	 */
	public static function have( $binary ) {
		$result = self::capture( sprintf( 'command -v %s', escapeshellarg( $binary ) ) );

		return 0 === $result['code'] && '' !== trim( $result['out'] );
	}

	/**
	 * Size of a local directory in bytes.
	 *
	 * `du -sb` is GNU-only and the operator is on a laptop, so this uses
	 * `du -sk` — POSIX — and multiplies.
	 *
	 * @param string $path Directory.
	 * @return int
	 */
	public static function dir_bytes( $path ) {
		$result = self::capture( sprintf( 'du -sk %s', escapeshellarg( $path ) ) );

		if ( 0 !== $result['code'] || ! preg_match( '/^\s*(\d+)/', $result['out'], $m ) ) {
			return 0;
		}

		return (int) $m[1] * 1024;
	}

	/**
	 * Run a local command, capturing stdout and stderr separately.
	 *
	 * @param string $command Shell command.
	 * @return array{code:int,out:string,err:string}
	 */
	public static function capture( $command ) {
		$descriptors = array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$pipes = array();
		$proc  = proc_open( $command, $descriptors, $pipes );

		if ( ! is_resource( $proc ) ) {
			return array(
				'code' => 127,
				'out'  => '',
				'err'  => 'Could not start: ' . $command,
			);
		}

		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'code' => (int) proc_close( $proc ),
			'out'  => $out,
			'err'  => $err,
		);
	}

	/**
	 * Run a local command with its output going straight to the terminal.
	 *
	 * Transfers can take an hour. The operator needs to see progress as it
	 * happens, not a summary afterwards.
	 *
	 * @param string $command Shell command.
	 * @return int Exit code.
	 */
	public static function stream( $command ) {
		$code = 0;

		passthru( $command, $code );

		return (int) $code;
	}
}
