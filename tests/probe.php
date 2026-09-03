<?php
/**
 * Unit probe harness for migrate_app.
 *
 * Exercises Yaml, Fs and Duplicator without a WordPress bootstrap and without a
 * database, so it runs anywhere PHP does.
 *
 *   php tests/probe.php                       # parser, path and merge assertions
 *   PKG=/path/to/package php tests/probe.php  # adds Duplicator introspection
 *
 * PKG points at an extracted Duplicator package (the folder holding
 * dup-installer/). Without it the package-dependent assertions are skipped and
 * reported as skipped, rather than silently passing.
 *
 * Exits 0 when every assertion passes, 1 otherwise — safe for CI.
 */

$base = dirname( __DIR__ );
require_once $base . '/src/Yaml.php';
require_once $base . '/src/Fs.php';
require_once $base . '/src/ConfigFile.php';
require_once $base . '/src/Duplicator.php';
require_once $base . '/src/Ssh.php';

use MigrateApp\Yaml;
use MigrateApp\Fs;
use MigrateApp\ConfigFile;
use MigrateApp\Duplicator;
use MigrateApp\Ssh;

$pass    = 0;
$fail    = 0;
$skipped = 0;

function skip( $label, $why ) {
	global $skipped;
	$skipped++;
	printf( "  \033[33mSKIP\033[0m %-52s %s\n", $label, $why );
}

function check( $label, $actual, $expected ) {
	global $pass, $fail;
	$ok = ( $actual === $expected );
	if ( $ok ) {
		$pass++;
		printf( "  \033[32mPASS\033[0m %-52s %s\n", $label, is_scalar( $actual ) ? var_export( $actual, true ) : json_encode( $actual ) );
	} else {
		$fail++;
		printf( "  \033[31mFAIL\033[0m %-52s got=%s want=%s\n", $label, json_encode( $actual ), json_encode( $expected ) );
	}
}

echo "\n== ISC-12: built-in YAML fallback ==\n";
$yaml = <<<Y
---
# a comment line
origin_url: https://old.com   # trailing comment
target_url: "https://new.com"
plugin_path: 'wp-content/plugins'
uploads_path:
database: dup-installer/x.sql
table_prefix: wp_
theme_path:
  - wp-content/themes/recap
  - wp-content/themes/recap-child
Y;
$p = Yaml::parse_builtin( $yaml );
check( 'plain scalar', $p['origin_url'], 'https://old.com' );
check( 'double-quoted scalar', $p['target_url'], 'https://new.com' );
check( 'single-quoted scalar', $p['plugin_path'], 'wp-content/plugins' );
check( 'empty value -> null', $p['uploads_path'], null );
check( 'trailing comment stripped', strpos( $p['origin_url'], '#' ), false );
check( 'sequence parsed', $p['theme_path'], array( 'wp-content/themes/recap', 'wp-content/themes/recap-child' ) );
check( 'key after sequence', $p['table_prefix'], 'wp_' );

echo "\n== ISC-13: path resolution ==\n";
check( 'relative resolves against base', Fs::resolve( 'wp-content/themes/recap', '/var/www/pkg' ), '/var/www/pkg/wp-content/themes/recap' );
check( 'absolute passes through', Fs::resolve( '/abs/themes/recap', '/var/www/pkg' ), '/abs/themes/recap' );
check( 'trailing slash trimmed', Fs::resolve( '/abs/themes/recap/', '/var/www/pkg' ), '/abs/themes/recap' );
check( 'empty stays empty', Fs::resolve( '', '/var/www/pkg' ), '' );

echo "\n== ISC-14: string-or-list normalisation ==\n";
check( 'string -> list', Fs::to_list( 'a/b' ), array( 'a/b' ) );
check( 'list -> list', Fs::to_list( array( 'a', 'b' ) ), array( 'a', 'b' ) );
check( 'null -> empty', Fs::to_list( null ), array() );

