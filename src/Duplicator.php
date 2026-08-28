<?php
/**
 * Reads what a package already knows about itself.
 *
 * Two formats, one internal shape.
 *
 * A **Duplicator Lite** package ships `dup-installer/dup-archive__<hash>.txt`,
 * which despite the extension is JSON, and carries the origin domain, the source
 * table prefix, the admin users and the WP/PHP/DB versions.
 *
 * A **Fiction Drafts** export (github.com/dgaitan/Fiction-Drafts) ships
 * `manifest.json` at the archive root beside `database.sql`, carrying `home_url`,
 * `table_prefix`, `multisite`, `active_theme` and the WP/PHP/MySQL versions.
 * Fiction Drafts is deliberately export-only — its README states it will never
 * restore, migrate or rewrite URLs — which makes it the exact other half of this
 * tool.
 *
 * Everything `migration.yaml` needs is in either manifest, so `--generate-config`
 * writes the config instead of asking the operator to transcribe it. The class
 * keeps the Duplicator name because every accessor below is written against
 * Duplicator's key shape; a Fiction Drafts manifest is normalised into that shape
 * at load time rather than forking fifteen call sites.
 *
 * @package MigrateApp
 */

namespace MigrateApp;

class Duplicator {

	/** @var string Absolute path to the source folder. */
	private $folder;

	/** @var array|null Decoded manifest, normalised to Duplicator's key shape. */
	private $manifest = null;

	/** @var string|null Path to the manifest file. */
	private $manifest_path = null;

	/** @var string Format the manifest came from: 'duplicator', 'fiction-drafts', or ''. */
	private $format = '';

	/** @var array|null A Fiction Drafts manifest exactly as written, when that is the format. */
	private $fd = null;

	/**
	 * @param string $folder Absolute path to the extracted package folder.
	 */
	public function __construct( $folder ) {
		$this->folder = rtrim( $folder, '/' );
		$this->load_manifest();
	}

	/**
	 * Locate and decode the dup-archive manifest.
	 *
	 * @return void
	 */
	private function load_manifest() {
		$candidates = array_merge(
			(array) glob( $this->folder . '/dup-installer/dup-archive__*.txt' ),
			(array) glob( $this->folder . '/dup-archive__*.txt' )
		);

		foreach ( array_filter( $candidates ) as $path ) {
			$decoded = json_decode( (string) file_get_contents( $path ), true );
			if ( is_array( $decoded ) ) {
				$this->manifest      = $decoded;
				$this->manifest_path = $path;
				$this->format        = 'duplicator';
				return;
			}
		}

		$this->load_fiction_drafts_manifest();
	}

