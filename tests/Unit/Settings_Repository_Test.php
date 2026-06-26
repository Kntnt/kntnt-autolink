<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Ruleset;
use Kntnt\Autolink\Settings_Repository;

/**
 * Stub the sanitiser functions Settings_Repository uses on save, modelling their
 * real WordPress behaviour closely enough to assert on. The registered public
 * post-type set is stubbed to post + page, the closed list the chip selector and
 * the sanitiser both validate against.
 */
function stub_settings_sanitisers(): void {
	Functions\when( 'sanitize_html_class' )->alias( static fn ( $class ): string => (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $class ) );
	Functions\when( 'sanitize_key' )->alias( static fn ( $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
	Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
	Functions\when( 'get_post_types' )->justReturn( [ 'post' => 'post', 'page' => 'page' ] );
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

it( 'sanitize_settings returns the complete option shape, defaults filling absent fields', function (): void {
	stub_settings_sanitisers();

	// An empty submission still yields every key the engine reads, so the option
	// shape is a stable contract independent of which fields the form posted.
	$result = ( new Settings_Repository() )->sanitize_settings( [] );

	expect( array_keys( $result ) )->toBe( [
		'deny_tags',
		'skip_class',
		'deny_xpath',
		'allow_only_xpath',
		'link_class',
		'max_links_per_post',
		'post_types',
		'terms',
	] );
	expect( $result['skip_class'] )->toBe( 'no-autolink' );
	expect( $result['link_class'] )->toBe( 'kntnt-autolink' );
	expect( $result['max_links_per_post'] )->toBe( 10 );
	expect( $result )->not->toHaveKey( 'nofollow' );
	expect( $result )->not->toHaveKey( 'new_tab' );
} );

it( 'sanitize_settings round-trips array chip values from the JS hidden-input path', function (): void {
	stub_settings_sanitisers();

	$result = ( new Settings_Repository() )->sanitize_settings( [
		'deny_tags' => [ ' H2 ', 'a<b', 'PRE' ],
		'skip_class' => ' no autolink ',
		'link_class' => 'kntnt-autolink',
		'deny_xpath' => '  //figure  ',
		'allow_only_xpath' => '   ',
		'max_links_per_post' => '12',
		'post_types' => [ 'Post', 'page' ],
	] );

	expect( $result['deny_tags'] )->toBe( [ 'h2', 'ab', 'pre' ] );
	expect( $result['skip_class'] )->toBe( 'noautolink' );
	expect( $result['deny_xpath'] )->toBe( '//figure' );
	expect( $result['allow_only_xpath'] )->toBe( '' );
	expect( $result['max_links_per_post'] )->toBe( 12 );
	expect( $result['post_types'] )->toBe( [ 'post', 'page' ] );
} );

it( 'sanitize_settings parses comma/newline strings from the no-JS degraded path', function (): void {
	stub_settings_sanitisers();

	// Without JS the chip fields submit a single comma/newline string instead of
	// the array of hidden inputs; the sanitiser must accept both representations.
	$result = ( new Settings_Repository() )->sanitize_settings( [
		'deny_tags' => "H1, h2\n a , CODE",
		'post_types' => 'post, page',
	] );

	expect( $result['deny_tags'] )->toBe( [ 'h1', 'h2', 'a', 'code' ] );
	expect( $result['post_types'] )->toBe( [ 'post', 'page' ] );
} );

it( 'sanitize_settings rejects post types outside the registered public set', function (): void {
	stub_settings_sanitisers();

	// The chip selector offers only registered public types, but the no-JS text
	// field and a tampered POST can carry anything; an unregistered type must be
	// dropped so an invalid entry can never reach the engine.
	$result = ( new Settings_Repository() )->sanitize_settings( [
		'post_types' => [ 'post', 'page', 'attachment', 'bogus_type' ],
	] );

	expect( $result['post_types'] )->toBe( [ 'post', 'page' ] );
} );

it( 'sanitize_settings sanitises the term-targeting map into taxonomy => list<int>', function (): void {
	stub_settings_sanitisers();

	// The repeatable term control posts a terms map (taxonomy => term ids); the
	// sanitiser keys the taxonomy, absints the ids, drops empties and non-positive
	// ids, and drops a taxonomy left with no valid ids or an empty key.
	$result = ( new Settings_Repository() )->sanitize_settings( [
		'terms' => [
			'Category' => [ '5', '7', 'abc', '0', '-3', '5' ],
			'post_tag' => [ '12' ],
			'' => [ '9' ],
			'empty_tax' => [ 'x', '0' ],
		],
	] );

	expect( $result['terms'] )->toBe( [
		'category' => [ 5, 7 ],
		'post_tag' => [ 12 ],
	] );
} );

it( 'sanitize_settings parses the no-JS comma/newline string of term ids', function (): void {
	stub_settings_sanitisers();

	// Without JS the term chips degrade to a textarea posting a single comma/newline
	// string of ids per taxonomy; the sanitiser must accept that shape too.
	$result = ( new Settings_Repository() )->sanitize_settings( [
		'terms' => [ 'category' => "5, 7\n abc \n0" ],
	] );

	expect( $result['terms'] )->toBe( [ 'category' => [ 5, 7 ] ] );
} );

it( 'sanitize_settings round-trips the term map back into get_terms', function (): void {
	stub_settings_sanitisers();
	$sanitised = ( new Settings_Repository() )->sanitize_settings( [ 'terms' => [ 'category' => [ '3', '5' ] ] ] );

	// The sanitised option, read back, is exactly the taxonomy => list<int> map the
	// engine's is_in_scope consumes — selections persist and reload faithfully.
	Functions\when( 'get_option' )->justReturn( $sanitised );
	expect( ( new Settings_Repository() )->get_terms() )->toBe( [ 'category' => [ 3, 5 ] ] );
} );

it( 'sanitize_settings coerces the post cap to a positive integer', function (): void {
	stub_settings_sanitisers();
	$repo = new Settings_Repository();

	expect( $repo->sanitize_settings( [ 'max_links_per_post' => '0' ] )['max_links_per_post'] )->toBe( 1 );
	expect( $repo->sanitize_settings( [ 'max_links_per_post' => 'abc' ] )['max_links_per_post'] )->toBe( 1 );
	expect( $repo->sanitize_settings( [ 'max_links_per_post' => '-5' ] )['max_links_per_post'] )->toBe( 5 );
	expect( $repo->sanitize_settings( [ 'max_links_per_post' => '7' ] )['max_links_per_post'] )->toBe( 7 );
} );

it( 'save_settings sanitises and persists the option without autoloading', function (): void {
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
	expect( $captured['max_links_per_post'] )->toBe( 12 );
	expect( $captured['post_types'] )->toBe( [ 'post', 'page' ] );
	expect( $captured )->not->toHaveKey( 'nofollow' );
	expect( $captured )->not->toHaveKey( 'new_tab' );
} );

it( 'exposes targeting and cap getters', function (): void {
	Functions\when( 'get_option' )->justReturn( [ 'post_types' => [ 'post' ], 'terms' => [ 'category' => [ 3, 5 ] ], 'max_links_per_post' => 7 ] );
	$repo = new Settings_Repository();
	expect( $repo->get_post_types() )->toBe( [ 'post' ] );
	expect( $repo->get_terms() )->toBe( [ 'category' => [ 3, 5 ] ] );
	expect( $repo->get_max_links_per_post() )->toBe( 7 );
} );
