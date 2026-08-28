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
require_once $base . '/src/Duplicator.php';
require_once $base . '/src/Ssh.php';

use MigrateApp\Yaml;
use MigrateApp\Fs;
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


Fs::rmdir_recursive( $tmp );

printf(
	"\n%s  %d passed, %d failed, %d skipped\n\n",
	$fail ? "\033[31mFAILURES\033[0m" : "\033[32mALL GREEN\033[0m",
	$pass,
	$fail,
	$skipped
);
exit( $fail ? 1 : 0 );
