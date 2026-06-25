<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Keyword;
use Kntnt\Autolink\Keyword_Repository;

/**
 * Stub the sanitisers Keyword_Repository uses on save.
 */
function stub_keyword_sanitisers(): void {
	Functions\when( 'esc_url_raw' )->alias( static fn ( $url ): string => trim( (string) $url ) );
	Functions\when( 'sanitize_text_field' )->alias( static fn ( $text ): string => trim( (string) $text ) );
	Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
	Functions\when( 'sanitize_key' )->alias( static fn ( $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
	Functions\when( 'wp_generate_uuid4' )->justReturn( 'generated-uuid-1234' );
}

/**
 * Capture the value passed to update_option, asserting the key and autoload flag.
 *
 * @param mixed $captured
 */
function expect_keyword_save( mixed &$captured ): void {
	Functions\expect( 'update_option' )->once()->with(
		'kntnt_autolink_keywords',
		Mockery::on( static function ( $value ) use ( &$captured ): bool {
			$captured = $value;
			return true;
		} ),
		false,
	);
}

it( 'returns an empty list when the option is absent', function (): void {
	Functions\when( 'get_option' )->justReturn( false );
	expect( ( new Keyword_Repository() )->all() )->toBe( [] );
} );

it( 'hydrates keywords, defaulting missing max and variants', function (): void {
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'k1', 'base' => 'cat', 'url' => 'https://example.com/' ],
		[ 'id' => 'k2', 'base' => 'dog', 'variants' => [ 'dogs' ], 'url' => 'https://example.com/d', 'max' => 3 ],
	] );
	$all = ( new Keyword_Repository() )->all();
	expect( $all )->toHaveCount( 2 );
	expect( $all[0]->base )->toBe( 'cat' );
	expect( $all[0]->max )->toBe( 1 );
	expect( $all[0]->variants )->toBe( [] );
	expect( $all[1]->variants )->toBe( [ 'dogs' ] );
	expect( $all[1]->max )->toBe( 3 );
} );

it( 'upserts by replacing an entry with the same id', function (): void {
	stub_keyword_sanitisers();
	Functions\when( 'get_option' )->justReturn( [ [ 'id' => 'k1', 'base' => 'old', 'variants' => [], 'url' => 'https://example.com/', 'max' => 1 ] ] );
	$captured = null;
	expect_keyword_save( $captured );
	( new Keyword_Repository() )->save( new Keyword( id: 'k1', base: 'new', variants: [], url: 'https://example.com/', max: 1 ) );
	expect( $captured )->toHaveCount( 1 );
	expect( $captured[0]['id'] )->toBe( 'k1' );
	expect( $captured[0]['base'] )->toBe( 'new' );
} );

it( 'upserts by appending an entry with a new id', function (): void {
	stub_keyword_sanitisers();
	Functions\when( 'get_option' )->justReturn( [ [ 'id' => 'k1', 'base' => 'cat', 'variants' => [], 'url' => 'https://example.com/', 'max' => 1 ] ] );
	$captured = null;
	expect_keyword_save( $captured );
	( new Keyword_Repository() )->save( new Keyword( id: 'k2', base: 'dog', variants: [], url: 'https://example.com/d', max: 1 ) );
	expect( $captured )->toHaveCount( 2 );
	expect( $captured[1]['id'] )->toBe( 'k2' );
} );

it( 'generates an id when the keyword has none', function (): void {
	stub_keyword_sanitisers();
	Functions\when( 'get_option' )->justReturn( false );
	$captured = null;
	expect_keyword_save( $captured );
	( new Keyword_Repository() )->save( new Keyword( id: '', base: 'cat', variants: [], url: 'https://example.com/' ) );
	expect( $captured[0]['id'] )->toBe( 'generated-uuid-1234' );
} );

it( 'deletes the entry with the matching id', function (): void {
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'k1', 'base' => 'cat', 'variants' => [], 'url' => 'https://example.com/', 'max' => 1 ],
		[ 'id' => 'k2', 'base' => 'dog', 'variants' => [], 'url' => 'https://example.com/d', 'max' => 1 ],
	] );
	$captured = null;
	expect_keyword_save( $captured );
	( new Keyword_Repository() )->delete( 'k1' );
	expect( $captured )->toHaveCount( 1 );
	expect( $captured[0]['id'] )->toBe( 'k2' );
} );

it( 'replaces the whole list with replace_all', function (): void {
	stub_keyword_sanitisers();
	$captured = null;
	expect_keyword_save( $captured );
	( new Keyword_Repository() )->replace_all( [
		new Keyword( id: 'a', base: 'A', variants: [], url: 'https://example.com/a' ),
		new Keyword( id: 'b', base: 'B', variants: [ 'bb' ], url: 'https://example.com/b', max: 2 ),
	] );
	expect( $captured )->toHaveCount( 2 );
	expect( $captured[0]['id'] )->toBe( 'a' );
	expect( $captured[1]['variants'] )->toBe( [ 'bb' ] );
	expect( $captured[1]['max'] )->toBe( 2 );
} );

it( 'round-trips a saved keyword back through all()', function (): void {
	stub_keyword_sanitisers();
	$captured = null;
	Functions\when( 'get_option' )->justReturn( false );
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );
	$repo = new Keyword_Repository();
	$repo->save( new Keyword( id: 'k1', base: 'cat', variants: [ 'cats' ], url: 'https://example.com/', max: 2 ) );

	Functions\when( 'get_option' )->justReturn( $captured );
	$all = $repo->all();
	expect( $all )->toHaveCount( 1 );
	expect( $all[0]->id )->toBe( 'k1' );
	expect( $all[0]->base )->toBe( 'cat' );
	expect( $all[0]->variants )->toBe( [ 'cats' ] );
	expect( $all[0]->url )->toBe( 'https://example.com/' );
	expect( $all[0]->max )->toBe( 2 );
} );
