<?php
/**
 * Plugin Name:       Kntnt Autolink
 * Plugin URI:        https://github.com/Kntnt/kntnt-autolink
 * Description:       Rule-based link-group→URL autolinking with include-only targeting, deep-module architecture, and a small filter API.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.4
 * Author:            Kntnt
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kntnt-autolink
 * Domain Path:       /languages
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

// Refuse to load on an unsupported PHP version instead of fataling mid-request.
if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
	add_action( 'admin_notices', static function (): void {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Kntnt Autolink requires PHP 8.4 or later and has been deactivated.', 'kntnt-autolink' );
		echo '</p></div>';
	} );
	return;
}

require_once __DIR__ . '/autoloader.php';

// Activation grants the custom capability (see install.php).
register_activation_hook( __FILE__, static function (): void {
	require_once __DIR__ . '/install.php';
} );

// Boot the plugin: the singleton wires every component and registers its hooks.
add_action( 'plugins_loaded', static function (): void {
	Plugin::get_instance( __FILE__ );
} );
