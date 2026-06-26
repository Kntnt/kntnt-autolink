<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Ruleset;
use Kntnt\Autolink\Settings_Repository;

/**
 * Stub the sanitiser functions Settings_Repository uses on save, modelling their
 * real WordPress behaviour closely enough to assert on.
 */
function stub_settings_sanitisers(): void {
	Functions\when( 'sanitize_html_class' )->alias( static fn ( $class ): string => (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class ) );
	Functions\when( 'sanitize_key' )->alias( static fn ( $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
	Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
}

it( 'returns the full defaults when the option is absent', function (): void {
	Functions\when( 'get_option' )->justReturn( false );
	$settings = ( new Settings_Repository() )->get_settings();
	expect( $settings['deny_tags'] )->toBe( [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'code', 'pre', 'script', 'style' ] );
	expect( $settings['skip_class'] )->toBe( 'no-autolink' );
	expect( $settings['deny_xpath'] )->toBe( '' );
	expect( $settings['allow_only_xpath'] )->toBe( '' );
	expect( $settings['link_class'] )->toBe( 'kntnt-autolink' );
	expect( $settings['max_links_per_post'] )->toBe( 10 );
	expect( $settings['post_types'] )->toBe( [ 'post', 'page' ] );
	expect( $settings['terms'] )->toBe( [] );
} );

it( 'no longer carries the global nofollow and new_tab settings', function (): void {
	Functions\when( 'get_option' )->justReturn( false );
	$settings = ( new Settings_Repository() )->get_settings();
	expect( $settings )->not->toHaveKey( 'nofollow' );
	expect( $settings )->not->toHaveKey( 'new_tab' );
} );

it( 'merges a partial stored array over the defaults', function (): void {
	Functions\when( 'get_option' )->justReturn( [ 'skip_class' => 'silent' ] );
	$settings = ( new Settings_Repository() )->get_settings();
	expect( $settings['skip_class'] )->toBe( 'silent' );
	expect( $settings['link_class'] )->toBe( 'kntnt-autolink' );
	expect( $settings['max_links_per_post'] )->toBe( 10 );
} );

it( 'hydrates a Ruleset, mapping empty XPath strings to null', function (): void {
	Functions\when( 'get_option' )->justReturn( false );
	$rules = ( new Settings_Repository() )->get_ruleset();
	expect( $rules )->toBeInstanceOf( Ruleset::class );
	expect( $rules->deny_xpath )->toBeNull();
	expect( $rules->allow_only_xpath )->toBeNull();
	expect( $rules->skip_class )->toBe( 'no-autolink' );
	expect( $rules->max_links_per_post )->toBe( 10 );
} );

it( 'passes a non-empty allow_only_xpath through to the Ruleset', function (): void {
	Functions\when( 'get_option' )->justReturn( [ 'allow_only_xpath' => '//main' ] );
	$rules = ( new Settings_Repository() )->get_ruleset();
	expect( $rules->allow_only_xpath )->toBe( '//main' );
} );

it( 'sanitises and persists settings without autoloading', function (): void {
	stub_settings_sanitisers();
	$captured = null;
	Functions\expect( 'update_option' )->once()->with(
		'kntnt_autolink_settings',
		Mockery::on( static function ( $value ) use ( &$captured ): bool {
			$captured = $value;
			return true;
		} ),
		false,
	);

	( new Settings_Repository() )->save_settings( [
		'deny_tags' => [ ' H2 ', 'a<b', 'PRE' ],
		'skip_class' => ' no autolink ',
		'link_class' => 'kntnt-autolink',
		'deny_xpath' => '  //figure  ',
		'allow_only_xpath' => '   ',
		'max_links_per_post' => '12',
		'post_types' => [ 'Post', 'page' ],
	] );

	expect( $captured['deny_tags'] )->toBe( [ 'h2', 'ab', 'pre' ] );
	expect( $captured['skip_class'] )->toBe( 'noautolink' );
	expect( $captured['deny_xpath'] )->toBe( '//figure' );
	expect( $captured['allow_only_xpath'] )->toBe( '' );
	expect( $captured['max_links_per_post'] )->toBe( 12 );
	expect( $captured['post_types'] )->toBe( [ 'post', 'page' ] );
	expect( $captured )->not->toHaveKey( 'nofollow' );
	expect( $captured )->not->toHaveKey( 'new_tab' );
} );

it( 'casts max_links_per_post junk input to zero', function (): void {
	stub_settings_sanitisers();
	$captured = null;
	Functions\expect( 'update_option' )->once()->with(
		'kntnt_autolink_settings',
		Mockery::on( static function ( $value ) use ( &$captured ): bool {
			$captured = $value;
			return true;
		} ),
		false,
	);
	( new Settings_Repository() )->save_settings( [ 'max_links_per_post' => 'abc' ] );
	expect( $captured['max_links_per_post'] )->toBe( 0 );
} );

it( 'exposes targeting and cap getters', function (): void {
	Functions\when( 'get_option' )->justReturn( [ 'post_types' => [ 'post' ], 'terms' => [ 'category' => [ 3, 5 ] ], 'max_links_per_post' => 7 ] );
	$repo = new Settings_Repository();
	expect( $repo->get_post_types() )->toBe( [ 'post' ] );
	expect( $repo->get_terms() )->toBe( [ 'category' => [ 3, 5 ] ] );
	expect( $repo->get_max_links_per_post() )->toBe( 7 );
} );