echo "\n== ISC-17/18/19/49: Duplicator introspection ==\n";
$pkg = getenv( 'PKG' );

if ( ! $pkg || ! is_dir( $pkg ) ) {
	skip( 'Duplicator introspection', 'set PKG=/path/to/extracted/package to run these' );
	skip( 'generated-yaml round-trip', 'needs PKG' );
} else {
	$dup = new Duplicator( $pkg );
	$db  = $dup->find_database();

	// Shape assertions — true of any Duplicator package.
	check( 'manifest found', $dup->has_manifest(), true );
	check( 'database globbed', is_string( $db ) && is_file( $db ), true );
	check( 'origin_url detected', (bool) preg_match( '#^https?://#', (string) $dup->detect_origin_url( $db ) ), true );
	check( 'source prefix detected', (bool) $dup->detect_prefix( $db ), true );
	check( 'collation detected', (bool) $dup->detect_collation( $db ), true );
	check( 'admin users found', count( $dup->admin_users() ) > 0, true );
	check( 'multisite flag is boolean', is_bool( $dup->is_multisite() ), true );

	$themes = $dup->detect_themes( $db );
	check( 'active theme detected', (bool) $themes['stylesheet'], true );

	// Deliberately no hardcoded expected values here. Asserting that a specific
	// package yields a specific domain, admin address or theme slug would bake
	// someone's real site into a public test suite, and it would only ever pass
	// on one machine. The shape assertions above cover the same code paths for
	// any package you point PKG at.
	echo "  detected: {$themes['stylesheet']}"
		. ( $themes['template'] && $themes['template'] !== $themes['stylesheet'] ? " (child of {$themes['template']})" : '' )
		. ', prefix ' . $dup->detect_prefix( $db ) . "\n";

	echo "\n== ISC-20: generated yaml round-trips through the loader ==\n";
	$data   = array(
		'origin_url'   => $dup->detect_origin_url( $db ),
		'target_url'   => 'https://new-site.test',
		'theme_path'   => array_values( array_unique( array_filter( array( $themes['template'], $themes['stylesheet'] ) ) ) ),
		'plugin_path'  => 'wp-content/plugins',
		'uploads_path' => 'wp-content/uploads',
		'database'     => 'dup-installer/' . basename( (string) $db ),
		'table_prefix' => $dup->detect_prefix( $db ),
	);
	$dumped = Yaml::dump( $data, array( 'generated header line' ) );
	$back   = Yaml::parse_builtin( $dumped );
	check( 'round-trip origin_url', $back['origin_url'], $data['origin_url'] );
	check( 'round-trip theme list', $back['theme_path'], $data['theme_path'] );
	check( 'round-trip database', $back['database'], $data['database'] );
	check( 'round-trip prefix', $back['table_prefix'], $data['table_prefix'] );
}

echo "\n== ConfigFile: theme paths that survive the merge ==\n";
$cf = sys_get_temp_dir() . '/migrate-app-configfile-' . getmypid();
@mkdir( $cf . '/wp-content/themes/parent', 0777, true );
@mkdir( $cf . '/wp-content/themes/child', 0777, true );
@mkdir( $cf . '/wp-content/themes/themes/rem', 0777, true );
@mkdir( $cf . '/wp-content/plugins', 0777, true );
@mkdir( $cf . '/wp-content/uploads', 0777, true );
file_put_contents( $cf . '/database.sql', "-- MySQL dump\nCREATE TABLE `wp_options` (id int);\n" );

check( 'a single theme resolves to its own directory', ConfigFile::theme_paths( $cf, 'parent', 'parent' ), array( 'wp-content/themes/parent' ) );
check( 'a child theme brings the parent along', ConfigFile::theme_paths( $cf, 'parent', 'child' ), array( 'wp-content/themes/parent', 'wp-content/themes/child' ) );
check( 'a theme absent from the package falls back to the container', ConfigFile::theme_paths( $cf, 'ghost', 'ghost' ), array( 'wp-content/themes' ) );

