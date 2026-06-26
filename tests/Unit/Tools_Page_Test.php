<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Admin\Tools_Page;
use Kntnt\Autolink\Link_Group_Repository;

/**
 * A Tools_Page over a real repository, with a known plugin file and version so
 * the enqueue tests can assert the asset wiring without a WordPress bootstrap.
 */
function make_tools_page(): Tools_Page {
	return new Tools_Page( new Link_Group_Repository(), '/wp-content/plugins/kntnt-autolink/kntnt-autolink.php', '1.1.0' );
}

it( 'enqueues no assets on a screen other than its own', function (): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'add_management_page' )->justReturn( 'tools_page_kntnt-autolink' );
	Functions\expect( 'wp_enqueue_style' )->never();
	Functions\expect( 'wp_enqueue_script' )->never();

	$page = make_tools_page();
	$page->add_page();
	$page->enqueue( 'index.php' );
	expect( true )->toBeTrue();
} );

it( 'enqueues the modal stylesheet, script and config only on the Autolink screen', function (): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'add_management_page' )->justReturn( 'tools_page_kntnt-autolink' );
	Functions\when( 'plugins_url' )->alias( static fn ( $path, $file ): string => 'https://example.test/' . $path );
	Functions\when( 'rest_url' )->alias( static fn ( $path = '' ): string => 'https://example.test/wp-json/' . $path );
	Functions\when( 'esc_url_raw' )->returnArg( 1 );
	Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-xyz' );
	Functions\expect( 'wp_enqueue_style' )->once();
	Functions\expect( 'wp_enqueue_script' )->once();
	$localized = [];
	Functions\expect( 'wp_localize_script' )->once()->andReturnUsing( static function ( string $handle, string $object, array $data ) use ( &$localized ): bool {
		$localized = [ 'object' => $object, 'data' => $data ];
		return true;
	} );

	$page = make_tools_page();
	$page->add_page();
	$page->enqueue( 'tools_page_kntnt-autolink' );

	// Pin the PHP→JS data contract js/admin.js reads as window.kntntAutolink.rest
	// and .nonce: a wrong object key or an empty nonce would silently break every
	// cookie-authenticated REST mutation, and no other test constrains this seam.
	expect( $localized['object'] )->toBe( 'kntntAutolink' );
	expect( $localized['data']['rest'] )->toBe( 'https://example.test/wp-json/kntnt-autolink/v1/link-groups' );
	expect( $localized['data']['nonce'] )->toBe( 'nonce-xyz' );
} );