	/**
	 * Recognise a Fiction Drafts export and normalise its manifest.
	 *
	 * Detection is structural rather than by name: the format's `schema` key is
	 * the integer 1, which identifies nothing on its own, so the fingerprint is
	 * the combination of keys no other manifest carries together.
	 *
	 * The normalisation deliberately does NOT populate `home` under a subsite.
	 * Duplicator records the origin's absolute filesystem path there and
	 * `source_abspath()` returns it; Fiction Drafts records no such path, and a
	 * URL in that slot would make the stale-path check compare a URL against a
	 * directory. Absent is correct — the caller already handles null.
	 *
	 * `active_theme` is likewise not used to override theme detection. Fiction
	 * Drafts records the stylesheet only, while scanning the dump recovers both
	 * template and stylesheet — so a child theme keeps its parent.
	 *
	 * @return void
	 */
	private function load_fiction_drafts_manifest() {
		$path = $this->folder . '/manifest.json';

		if ( ! is_file( $path ) ) {
			return;
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true );

		if ( ! is_array( $decoded ) ) {
			return;
		}

		$required = array( 'schema', 'table_prefix', 'home_url' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $decoded ) ) {
				return;
			}
		}

		if ( ! isset( $decoded['profile_areas'] ) && ! isset( $decoded['volumes'] ) ) {
			return;
		}

		// parse_url(), not wp_parse_url(): this class is loaded standalone by
		// tests/probe.php and must stay free of WordPress.
		$home   = (string) $decoded['home_url'];
		$domain = (string) parse_url( $home, PHP_URL_HOST );

		$normalised = array(
			'wp_tableprefix' => (string) $decoded['table_prefix'],
			'mu_mode'        => ! empty( $decoded['multisite'] ),
			'blogname'       => '' !== $domain ? $domain : $home,
		);

		$versions = array(
			'version_wp'  => 'wp_version',
			'version_php' => 'php_version',
			'version_db'  => 'mysql_version',
		);
		foreach ( $versions as $ours => $theirs ) {
			if ( ! empty( $decoded[ $theirs ] ) ) {
				$normalised[ $ours ] = (string) $decoded[ $theirs ];
			}
		}

		if ( '' !== $domain ) {
			$normalised['subsites'] = array( array( 'domain' => $domain ) );
		}

		$this->manifest      = $normalised;
		$this->manifest_path = $path;
		$this->format        = 'fiction-drafts';
		$this->fd            = $decoded;
	}

	/**
	 * Was a manifest found, in either format?
	 *
	 * @return bool
	 */
	public function has_manifest() {
		return null !== $this->manifest;
	}

	/**
	 * Which format the manifest came from.
	 *
	 * @return string 'duplicator', 'fiction-drafts', or '' when there is none.
	 */
	public function format() {
		return $this->format;
	}

	/**
	 * A human label for the format, for messages the operator reads.
	 *
	 * @return string
	 */
	public function format_label() {
		$labels = array(
			'duplicator'     => 'Duplicator',
			'fiction-drafts' => 'Fiction Drafts',
		);

		return isset( $labels[ $this->format ] ) ? $labels[ $this->format ] : '';
	}

	/**
	 * The origin's home URL, when the manifest states it outright.
	 *
	 * Only Fiction Drafts records this; Duplicator carries a bare domain, which
	 * is why `detect_origin_url()` prefers the dump and infers a scheme.
	 *
	 * @return string|null
	 */
	public function manifest_home_url() {
		return ( null !== $this->fd && ! empty( $this->fd['home_url'] ) )
			? rtrim( (string) $this->fd['home_url'], '/' )
			: null;
	}

	/**
	 * Whether the package is known to contain `wp-config.php`.
	 *
	 * Fiction Drafts excludes it by default and records the per-job choice. True
	 * means the folder holds the origin's database password and all eight
	 * authentication salts, which is worth saying out loud before a migration.
	 *
	 * @return bool|null Null when the manifest does not say.
	 */
	public function includes_wp_config() {
		if ( null === $this->fd || ! array_key_exists( 'includes_wp_config', $this->fd ) ) {
			return null;
		}

		return (bool) $this->fd['includes_wp_config'];
	}

	/**
	 * What the export was asked to contain, when the manifest records it.
	 *
	 * A `database_only` or `files_no_media` export is a perfectly valid backup
	 * and a partial migration source; the operator should be told which they
	 * have before it is merged into a live site.
	 *
	 * @return array<string,bool> Empty when the manifest does not say.
	 */
	public function profile_areas() {
		if ( null === $this->fd || ! isset( $this->fd['profile_areas'] ) || ! is_array( $this->fd['profile_areas'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $this->fd['profile_areas'] as $area => $included ) {
			$out[ (string) $area ] = (bool) $included;
		}

		return $out;
	}

	/**
	 * @return string|null
	 */
	public function manifest_path() {
		return $this->manifest_path;
	}

	/**
	 * Locate the package SQL dump.
	 *
	 * @return string|null Absolute path, or null when not found.
	 */
	public function find_database() {
		$candidates = array_merge(
			(array) glob( $this->folder . '/dup-installer/dup-database__*.sql' ),
			(array) glob( $this->folder . '/dup-installer/*.sql' ),
			(array) glob( $this->folder . '/*.sql' )
		);

		foreach ( array_filter( $candidates ) as $path ) {
			if ( is_file( $path ) && filesize( $path ) > 0 ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * The origin site URL.
	 *
	 * Preference order: the `siteurl` row inside the dump (authoritative, and
	 * carries the scheme) -> the manifest's subsite domain, scheme inferred from
	 * the dump -> null.
	 *
	 * @param string|null $sql_path Dump path, when already known.
	 * @return string|null
	 */
	public function detect_origin_url( $sql_path = null ) {
		$sql_path = $sql_path ? $sql_path : $this->find_database();
		$prefix   = $this->detect_prefix( $sql_path );

		if ( $sql_path && is_readable( $sql_path ) ) {
			$from_dump = $this->scan_dump_for_option( $sql_path, $prefix, 'siteurl' );
			if ( $from_dump ) {
				return rtrim( $from_dump, '/' );
			}
		}

		$domain = $this->manifest_domain();
		if ( $domain ) {
			$scheme = 'https';
			if ( $sql_path && is_readable( $sql_path ) ) {
				$head = (string) file_get_contents( $sql_path, false, null, 0, 2 * 1024 * 1024 );
				if ( false !== strpos( $head, 'http://' . $domain ) && false === strpos( $head, 'https://' . $domain ) ) {
					$scheme = 'http';
				}
			}
			return $scheme . '://' . $domain;
		}

		return null;
	}

	/**
	 * The domain recorded in the manifest.
	 *
	 * @return string|null
	 */
	public function manifest_domain() {
		if ( ! $this->has_manifest() ) {
			return null;
		}
		if ( ! empty( $this->manifest['subsites'][0]['domain'] ) ) {
			return (string) $this->manifest['subsites'][0]['domain'];
		}
		return null;
	}

	/**
	 * Source table prefix.
	 *
	 * Manifest first (`wp_tableprefix`), then the first CREATE TABLE in the dump.
	 *
	 * @param string|null $sql_path Dump path, when already known.
	 * @return string|null
	 */
	public function detect_prefix( $sql_path = null ) {
		if ( $this->has_manifest() && ! empty( $this->manifest['wp_tableprefix'] ) ) {
			return (string) $this->manifest['wp_tableprefix'];
		}

		$sql_path = $sql_path ? $sql_path : $this->find_database();
		if ( ! $sql_path || ! is_readable( $sql_path ) ) {
			return null;
		}

		$handle = fopen( $sql_path, 'r' );
		if ( ! $handle ) {
			return null;
		}

		$prefix = null;
		$lines  = 0;
		while ( ( $line = fgets( $handle ) ) !== false && $lines < 5000 ) {
			$lines++;
			if ( preg_match( '/^\s*CREATE\s+TABLE\s+`([^`]+)`/i', $line, $m ) ) {
				// Every core WP install has an `options` table; derive the prefix from it
				// when we see it, otherwise fall back to the first table's leading token.
				if ( preg_match( '/^(.*)(options|posts|users)$/', $m[1], $p ) && '' !== $p[1] ) {
					$prefix = $p[1];
					break;
				}
				if ( null === $prefix && preg_match( '/^([a-zA-Z0-9]+_)/', $m[1], $p ) ) {
					$prefix = $p[1];
				}
			}
		}
		fclose( $handle );

		return $prefix;
	}

	/**
	 * The collation declared by the dump, so preflight can ask the destination
	 * server whether it knows it before anything is dropped.
	 *
	 * @param string|null $sql_path Dump path.
	 * @return string|null
	 */
	public function detect_collation( $sql_path = null ) {
		$sql_path = $sql_path ? $sql_path : $this->find_database();
		if ( ! $sql_path || ! is_readable( $sql_path ) ) {
			return null;
		}
		$head = (string) file_get_contents( $sql_path, false, null, 0, 512 * 1024 );
		if ( preg_match( '/COLLATE=([A-Za-z0-9_]+)/', $head, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Admin logins recorded in the manifest.
	 *
	 * After the import the destination's own user table is gone, so the operator
	 * needs to be told whose credentials now open the site.
	 *
	 * @return array<int,string>
	 */
	public function admin_users() {
		$users = array();

		if ( ! $this->has_manifest() ) {
			return $users;
		}

		if ( ! empty( $this->manifest['subsites'][0]['adminUsers'] ) && is_array( $this->manifest['subsites'][0]['adminUsers'] ) ) {
			foreach ( $this->manifest['subsites'][0]['adminUsers'] as $u ) {
				if ( ! empty( $u['user_login'] ) ) {
					$users[] = (string) $u['user_login'];
				}
			}
		}

		if ( ! $users && ! empty( $this->manifest['mu_siteadmins'] ) && is_array( $this->manifest['mu_siteadmins'] ) ) {
			foreach ( $this->manifest['mu_siteadmins'] as $u ) {
				$users[] = (string) $u;
			}
		}

		return array_values( array_unique( $users ) );
	}

	/**
	 * The active theme and its parent, read out of the dump.
	 *
	 * The reference package runs `recap-child` on top of `recap`; a migration
	 * that brings only the stylesheet leaves the site unstyled.
	 *
	 * @param string|null $sql_path Dump path.
	 * @return array{template:?string,stylesheet:?string}
	 */
	public function detect_themes( $sql_path = null ) {
		$sql_path = $sql_path ? $sql_path : $this->find_database();
		$prefix   = $this->detect_prefix( $sql_path );

		return array(
			'template'   => $sql_path ? $this->scan_dump_for_option( $sql_path, $prefix, 'template' ) : null,
			'stylesheet' => $sql_path ? $this->scan_dump_for_option( $sql_path, $prefix, 'stylesheet' ) : null,
		);
	}

	/**
	 * The origin server's absolute WordPress path, as recorded at package time.
	 *
	 * Cache plugins, some page builders and `upload_path` bake the filesystem
	 * path into the options table. A URL replacement never touches those, so the
	 * operator has to be told the string is still in there.
	 *
	 * @return string|null
	 */
	public function source_abspath() {
		if ( ! $this->has_manifest() ) {
			return null;
		}

		foreach ( array( $this->manifest, isset( $this->manifest['subsites'][0] ) ? $this->manifest['subsites'][0] : array() ) as $scope ) {
			if ( ! empty( $scope['home'] ) && is_string( $scope['home'] ) && '/' === $scope['home'][0] ) {
				return rtrim( $scope['home'], '/' );
			}
		}

		return null;
	}

	/**
	 * Blog name from the manifest, for the confirmation prompt.
	 *
	 * @return string|null
	 */
	public function blogname() {
		return $this->has_manifest() && ! empty( $this->manifest['blogname'] )
			? (string) $this->manifest['blogname']
			: null;
	}

	/**
	 * Is this a multisite package? migrate_app refuses those rather than
	 * half-migrating them.
	 *
	 * @return bool
	 */
	public function is_multisite() {
		return $this->has_manifest() && ! empty( $this->manifest['mu_mode'] );
	}

	/**
	 * Versions recorded at package time, shown in the preflight report.
	 *
	 * @return array<string,string>
	 */
	public function versions() {
		$keys = array( 'version_dup', 'version_wp', 'version_php', 'version_db' );
		$out  = array();
		foreach ( $keys as $k ) {
			if ( $this->has_manifest() && ! empty( $this->manifest[ $k ] ) ) {
				$out[ str_replace( 'version_', '', $k ) ] = (string) $this->manifest[ $k ];
			}
		}
		return $out;
	}

	/**
	 * Pull a single option value out of the dump without loading it into memory.
	 *
	 * Duplicator writes `INSERT INTO `wp_options` VALUES("2", "siteurl", "https://...", "on");`
	 * one row per line, with double-quoted values.
	 *
	 * @param string      $sql_path Dump path.
	 * @param string|null $prefix   Source table prefix.
	 * @param string      $option   Option name.
	 * @return string|null
	 */
	private function scan_dump_for_option( $sql_path, $prefix, $option ) {
		$handle = fopen( $sql_path, 'r' );
		if ( ! $handle ) {
			return null;
		}

		$table  = ( $prefix ? $prefix : '' ) . 'options';
		$needle = 'INSERT INTO `' . $table . '`';

		/*
		 * Match the option wherever it sits in the statement, in either quote
		 * style. Both halves of that mattered:
		 *
		 * The old pattern was anchored to `VALUES (` and so only ever saw the
		 * FIRST tuple. Duplicator writes one row per INSERT, so it never showed
		 * — but mysqldump and Fiction Drafts both write extended inserts with
		 * hundreds of rows per statement, where every option but the first was
		 * invisible.
		 *
		 * It also hardcoded double quotes, which Duplicator emits and mysqldump
		 * does not. Same blind spot the prefix rewriter had.
		 *
		 * Group 1 and 2 are the quote characters, backreferenced so an opening
		 * `'` cannot be closed by a `"`; group 3 is the value, which may contain
		 * escaped quotes and the other quote character unescaped.
		 */
		$pattern = '/([\'"])' . preg_quote( $option, '/' ) . '\1\s*,\s*([\'"])((?:\\\\.|(?!\2)[^\\\\])*)\2\s*[,)]/i';

		$found = null;
		while ( ( $line = fgets( $handle ) ) !== false ) {
			if ( false === strpos( $line, $needle ) ) {
				continue;
			}
			if ( preg_match( $pattern, $line, $m ) ) {
				$found = stripslashes( $m[3] );
				break;
			}
		}

		fclose( $handle );
		return $found;
	}
}
