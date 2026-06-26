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

/**
 * Stub the i18n, escaping and nonce helpers the no-JS bulk confirmation screen
 * renders through. wp_nonce_field echoes a recognisable marker so a test can
 * assert the screen is nonce-protected.
 */
function stub_bulk_confirmation_functions(): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'esc_attr' )->returnArg( 1 );
	Functions\when( 'esc_url' )->returnArg( 1 );
	Functions\when( 'esc_html_e' )->alias( static function ( $text, $domain = '' ): void {
		echo (string) $text;
	} );
	Functions\when( 'admin_url' )->alias( static fn ( $path = '' ): string => 'https://example.test/wp-admin/' . $path );
	Functions\when( 'sanitize_key' )->alias( static fn ( $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
	Functions\when( 'wp_unslash' )->returnArg( 1 );
	Functions\when( '_n' )->alias( static fn ( $single, $plural, $number ): string => $number === 1 ? $single : $plural );
	Functions\when( 'wp_nonce_field' )->alias( static function (): string {
		echo 'NONCE-FIELD';
		return 'NONCE-FIELD';
	} );
}

/**
 * Capture the HTML a page renders to the output buffer.
 */
function capture_render( Tools_Page $page ): string {
	ob_start();
	$page->render();
	return (string) ob_get_clean();
}

afterEach( function (): void {
	$_REQUEST = [];
	$_GET = [];
	$_POST = [];
} );

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
	Functions\when( '_x' )->returnArg( 1 );
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

	// The bulk affordances need their own strings: a confirm for bulk delete and a
	// singular/plural prompt pair carrying the selected count for the set-cap modal,
	// so a single selection reads "1 selected group" like the no-JS _n() path.
	expect( $localized['data']['i18n'] )->toHaveKey( 'confirmBulkDelete' );
	expect( $localized['data']['i18n'] )->toHaveKey( 'setCapPromptSingular' );
	expect( $localized['data']['i18n'] )->toHaveKey( 'setCapPromptPlural' );
} );

it( 'renders a no-JS set-cap confirmation screen with a number field, the ids and a nonce', function (): void {
	$_REQUEST = [ 'action' => 'set-cap', 'ids' => [ 'g1', 'g2' ] ];
	stub_bulk_confirmation_functions();
	Functions\when( 'current_user_can' )->justReturn( true );

	$html = capture_render( make_tools_page() );

	expect( $html )->toContain( 'type="number"' );
	expect( $html )->toContain( 'name="cap"' );
	expect( $html )->toContain( 'name="ids[]"' );
	expect( $html )->toContain( 'value="g1"' );
	expect( $html )->toContain( 'value="g2"' );
	expect( $html )->toContain( 'kntnt_autolink_bulk_confirm' );
	expect( $html )->toContain( 'NONCE-FIELD' );
} );

it( 'renders a no-JS delete confirmation screen with the ids and a nonce and no number field', function (): void {
	$_REQUEST = [ 'action' => 'delete', 'ids' => [ 'g1', 'g2', 'g3' ] ];
	stub_bulk_confirmation_functions();
	Functions\when( 'current_user_can' )->justReturn( true );

	$html = capture_render( make_tools_page() );

	expect( $html )->toContain( 'name="ids[]"' );
	expect( $html )->toContain( 'value="g3"' );
	expect( $html )->toContain( 'kntnt_autolink_bulk_confirm' );
	expect( $html )->toContain( 'NONCE-FIELD' );
	expect( $html )->not->toContain( 'type="number"' );
} );

it( 'applies a confirmed no-JS set-cap over the repository, gated capability-then-nonce, then redirects', function (): void {
	$_REQUEST = [
		'kntnt_autolink_bulk_confirm' => '1',
		'kntnt_autolink_bulk_action' => 'set-cap',
		'ids' => [ 'g1' ],
		'cap' => '6',
	];
	stub_bulk_confirmation_functions();
	Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/a', 'cap' => 1 ],
	] );

	// Capability is checked before the nonce; both must pass before any mutation.
	$order = [];
	Functions\when( 'current_user_can' )->alias( static function () use ( &$order ): bool {
		$order[] = 'cap';
		return true;
	} );
	Functions\when( 'check_admin_referer' )->alias( static function () use ( &$order ): bool {
		$order[] = 'nonce';
		return true;
	} );

	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	// The redirect ends the request; a thrown exception stands in for exit so the
	// test can assert the mutation ran and the handler stopped there.
	Functions\when( 'wp_safe_redirect' )->alias( static function (): never {
		throw new RuntimeException( 'redirected' );
	} );

	expect( static fn () => make_tools_page()->render() )->toThrow( RuntimeException::class, 'redirected' );
	expect( $order )->toBe( [ 'cap', 'nonce' ] );
	expect( $captured[0]['cap'] )->toBe( 6 );
} );

it( 'applies a confirmed no-JS delete over the repository, gated capability-then-nonce, then redirects', function (): void {
	$_REQUEST = [
		'kntnt_autolink_bulk_confirm' => '1',
		'kntnt_autolink_bulk_action' => 'delete',
		'ids' => [ 'g1', 'g2' ],
	];
	stub_bulk_confirmation_functions();
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/a', 'cap' => 1 ],
		[ 'id' => 'g2', 'phrases' => [ 'dog' ], 'url' => 'https://example.com/b', 'cap' => 1 ],
		[ 'id' => 'g3', 'phrases' => [ 'fox' ], 'url' => 'https://example.com/c', 'cap' => 1 ],
	] );

	// Capability is checked before the nonce; both must pass before any mutation.
	$order = [];
	Functions\when( 'current_user_can' )->alias( static function () use ( &$order ): bool {
		$order[] = 'cap';
		return true;
	} );
	Functions\when( 'check_admin_referer' )->alias( static function () use ( &$order ): bool {
		$order[] = 'nonce';
		return true;
	} );

	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	// The redirect ends the request; a thrown exception stands in for exit so the
	// test can assert the mutation ran and the handler stopped there.
	Functions\when( 'wp_safe_redirect' )->alias( static function (): never {
		throw new RuntimeException( 'redirected' );
	} );

	expect( static fn () => make_tools_page()->render() )->toThrow( RuntimeException::class, 'redirected' );
	expect( $order )->toBe( [ 'cap', 'nonce' ] );
	expect( $captured )->toHaveCount( 1 );
	expect( $captured[0]['id'] )->toBe( 'g3' );
} );

it( 'reads the no-JS set-cap value numerically, matching the REST absint path rather than sanitize_key', function (): void {
	$_REQUEST = [
		'kntnt_autolink_bulk_confirm' => '1',
		'kntnt_autolink_bulk_action' => 'set-cap',
		'ids' => [ 'g1' ],
		'cap' => '5.5',
	];
	stub_bulk_confirmation_functions();
	Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'check_admin_referer' )->justReturn( true );
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/a', 'cap' => 1 ],
	] );

	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );
	Functions\when( 'wp_safe_redirect' )->alias( static function (): never {
		throw new RuntimeException( 'redirected' );
	} );

	// absint( "5.5" ) is 5, exactly as the REST path reads it; sanitize_key would
	// strip the dot and yield the surprising 55.
	expect( static fn () => make_tools_page()->render() )->toThrow( RuntimeException::class, 'redirected' );
	expect( $captured[0]['cap'] )->toBe( 5 );
} );
