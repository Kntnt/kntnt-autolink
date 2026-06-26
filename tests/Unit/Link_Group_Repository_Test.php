<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Link_Group;
use Kntnt\Autolink\Link_Group_Repository;

/**
 * Stub the sanitisers Link_Group_Repository uses on save.
 */
function stub_link_group_sanitisers(): void {
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
function expect_link_group_save( mixed &$captured ): void {
	Functions\expect( 'update_option' )->once()->with(
		'kntnt_autolink_link_groups',
		Mockery::on( static function ( $value ) use ( &$captured ): bool {
			$captured = $value;
			return true;
		} ),
		false,
	);
}

it( 'returns an empty list when the option is absent', function (): void {
	Functions\when( 'get_option' )->justReturn( false );
	expect( ( new Link_Group_Repository() )->all() )->toBe( [] );
} );

it( 'hydrates link groups, defaulting missing cap, phrases and policy', function (): void {
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'url' => 'https://example.com/' ],
		[ 'id' => 'g2', 'phrases' => [ 'dog', 'dogs' ], 'url' => 'https://example.com/d', 'cap' => 3, 'nofollow' => true, 'new_tab' => true ],
	] );
	$all = ( new Link_Group_Repository() )->all();
	expect( $all )->toHaveCount( 2 );
	expect( $all[0]->phrases )->toBe( [] );
	expect( $all[0]->cap )->toBe( 1 );
	expect( $all[0]->nofollow )->toBeFalse();
	expect( $all[0]->new_tab )->toBeFalse();
	expect( $all[1]->phrases )->toBe( [ 'dog', 'dogs' ] );
	expect( $all[1]->cap )->toBe( 3 );
	expect( $all[1]->nofollow )->toBeTrue();
	expect( $all[1]->new_tab )->toBeTrue();
} );

it( 'upserts by replacing an entry with the same id', function (): void {
	stub_link_group_sanitisers();
	Functions\when( 'get_option' )->justReturn( [ [ 'id' => 'g1', 'phrases' => [ 'old' ], 'url' => 'https://example.com/', 'cap' => 1 ] ] );
	$captured = null;
	expect_link_group_save( $captured );
	( new Link_Group_Repository() )->save( new Link_Group( id: 'g1', phrases: [ 'new' ], url: 'https://example.com/', cap: 1 ) );
	expect( $captured )->toHaveCount( 1 );
	expect( $captured[0]['id'] )->toBe( 'g1' );
	expect( $captured[0]['phrases'] )->toBe( [ 'new' ] );
} );

it( 'upserts by appending an entry with a new id', function (): void {
	stub_link_group_sanitisers();
	Functions\when( 'get_option' )->justReturn( [ [ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1 ] ] );
	$captured = null;
	expect_link_group_save( $captured );
	( new Link_Group_Repository() )->save( new Link_Group( id: 'g2', phrases: [ 'dog' ], url: 'https://example.com/d', cap: 1 ) );
	expect( $captured )->toHaveCount( 2 );
	expect( $captured[1]['id'] )->toBe( 'g2' );
} );

it( 'generates an id when the group has none and returns the stored group', function (): void {
	stub_link_group_sanitisers();
	Functions\when( 'get_option' )->justReturn( false );
	$captured = null;
	expect_link_group_save( $captured );
	$stored = ( new Link_Group_Repository() )->save( new Link_Group( id: '', phrases: [ 'cat' ], url: 'https://example.com/' ) );
	expect( $captured[0]['id'] )->toBe( 'generated-uuid-1234' );
	expect( $stored )->toBeInstanceOf( Link_Group::class );
	expect( $stored->id )->toBe( 'generated-uuid-1234' );
	expect( $stored->phrases )->toBe( [ 'cat' ] );
} );

