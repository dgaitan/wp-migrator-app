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

use MigrateApp\Yaml;
use MigrateApp\Fs;
use MigrateApp\Duplicator;

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

	// Value assertions — only for the reference package this tool was built against.
	if ( 'dup-database__dab48ec-21220214.sql' === basename( (string) $db ) ) {
		echo "  (reference package detected — asserting exact values)\n";
		check( 'ref: origin_url', $dup->detect_origin_url( $db ), 'https://atreveteacreer.org' );
		check( 'ref: prefix', $dup->detect_prefix( $db ), 'wp_' );
		check( 'ref: collation', $dup->detect_collation( $db ), 'utf8mb4_unicode_520_ci' );
		check( 'ref: admin users', $dup->admin_users(), array( 'david@poetkods.com' ) );
		check( 'ref: parent theme (template)', $themes['template'], 'recap' );
		check( 'ref: child theme (stylesheet)', $themes['stylesheet'], 'recap-child' );
	}

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

Fs::rmdir_recursive( $tmp );

printf(
	"\n%s  %d passed, %d failed, %d skipped\n\n",
	$fail ? "\033[31mFAILURES\033[0m" : "\033[32mALL GREEN\033[0m",
	$pass,
	$fail,
	$skipped
);
exit( $fail ? 1 : 0 );
