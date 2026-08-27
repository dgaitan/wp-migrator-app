<?php
/**
 * Plugin Name: WP-CLI migrate_app
 * Description: `wp migrate_app <folder_name>` — imports an uploaded WordPress package (Duplicator or plain) into this installation, rewrites URLs, and merges themes/plugins/uploads. `wp migrate_app_remote <folder> --to=<target>` does the same against a remote install over SSH.
 * Version:     1.1.0
 * Requires PHP: 7.4
 * License:     MIT
 *
 * Bootstrap. Works three ways:
 *   1. `wp package install /path/to/wp-cli-migrate-app`
 *   2. `wp --require=/path/to/wp-cli-migrate-app/migrate-app.php migrate_app ...`
 *   3. dropped into wp-content/plugins/ and activated (the command registers when
 *      WP-CLI is the one loading WordPress).
 *
 * Route 2 is the one that matters for `migrate_app_remote`. That command is
 * registered `before_wp_load`, because the machine you type it on usually has no
 * WordPress at all — and route 3 can never reach it, since a plugin file is only
 * ever read during `after_wp_load`.
 *
 * @package MigrateApp
 */

if ( ! class_exists( 'WP_CLI' ) ) {
	// Outside WP-CLI this file is inert, which is what makes it safe to leave in
	// wp-content/plugins and what lets `php -l` check it standalone.
	return;
}

/*
 * Two copies of this tool can be reached in a single `wp` run, and it happens
 * more easily than it sounds: one installed with `wp package install`, another
 * `--require`d from a checkout, and — in remote mode — a third uploaded to the
 * far end and required by path. `require_once` does not help, because the paths
 * differ. Redeclaring a class is fatal, and so is claiming a command name that
 * is already taken.
 *
 * So the guards key on what actually collides — the class, and the command —
 * rather than on a constant. A constant only works when every copy in play is
 * new enough to define it, which is precisely the case you cannot rely on.
 */
define( 'MIGRATE_APP_LOADED', true );

$migrate_app_src = __DIR__ . '/src';

$migrate_app_classes = array(
	'Yaml'                     => 'Yaml.php',
	'Fs'                       => 'Fs.php',
	'Ssh'                      => 'Ssh.php',
	'Duplicator'               => 'Duplicator.php',
	'MigrateAppCommand'        => 'MigrateAppCommand.php',
	'MigrateAppRemoteCommand'  => 'MigrateAppRemoteCommand.php',
);

foreach ( $migrate_app_classes as $migrate_app_class => $migrate_app_file ) {
	if ( ! class_exists( '\\MigrateApp\\' . $migrate_app_class, false ) ) {
		require_once $migrate_app_src . '/' . $migrate_app_file;
	}
}

if ( ! function_exists( 'migrate_app_command_exists' ) ) {
	/**
	 * Whether a top-level command name is already registered.
	 *
	 * @param string $name Command name.
	 * @return bool
	 */
	function migrate_app_command_exists( $name ) {
		$args = array( $name );

		return (bool) WP_CLI::get_root_command()->find_subcommand( $args );
	}
}

if ( ! migrate_app_command_exists( 'migrate_app' ) && class_exists( '\MigrateApp\MigrateAppCommand' ) ) {
	WP_CLI::add_command(
		'migrate_app',
		'\MigrateApp\MigrateAppCommand',
		array(
			'shortdesc' => 'Migrate an uploaded WordPress package into this installation.',
		)
	);
}

if ( ! migrate_app_command_exists( 'migrate_app_remote' ) && class_exists( '\MigrateApp\MigrateAppRemoteCommand' ) ) {
	WP_CLI::add_command(
		'migrate_app_remote',
		'\MigrateApp\MigrateAppRemoteCommand',
		array(
			'shortdesc' => 'Push a package to a remote WordPress install and migrate it there over SSH.',
			'when'      => 'before_wp_load',
		)
	);
}

unset( $migrate_app_src, $migrate_app_file, $migrate_app_class, $migrate_app_classes );
