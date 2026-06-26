<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Admin\Action_Links;

/**
 * Translation, escaping and admin-URL pass-throughs so the rendered action-links
 * markup carries the real Tools and Settings URLs and is assertable.
 */
function stub_action_links_functions(): void {
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'esc_url' )->returnArg( 1 );
	Functions\when( 'admin_url' )->alias( static fn ( $path = '' ): string => 'https://example.test/wp-admin/' . $path );
}

it( 'adds both the link-groups and the settings links to the plugin action-links row', function (): void {
	stub_action_links_functions();

	$existing = [ 'deactivate' => '<a href="https://example.test/deactivate">Deactivate</a>' ];
	$links = ( new Action_Links( 'kntnt-autolink/kntnt-autolink.php' ) )->add_links( $existing );

	// Both Autolink screens are reachable from the Plugins row: the Tools manager
	// and the Settings page, at their real registered slugs.
	$joined = implode( '', $links );
	expect( $joined )->toContain( 'tools.php?page=kntnt-autolink' );
	expect( $joined )->toContain( 'options-general.php?page=kntnt-autolink' );

	// Core's own action links are preserved, not replaced.
	expect( $links )->toHaveKey( 'deactivate' );
} );

it( 'registers the plugin_action_links filter for this plugin basename', function (): void {
	Functions\when( 'plugin_basename' )->alias( static fn ( $file ): string => 'kntnt-autolink/kntnt-autolink.php' );

	Functions\expect( 'add_filter' )->once()->with(
		'plugin_action_links_kntnt-autolink/kntnt-autolink.php',
		Mockery::type( Closure::class ),
	);

	( new Action_Links( '/wp-content/plugins/kntnt-autolink/kntnt-autolink.php' ) )->register_hooks();
	expect( true )->toBeTrue();
} );