/*
 * The regression this class was extracted for. `template` = `themes/rem` is a
 * theme in a SUBDIRECTORY of the themes root, which WordPress supports. Naming
 * it directly makes merge_into() flatten it to wp-content/themes/rem via
 * basename(), where a different theme of that name may already sit — so the
 * container is the only correct answer.
 */
check( 'a nested active theme is detected', ConfigFile::is_nested( 'themes/rem', 'themes/rem' ), true );
check( 'a normal active theme is not', ConfigFile::is_nested( 'rem', 'rem' ), false );
check( 'a nested active theme forces the container', ConfigFile::theme_paths( $cf, 'themes/rem', 'themes/rem' ), array( 'wp-content/themes' ) );
check( 'and never names the nested directory itself', in_array( 'wp-content/themes/themes/rem', ConfigFile::theme_paths( $cf, 'themes/rem', 'themes/rem' ), true ), false );

echo "\n== ConfigFile: the built map ==\n";
$built = ConfigFile::build(
	$cf,
	array(
		'origin_url' => 'http://old-site.test',
		'target_url' => '',
		'template'   => 'parent',
		'stylesheet' => 'parent',
		'database'   => $cf . '/database.sql',
		'prefix'     => 'wp_',
	)
);
check( 'origin_url is carried through', $built['origin_url'], 'http://old-site.test' );
check( 'target_url stays empty for the remote fallback', $built['target_url'], '' );
check( 'a single theme collapses to a scalar', $built['theme_path'], 'wp-content/themes/parent' );
check( 'plugins are detected', $built['plugin_path'], 'wp-content/plugins' );
check( 'uploads are detected', $built['uploads_path'], 'wp-content/uploads' );
check( 'the dump path is made relative to the folder', $built['database'], 'database.sql' );
check( 'table_prefix is the source prefix', $built['table_prefix'], 'wp_' );

$absent = ConfigFile::build( $cf . '/wp-content', array( 'origin_url' => 'x', 'database' => '' ) );
check( 'a missing uploads directory yields an empty value', $absent['uploads_path'], '' );
check( 'an empty dump path stays empty', $absent['database'], '' );

echo "\n== ConfigFile: rendered output round-trips ==\n";
$rendered = ConfigFile::render(
	$cf,
	array(
		'origin_url' => 'http://old-site.test',
		'target_url' => '',
		'template'   => 'parent',
		'stylesheet' => 'child',
		'database'   => $cf . '/database.sql',
		'prefix'     => 'wp_',
	),
	array( 'a header line' )
);
$parsed = Yaml::parse_builtin( $rendered );
check( 'round-trip origin_url', $parsed['origin_url'], 'http://old-site.test' );
check( 'round-trip theme list', $parsed['theme_path'], array( 'wp-content/themes/parent', 'wp-content/themes/child' ) );
check( 'round-trip database', $parsed['database'], 'database.sql' );
check( 'round-trip prefix', $parsed['table_prefix'], 'wp_' );

/*
 * An empty target_url must render as a bare `target_url:`. That is the form
 * migrate_app_pull has always written and the form tests/e2e-pull.sh asserts,
 * and it has to parse back to '' so load_config() takes the home_url()
 * fallback rather than rewriting every URL to the literal string.
 */
check( 'an empty target_url renders bare, not as ""', (bool) preg_match( '/^target_url:$/m', $rendered ), true );
// The built-in parser gives a bare key null, and load_config() casts through
// normalize_url() before testing it — so null and '' are the same thing here.
// Assert the contract that actually holds rather than the representation.
check( 'and parses back to an empty value', (string) $parsed['target_url'], '' );
check( 'the header is emitted as a comment', (bool) preg_match( '/^# a header line$/m', $rendered ), true );

Fs::rmdir_recursive( $cf );

