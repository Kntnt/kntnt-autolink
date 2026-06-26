<?php
/**
 * Uninstall routine: remove both options and the custom capability.
 *
 * Runs in WordPress's uninstall context without the plugin bootstrapped, so it
 * uses fully-qualified calls and guards on WP_UNINSTALL_PLUGIN.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

// Refuse to run outside WordPress's uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove every stored option: settings, link groups, the version stamp, and the
// pre-1.1.0 keyword option in case the site was uninstalled before it migrated.
delete_option( 'kntnt_autolink_settings' );
delete_option( 'kntnt_autolink_link_groups' );
delete_option( 'kntnt_autolink_version' );
delete_option( 'kntnt_autolink_keywords' );

// Remove the custom capability from every role. The pre-1.1.0 capability is
// already retired by the upgrade routine on any site that ran 1.1.0.
require_once __DIR__ . '/autoloader.php';
( new \Kntnt\Autolink\Capabilities() )->revoke();
