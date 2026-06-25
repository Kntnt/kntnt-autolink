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

// Remove both stored options (settings + keywords).
delete_option( 'kntnt_autolink_settings' );
delete_option( 'kntnt_autolink_keywords' );

// Remove the custom capability from every role.
require_once __DIR__ . '/autoloader.php';
( new \Kntnt\Autolink\Capabilities() )->revoke();