echo "\n== backup plausibility: a truncated dump must not pass ==\n";
$bk = sys_get_temp_dir() . '/migrate-app-backup-probe-' . getmypid();
@mkdir( $bk, 0777, true );
file_put_contents( $bk . '/empty.sql', '' );
file_put_contents( $bk . '/tiny.sql', "-- MySQL dump\n" );
file_put_contents( $bk . '/real.sql', "-- MySQL dump 10.13\n" . str_repeat( "-- padding\n", 200 ) . "CREATE TABLE `wp_options` (id int);\n" );
file_put_contents( $bk . '/html.sql', str_repeat( "<html>not a dump</html>\n", 200 ) );
check( 'empty file rejected', Fs::looks_like_sql_dump( $bk . '/empty.sql' ), false );
check( 'truncated file rejected', Fs::looks_like_sql_dump( $bk . '/tiny.sql' ), false );
check( 'wrong content rejected', Fs::looks_like_sql_dump( $bk . '/html.sql' ), false );
check( 'real dump accepted', Fs::looks_like_sql_dump( $bk . '/real.sql' ), true );
check( 'missing file rejected', Fs::looks_like_sql_dump( $bk . '/nope.sql' ), false );
Fs::rmdir_recursive( $bk );

echo "\n== ISC-39: merge is ADDITIVE ==\n";
$tmp = sys_get_temp_dir() . '/migrate-app-probe-' . getmypid();
@mkdir( $tmp . '/src/recap', 0777, true );
@mkdir( $tmp . '/src/recap/inc', 0777, true );
@mkdir( $tmp . '/dst/themes/twentytwentyfive', 0777, true );
@mkdir( $tmp . '/dst/themes/recap', 0777, true );
file_put_contents( $tmp . '/src/recap/style.css', 'NEW' );
file_put_contents( $tmp . '/src/recap/inc/fn.php', '<?php' );
file_put_contents( $tmp . '/dst/themes/twentytwentyfive/style.css', 'KEEP-ME' );
file_put_contents( $tmp . '/dst/themes/recap/style.css', 'OLD' );
file_put_contents( $tmp . '/dst/themes/recap/local-only.txt', 'KEEP-ME-TOO' );

$res = Fs::merge_dir( $tmp . '/src/recap', $tmp . '/dst/themes/recap', false );
check( 'merge method used', in_array( $res['method'], array( 'rsync', 'php' ), true ), true );
check( 'source file overwrote destination', trim( file_get_contents( $tmp . '/dst/themes/recap/style.css' ) ), 'NEW' );
check( 'nested source file arrived', is_file( $tmp . '/dst/themes/recap/inc/fn.php' ), true );
check( 'destination-only FILE survived', trim( file_get_contents( $tmp . '/dst/themes/recap/local-only.txt' ) ), 'KEEP-ME-TOO' );
check( 'destination-only THEME survived', is_file( $tmp . '/dst/themes/twentytwentyfive/style.css' ), true );

echo "\n== ISC-22 (unit half): dry-run merge writes nothing ==\n";
$dry = Fs::merge_dir( $tmp . '/src/recap', $tmp . '/dst/themes/never-created', true );
check( 'dry-run reports method', $dry['method'], 'dry-run' );
check( 'dry-run created no directory', is_dir( $tmp . '/dst/themes/never-created' ), false );
check( 'dry-run counted files', $dry['files'], 2 );

echo "\n== theme/plugin container detection ==\n";
check( 'is_theme_dir on a theme', Fs::is_theme_dir( $tmp . '/dst/themes/recap' ), true );
check( 'is_theme_dir on a container', Fs::is_theme_dir( $tmp . '/dst/themes' ), false );

// The Hello Dolly regression: a plugins CONTAINER holds hello.php, a genuine
// single-file plugin, at its root. A header-only test calls the container a
// plugin and the whole merge lands in the wrong place.
@mkdir( $tmp . '/plugins/akismet', 0777, true );
file_put_contents( $tmp . '/plugins/hello.php', "<?php\n/*\nPlugin Name: Hello Dolly\n*/\n" );
file_put_contents( $tmp . '/plugins/akismet/akismet.php', "<?php\n/*\nPlugin Name: Akismet\n*/\n" );
check( 'is_plugin_dir on a real plugin', Fs::is_plugin_dir( $tmp . '/plugins/akismet' ), true );
check( 'is_plugin_dir on a container holding hello.php', Fs::is_plugin_dir( $tmp . '/plugins' ), false );

