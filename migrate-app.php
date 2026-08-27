<?php
/**
 * Plugin Name: WP-CLI migrate_app
 * Description: `wp migrate_app <folder_name>` — imports an uploaded WordPress package (Duplicator or plain) into this installation, rewrites URLs, and merges themes/plugins/uploads.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * License:     MIT
 *
 * Bootstrap. Works three ways:
 *   1. `wp package install /path/to/wp-cli-migrate-app`
 *   2. `wp --require=/path/to/wp-cli-migrate-app/migrate-app.php migrate_app ...`
 *   3. dropped into wp-content/plugins/ and activated (the command registers when
 *      WP-CLI is the one loading WordPress).
 *
 * @package MigrateApp
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	// Outside WP-CLI this file is inert, which is what makes it safe to leave in
	// wp-content/plugins and what lets `php -l` check it standalone.
	return;
}

$migrate_app_src = __DIR__ . '/src';

foreach ( array( 'Yaml.php', 'Fs.php', 'Duplicator.php', 'MigrateAppCommand.php' ) as $migrate_app_file ) {
	require_once $migrate_app_src . '/' . $migrate_app_file;
}

unset( $migrate_app_src, $migrate_app_file );

WP_CLI::add_command(
	'migrate_app',
	'\MigrateApp\MigrateAppCommand',
	array(
		'shortdesc' => 'Migrate an uploaded WordPress package into this installation.',
	)
);