it( 'sanitises phrases on save, dropping empties', function (): void {
	stub_link_group_sanitisers();
	Functions\when( 'get_option' )->justReturn( false );
	$captured = null;
	expect_link_group_save( $captured );
	( new Link_Group_Repository() )->save( new Link_Group( id: 'g1', phrases: [ ' cat ', '', 'dog' ], url: 'https://example.com/' ) );
	expect( $captured[0]['phrases'] )->toBe( [ 'cat', 'dog' ] );
} );

it( 'deletes the entry with the matching id', function (): void {
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1 ],
		[ 'id' => 'g2', 'phrases' => [ 'dog' ], 'url' => 'https://example.com/d', 'cap' => 1 ],
	] );
	$captured = null;
	expect_link_group_save( $captured );
	( new Link_Group_Repository() )->delete( 'g1' );
	expect( $captured )->toHaveCount( 1 );
	expect( $captured[0]['id'] )->toBe( 'g2' );
} );

it( 'deletes many groups by id in a single write, keeping the rest', function (): void {
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/a', 'cap' => 1 ],
		[ 'id' => 'g2', 'phrases' => [ 'dog' ], 'url' => 'https://example.com/b', 'cap' => 1 ],
		[ 'id' => 'g3', 'phrases' => [ 'fox' ], 'url' => 'https://example.com/c', 'cap' => 1 ],
	] );
	$captured = null;
	expect_link_group_save( $captured );
	( new Link_Group_Repository() )->delete_many( [ 'g1', 'g3' ] );
	expect( $captured )->toHaveCount( 1 );
	expect( $captured[0]['id'] )->toBe( 'g2' );
} );

it( 'sets the cap on the listed groups only, leaving the others untouched', function (): void {
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/a', 'cap' => 1 ],
		[ 'id' => 'g2', 'phrases' => [ 'dog' ], 'url' => 'https://example.com/b', 'cap' => 2 ],
		[ 'id' => 'g3', 'phrases' => [ 'fox' ], 'url' => 'https://example.com/c', 'cap' => 3 ],
	] );
	$captured = null;
	expect_link_group_save( $captured );
	( new Link_Group_Repository() )->set_cap( [ 'g1', 'g3' ], 7 );
	expect( $captured )->toHaveCount( 3 );
	expect( $captured[0]['cap'] )->toBe( 7 );
	expect( $captured[1]['cap'] )->toBe( 2 );
	expect( $captured[2]['cap'] )->toBe( 7 );
} );

it( 'clamps a bulk cap below one up to the minimum of one', function (): void {
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/a', 'cap' => 4 ],
	] );
	$captured = null;
	expect_link_group_save( $captured );
	( new Link_Group_Repository() )->set_cap( [ 'g1' ], 0 );
	expect( $captured[0]['cap'] )->toBe( 1 );
} );

it( 'finds a group by id and returns null for a miss', function (): void {
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 2 ],
	] );
	$repo = new Link_Group_Repository();
	$found = $repo->find( 'g1' );
	expect( $found )->toBeInstanceOf( Link_Group::class );
	expect( $found->id )->toBe( 'g1' );
	expect( $found->cap )->toBe( 2 );
	expect( $repo->find( 'nope' ) )->toBeNull();
} );

it( 'round-trips a saved group back through all()', function (): void {
	stub_link_group_sanitisers();
	$captured = null;
	Functions\when( 'get_option' )->justReturn( false );
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );
	$repo = new Link_Group_Repository();
	$repo->save( new Link_Group( id: 'g1', phrases: [ 'cat', 'cats' ], url: 'https://example.com/', cap: 2, nofollow: true ) );

	Functions\when( 'get_option' )->justReturn( $captured );
	$all = $repo->all();
	expect( $all )->toHaveCount( 1 );
	expect( $all[0]->id )->toBe( 'g1' );
	expect( $all[0]->phrases )->toBe( [ 'cat', 'cats' ] );
	expect( $all[0]->url )->toBe( 'https://example.com/' );
	expect( $all[0]->cap )->toBe( 2 );
	expect( $all[0]->nofollow )->toBeTrue();
} );