echo "\n== ISC-118: a truncated dump is caught by the tail, not the head ==\n";

// The failure this guards against: a transfer dies mid-dump. The head is intact
// — CREATE TABLE and all — so looks_like_sql_dump() is satisfied. Only the tail
// tells the truth.
$whole = $tmp . '/whole.sql';
$cut   = $tmp . '/cut.sql';
$head  = "-- MySQL dump 10.13\n" . str_repeat( "CREATE TABLE `wp_x` (`id` int);\n", 60 );

file_put_contents( $whole, $head . "\n-- Dump completed on 2026-08-27 12:00:00\n" );
file_put_contents( $cut, $head );

check( 'whole dump passes the head check', Fs::looks_like_sql_dump( $whole ), true );
check( 'truncated dump ALSO passes the head check', Fs::looks_like_sql_dump( $cut ), true );
check( 'whole dump passes the tail check', Fs::sql_dump_is_complete( $whole ), true );
check( 'truncated dump FAILS the tail check', Fs::sql_dump_is_complete( $cut ), false );
check( 'missing file fails the tail check', Fs::sql_dump_is_complete( $tmp . '/nope.sql' ), false );

// A marker further back than the last 4 KB must still be found if it is the end.
file_put_contents( $tmp . '/padded.sql', $head . str_repeat( "-- filler\n", 300 ) . "-- Dump completed\n" );
check( 'marker at the very end of a longer file is found', Fs::sql_dump_is_complete( $tmp . '/padded.sql' ), true );

echo "\n== ISC-121: backup-plugin archives are excluded from a pull by default ==\n";
$noise = Ssh::content_noise();
check( 'updraft is excluded', in_array( 'updraft', $noise, true ), true );
check( 'ai1wm-backups is excluded', in_array( 'ai1wm-backups', $noise, true ), true );
check( 'all-in-one .wpress archives are excluded', in_array( '*.wpress', $noise, true ), true );
check( 'caches are excluded', in_array( 'cache', $noise, true ), true );
// wp-config.php is NOT here on purpose: it is refused structurally, not by pattern.
check( 'wp-config.php is not merely an exclude pattern', in_array( 'wp-config.php', $noise, true ), false );

echo "\n== prefix rewrite: value-level keys, both quote styles ==\n";

// The regression this guards: mysqldump — and therefore `wp db export`, and
// therefore every package migrate_app_pull produces — writes string literals in
// SINGLE quotes. This pattern accepted double quotes only until 1.2.0, so
// wp_user_roles was never rewritten and every user came out with no role.
$src_prefix = 'wp_';
$dst_prefix = 'dst_';
$q          = preg_quote( $src_prefix, '/' );
$meta_keys  = array( 'user_roles', 'capabilities', 'user_level', 'dashboard_quick_press_last_post_id', 'user-settings', 'user-settings-time' );
$meta_re    = '/([\'"])' . $q . '(' . implode( '|', array_map( function ( $k ) {
	return preg_quote( $k, '/' );
}, $meta_keys ) ) . ')\1/';
$rewrite = function ( $line ) use ( $meta_re, $dst_prefix ) {
	return preg_replace( $meta_re, '$1' . $dst_prefix . '$2$1', $line );
};

