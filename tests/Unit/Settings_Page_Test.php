<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Admin\Settings_Page;
use Kntnt\Autolink\Settings_Repository;

/**
 * Stub the i18n, sanitiser and redirect functions the settings handler uses.
 * wp_die and wp_safe_redirect throw so execution stops where production would
 * terminate, letting the test observe the work done before that point.
 */
function stub_settings_page_functions(): void {
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'wp_unslash' )->returnArg( 1 );
	Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ): string => is_string( $s ) ? trim( $s ) : '' );
	Functions\when( 'sanitize_html_class' )->alias( static fn ( $c ): string => is_string( $c ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $c ) : '' );
	Functions\when( 'sanitize_key' )->alias( static fn ( $k ): string => is_string( $k ) ? (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ) : '' );
	Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
	Functions\when( 'check_admin_referer' )->justReturn( true );
	Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/options-general.php' );
	Functions\when( 'add_query_arg' )->justReturn( 'http://example.test/wp-admin/options-general.php?page=kntnt-autolink' );
	Functions\when( 'wp_safe_redirect' )->alias( static fn ( ...$args ) => throw new RuntimeException( 'redirect' ) );
	Functions\when( 'wp_die' )->alias( static fn ( ...$args ) => throw new RuntimeException( 'wp_die' ) );
}

function make_settings_page(): Settings_Page {
	return new Settings_Page( new Settings_Repository() );
}

it( 'registers the structural-rules page under the Settings menu, administrators only', function (): void {
	Functions\when( '__' )->returnArg( 1 );

	// add_options_page (not add_management_page) is what places the page under
	// Settings → Autolink, gated by manage_options — the deliberate Tools/Settings
	// split of ADR-0002. Pin the menu location and the capability so the IA half
	// this issue realises is not left unverified.
	Functions\expect( 'add_options_page' )->once()->with(
		'Autolink',
		'Autolink',
		'manage_options',
		'kntnt-autolink',
		Mockery::type( Closure::class ),
	);

	make_settings_page()->add_page();
	expect( true )->toBeTrue();
} );

it( 'refuses to save settings without manage_options', function (): void {
	$_POST = [];
	stub_settings_page_functions();
	Functions\when( 'current_user_can' )->justReturn( false );
	Functions\expect( 'update_option' )->never();
	expect( fn () => make_settings_page()->handle_save_settings() )->toThrow( RuntimeException::class );
} );

it( 'saves sanitised structural rules including the XPath fields when authorised', function (): void {
	$_POST = [
		'deny_tags' => 'h1, h2',
		'skip_class' => 'no-autolink',
		'deny_xpath' => '//figure',
		'allow_only_xpath' => '//main',
		'link_class' => 'kntnt-autolink',
		'max_links_per_post' => '5',
		'post_types' => 'post, page',
		'terms' => '',
	];
	stub_settings_page_functions();
	Functions\when( 'current_user_can' )->justReturn( true );
	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	try {
		make_settings_page()->handle_save_settings();
	} catch ( RuntimeException $e ) {
		// Expected: wp_safe_redirect throws after the save.
	}

	expect( $captured )->not->toBeNull();
	expect( $captured['deny_xpath'] )->toBe( '//figure' );
	expect( $captured['allow_only_xpath'] )->toBe( '//main' );
	expect( $captured['max_links_per_post'] )->toBe( 5 );
	expect( $captured['deny_tags'] )->toBe( [ 'h1', 'h2' ] );
} );

it( 'does not persist any global nofollow or new-tab setting', function (): void {
	$_POST = [ 'nofollow' => '1', 'new_tab' => '1', 'link_class' => 'kntnt-autolink' ];
	stub_settings_page_functions();
	Functions\when( 'current_user_can' )->justReturn( true );
	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	try {
		make_settings_page()->handle_save_settings();
	} catch ( RuntimeException $e ) {
		// Expected.
	}

	expect( $captured )->not->toBeNull();
	expect( $captured )->not->toHaveKey( 'nofollow' );
	expect( $captured )->not->toHaveKey( 'new_tab' );
} );
