<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Admin\Tools_Page;
use Kntnt\Autolink\Keyword_Repository;
use Kntnt\Autolink\Settings_Repository;

/**
 * Stub the i18n, sanitiser and redirect functions the handlers use. wp_die and
 * wp_safe_redirect throw so execution stops where it would terminate in
 * production, letting the test observe the work done before that point.
 */
function stub_admin_functions(): void {
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'wp_unslash' )->returnArg( 1 );
	Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ): string => is_string( $s ) ? trim( $s ) : '' );
	Functions\when( 'sanitize_html_class' )->alias( static fn ( $c ): string => is_string( $c ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $c ) : '' );
	Functions\when( 'sanitize_key' )->alias( static fn ( $k ): string => is_string( $k ) ? (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ) : '' );
	Functions\when( 'esc_url_raw' )->alias( static fn ( $u ): string => is_string( $u ) ? trim( $u ) : '' );
	Functions\when( 'absint' )->alias( static fn ( $v ): int => abs( (int) $v ) );
	Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid' );
	Functions\when( 'check_admin_referer' )->justReturn( true );
	Functions\when( 'admin_url' )->justReturn( 'http://example.test/wp-admin/tools.php' );
	Functions\when( 'add_query_arg' )->justReturn( 'http://example.test/wp-admin/tools.php?page=kntnt-autolink' );
	Functions\when( 'wp_safe_redirect' )->alias( static fn ( ...$args ) => throw new RuntimeException( 'redirect' ) );
	Functions\when( 'wp_die' )->alias( static fn ( ...$args ) => throw new RuntimeException( 'wp_die' ) );
}

function make_tools_page(): Tools_Page {
	return new Tools_Page( new Settings_Repository(), new Keyword_Repository() );
}

it( 'refuses to save a keyword without the manage-keywords capability', function (): void {
	$_POST = [];
	stub_admin_functions();
	Functions\when( 'current_user_can' )->justReturn( false );
	Functions\expect( 'update_option' )->never();
	expect( fn () => make_tools_page()->handle_save_keyword() )->toThrow( RuntimeException::class );
} );

it( 'saves a sanitised keyword when authorised', function (): void {
	$_POST = [ 'id' => '', 'base' => 'cat', 'variants' => "cats\nkitty", 'url' => 'https://example.com/', 'max' => '2' ];
	stub_admin_functions();
	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'get_option' )->justReturn( false );
	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	try {
		make_tools_page()->handle_save_keyword();
	} catch ( RuntimeException $e ) {
		// Expected: wp_safe_redirect throws after the save.
	}

	expect( $captured )->not->toBeNull();
	expect( $captured[0]['base'] )->toBe( 'cat' );
	expect( $captured[0]['url'] )->toBe( 'https://example.com/' );
	expect( $captured[0]['max'] )->toBe( 2 );
	expect( $captured[0]['variants'] )->toBe( [ 'cats', 'kitty' ] );
	expect( $captured[0]['id'] )->toBe( 'uuid' );
} );

it( 'refuses to save settings without manage_options', function (): void {
	$_POST = [];
	stub_admin_functions();
	Functions\when( 'current_user_can' )->justReturn( false );
	Functions\expect( 'update_option' )->never();
	expect( fn () => make_tools_page()->handle_save_settings() )->toThrow( RuntimeException::class );
} );

it( 'saves sanitised settings including the XPath fields when authorised', function (): void {
	$_POST = [
		'deny_tags' => 'h1, h2',
		'skip_class' => 'no-autolink',
		'deny_xpath' => '//figure',
		'allow_only_xpath' => '//main',
		'link_class' => 'kntnt-autolink',
		'nofollow' => '1',
		'max_links_per_post' => '5',
		'post_types' => 'post, page',
		'terms' => '',
	];
	stub_admin_functions();
	Functions\when( 'current_user_can' )->justReturn( true );
	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	try {
		make_tools_page()->handle_save_settings();
	} catch ( RuntimeException $e ) {
		// Expected.
	}

	expect( $captured )->not->toBeNull();
	expect( $captured['deny_xpath'] )->toBe( '//figure' );
	expect( $captured['allow_only_xpath'] )->toBe( '//main' );
	expect( $captured['max_links_per_post'] )->toBe( 5 );
	expect( $captured['nofollow'] )->toBeTrue();
	expect( $captured['deny_tags'] )->toBe( [ 'h1', 'h2' ] );
} );

it( 'deletes a keyword by its sanitised id when authorised', function (): void {
	$_POST = [ 'id' => 'k1' ];
	stub_admin_functions();
	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'k1', 'base' => 'cat', 'variants' => [], 'url' => 'https://example.com/', 'max' => 1 ],
		[ 'id' => 'k2', 'base' => 'dog', 'variants' => [], 'url' => 'https://example.com/d', 'max' => 1 ],
	] );
	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	try {
		make_tools_page()->handle_delete_keyword();
	} catch ( RuntimeException $e ) {
		// Expected.
	}

	expect( $captured )->toHaveCount( 1 );
	expect( $captured[0]['id'] )->toBe( 'k2' );
} );