check( 'single-quoted user_roles is rewritten', $rewrite( "(1,'wp_user_roles','a:1{}')" ), "(1,'dst_user_roles','a:1{}')" );
check( 'double-quoted user_roles is rewritten', $rewrite( '(1,"wp_user_roles","x")' ), '(1,"dst_user_roles","x")' );
check( 'capabilities is rewritten', $rewrite( "('wp_capabilities','a:1')" ), "('dst_capabilities','a:1')" );
check( 'user_level is rewritten', $rewrite( "('wp_user_level','10')" ), "('dst_user_level','10')" );
check( 'a hyphenated key is rewritten', $rewrite( "('wp_user-settings','x')" ), "('dst_user-settings','x')" );
check( 'a longer name is left alone', $rewrite( "('wp_user_rolesX')" ), "('wp_user_rolesX')" );
check( 'mismatched quotes are left alone', $rewrite( "('wp_user_roles\")" ), "('wp_user_roles\")" );
check( 'a different prefix is left alone', $rewrite( "('other_user_roles')" ), "('other_user_roles')" );

echo "\n== prefix rewrite: serialized strings keep a correct byte length ==\n";

// The failure this guards: `wp_` and `dst_` are different lengths. Rewrite the
// content of a serialized string without its length prefix and unserialize()
// returns false — silently. The setting does not error, it evaporates. This is
// the tool's own first principle turned on its own dump rewriter.
$serialized_re = '/s:(\d+):"' . $q . '(' . implode( '|', array_map( function ( $k ) {
	return preg_quote( $k, '/' );
}, $meta_keys ) ) . ')"/';
$rewrite_all = function ( $line ) use ( $serialized_re, $meta_re, $dst_prefix ) {
	$line = preg_replace_callback(
		$serialized_re,
		function ( $m ) use ( $dst_prefix ) {
			$value = $dst_prefix . $m[2];
			return 's:' . strlen( $value ) . ':"' . $value . '"';
		},
		$line
	);
	return preg_replace( $meta_re, '$1' . $dst_prefix . '$2$1', $line );
};

$before = 'a:1:{s:15:"wp_capabilities";b:1;}';
$after  = $rewrite_all( $before );
check( 'serialized length is corrected', $after, 'a:1:{s:16:"dst_capabilities";b:1;}' );
check( 'the corrected payload unserializes', is_array( @unserialize( $after ) ), true );
check( 'the UNcorrected payload would not have', is_array( @unserialize( 'a:1:{s:15:"dst_capabilities";b:1;}' ) ), false );

$roles = 'a:2:{s:13:"wp_user_roles";s:1:"x";s:13:"wp_user_level";s:1:"y";}';
$out   = $rewrite_all( $roles );
check( 'two serialized keys on one line both fixed', $out, 'a:2:{s:14:"dst_user_roles";s:1:"x";s:14:"dst_user_level";s:1:"y";}' );
check( 'that payload unserializes too', is_array( @unserialize( $out ) ), true );

// A same-length prefix must be left byte-identical, not merely correct.
$q2 = preg_quote( 'abc_', '/' );
$same_re = '/s:(\d+):"' . $q2 . '(user_roles)"/';
$same = preg_replace_callback( $same_re, function ( $m ) {
	$v = 'xyz_' . $m[2];
	return 's:' . strlen( $v ) . ':"' . $v . '"';
}, 's:14:"abc_user_roles"' );
check( 'equal-length prefixes keep the same length', $same, 's:14:"xyz_user_roles"' );

// The plain (non-serialized) path must still work and must NOT gain a length.
check( 'a plain quoted key is still rewritten', $rewrite_all( "('wp_user_roles','a:0:{}')" ), "('dst_user_roles','a:0:{}')" );


echo "\n== Fiction Drafts packages are recognised (github.com/dgaitan/Fiction-Drafts) ==\n";

// The shape a Fiction Drafts export actually has once unzipped: manifest.json
// and database.sql at the root, entries relative to ABSPATH beneath. Keys and
// values below are taken from src/Backup/Manifest.php in that plugin, not
// invented — schema is the integer 1, active_theme is the stylesheet only, and
// the dump is escaped-and-single-quoted with a SET FOREIGN_KEY_CHECKS footer.
$fd = $tmp . '/fd-package';
@mkdir( $fd . '/wp-content/themes/childish', 0777, true );
@mkdir( $fd . '/wp-content/plugins', 0777, true );
@mkdir( $fd . '/wp-content/uploads', 0777, true );

file_put_contents(
	$fd . '/manifest.json',
	json_encode(
		array(
			'schema'             => 1,
			'site_url'           => 'https://origin.example',
			'home_url'           => 'https://origin.example',
			'wp_version'         => '6.9',
			'php_version'        => '8.3.0',
			'mysql_version'      => '8.0.36',
			'table_prefix'       => 'fdw_',
			'multisite'          => false,
			'active_theme'       => 'childish',
			'active_plugins'     => array( 'akismet/akismet.php' ),
			'profile'            => 'full',
			'profile_areas'      => array( 'database' => true, 'core' => true, 'uploads' => true ),
			'includes_wp_config' => false,
			'file_count'         => 12,
			'total_bytes'        => 4096,
			'created_at'         => '2026-08-28 10:00:00',
			'volumes'            => array(),
		)
	)
);

file_put_contents(
	$fd . '/database.sql',
	"-- Fiction Drafts export\n-- generated: 2026-08-28\nSET FOREIGN_KEY_CHECKS=0;\n"
	. "DROP TABLE IF EXISTS `fdw_options`;\n"
	. "CREATE TABLE `fdw_options` (`option_id` bigint, `option_name` varchar(191), `option_value` longtext);\n"
	. "INSERT INTO `fdw_options` VALUES (1,'siteurl','https://origin.example'),(2,'home','https://origin.example'),"
	. "(3,'template','parental'),(4,'stylesheet','childish'),(5,'fdw_user_roles','a:1:{s:13:\"administrator\";b:1;}');\n"
	. str_repeat( "-- padding to clear the 1KB plausibility floor\n", 40 )
	. "\nSET FOREIGN_KEY_CHECKS=1;\n"
);

$fdp = new Duplicator( $fd );
check( 'a Fiction Drafts export is detected', $fdp->has_manifest(), true );
check( 'the format is named', $fdp->format(), 'fiction-drafts' );
check( 'the label is human', $fdp->format_label(), 'Fiction Drafts' );
check( 'the ORIGIN prefix comes from the manifest', $fdp->detect_prefix(), 'fdw_' );
check( 'the dump is found at the archive root', basename( (string) $fdp->find_database() ), 'database.sql' );
check( 'origin URL is read from the dump', $fdp->detect_origin_url(), 'https://origin.example' );
check( 'the manifest home_url is available too', $fdp->manifest_home_url(), 'https://origin.example' );
check( 'multisite is read as false', $fdp->is_multisite(), false );
check( 'wp-config.php inclusion is reported', $fdp->includes_wp_config(), false );

// The stylesheet alone is what the manifest records; scanning the dump recovers
// the parent as well, which is why theme detection is NOT taken from it.
$themes = $fdp->detect_themes();
check( 'the child theme is detected', $themes['stylesheet'], 'childish' );
check( 'the PARENT theme is detected too', $themes['template'], 'parental' );

$fdv = $fdp->versions();
check( 'WordPress version is carried', isset( $fdv['wp'] ) ? $fdv['wp'] : '', '6.9' );
check( 'PHP version is carried', isset( $fdv['php'] ) ? $fdv['php'] : '', '8.3.0' );
check( 'MySQL version is carried', isset( $fdv['db'] ) ? $fdv['db'] : '', '8.0.36' );
check( 'no Duplicator version is invented', isset( $fdv['dup'] ), false );

// source_abspath must stay null: Fiction Drafts records no filesystem path, and
// a URL in that slot would make the stale-path check compare a URL to a directory.
check( 'no source path is invented from a URL', $fdp->source_abspath(), null );

check( "the FD dump passes the plausibility check", Fs::looks_like_sql_dump( $fd . '/database.sql' ), true );
check( "the FD footer counts as a complete dump", Fs::sql_dump_is_complete( $fd . '/database.sql' ), true );

echo "\n== the dump scanner reads extended inserts and both quote styles ==\n";

// Two bugs lived here together. The pattern was anchored to `VALUES (`, so only
// the FIRST tuple in a statement was ever visible — invisible with Duplicator,
// which writes one row per INSERT, and fatal with mysqldump and Fiction Drafts,
// which write hundreds. And it hardcoded double quotes, the same blind spot the
// prefix rewriter had.
$scan = $tmp . '/scan';
@mkdir( $scan, 0777, true );
$multi = "INSERT INTO `wp_options` VALUES (1,'siteurl','https://a.example'),(2,'home','https://a.example'),"
	. "(3,'template','parent-theme'),(4,'stylesheet','child-theme'),(5,'blogname','Dave\\'s Site');\n";
file_put_contents( $scan . '/database.sql', "DROP TABLE IF EXISTS `wp_options`;\nCREATE TABLE `wp_options` (x int);\n" . $multi . str_repeat( "-- pad\n", 200 ) );
file_put_contents( $scan . '/manifest.json', json_encode( array(
	'schema' => 1, 'home_url' => 'https://a.example', 'table_prefix' => 'wp_',
	'multisite' => false, 'profile_areas' => array( 'database' => true ),
) ) );
$sp = new Duplicator( $scan );
$st = $sp->detect_themes();
check( 'option 3 of 5 is found (not just the first)', $st['template'], 'parent-theme' );
check( 'option 4 of 5 is found', $st['stylesheet'], 'child-theme' );
check( 'the siteurl is read from the dump itself', $sp->detect_origin_url(), 'https://a.example' );

echo "\n== a multisite Fiction Drafts export is refusable ==\n";
$fdm = $tmp . '/fd-multisite';
@mkdir( $fdm, 0777, true );
file_put_contents( $fdm . '/manifest.json', json_encode( array(
	'schema' => 1, 'home_url' => 'https://net.example', 'table_prefix' => 'wp_',
	'multisite' => true, 'profile_areas' => array( 'database' => true ),
) ) );
$fdmp = new Duplicator( $fdm );
check( 'multisite is read as true', $fdmp->is_multisite(), true );

echo "\n== a partial export is reported, not silently merged ==\n";
$fdd = $tmp . '/fd-dbonly';
@mkdir( $fdd, 0777, true );
file_put_contents( $fdd . '/manifest.json', json_encode( array(
	'schema' => 1, 'home_url' => 'https://db.example', 'table_prefix' => 'wp_',
	'multisite' => false, 'includes_wp_config' => true,
	'profile_areas' => array( 'database' => true, 'core' => false, 'uploads' => false ),
) ) );
$fddp = new Duplicator( $fdd );
$areas = $fddp->profile_areas();
check( 'database is included', $areas['database'], true );
check( 'uploads are not', $areas['uploads'], false );
check( 'an included wp-config.php is flagged', $fddp->includes_wp_config(), true );

echo "\n== a folder that is neither format claims neither ==\n";
$plain = $tmp . '/plain';
@mkdir( $plain, 0777, true );
file_put_contents( $plain . '/manifest.json', json_encode( array( 'name' => 'some other tool', 'version' => 3 ) ) );
$pp = new Duplicator( $plain );
check( 'an unrelated manifest.json is ignored', $pp->has_manifest(), false );
check( 'and reports no format', $pp->format(), '' );

echo "\n== Fiction Drafts archives are excluded from a pull ==\n";
check( 'relocated fiction-drafts storage is excluded', in_array( 'fiction-drafts-*', Ssh::content_noise(), true ), true );

Fs::rmdir_recursive( $tmp );

printf(
	"\n%s  %d passed, %d failed, %d skipped\n\n",
	$fail ? "\033[31mFAILURES\033[0m" : "\033[32mALL GREEN\033[0m",
	$pass,
	$fail,
	$skipped
);
exit( $fail ? 1 : 0 );
