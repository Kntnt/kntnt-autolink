<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Capabilities;
use Kntnt\Autolink\Migrator;

/**
 * A wp_roles() listing two roles plus a role double whose legacy-capability
 * removal is observed, so the migrator's legacy cleanup can run.
 */
function stub_roles_for_migration(): void {
	$roles = Mockery::mock();
	$roles->roles = [ 'administrator' => [], 'editor' => [] ];
	Functions\when( 'wp_roles' )->justReturn( $roles );
	$role = Mockery::mock();
	$role->shouldReceive( 'remove_cap' )->with( 'kntnt_autolink_manage_keywords' );
	Functions\when( 'get_role' )->justReturn( $role );
}

it( 'registers the upgrade check on init', function (): void {
	$capabilities = Mockery::mock( Capabilities::class );
	Functions\expect( 'add_action' )->once()->with( 'init', Mockery::type( Closure::class ) );

	( new Migrator( $capabilities, '1.1.0' ) )->register_hooks();
	expect( true )->toBeTrue();
} );

it( 'does nothing when the stored version already matches the running version', function (): void {
	Functions\when( 'get_option' )->justReturn( '1.1.0' );
	Functions\expect( 'update_option' )->never();
	$capabilities = Mockery::mock( Capabilities::class );
	$capabilities->shouldNotReceive( 'grant' );

	( new Migrator( $capabilities, '1.1.0' ) )->maybe_upgrade();
	expect( true )->toBeTrue();
} );

it( 're-grants the renamed capability and migrates legacy keywords on an in-place update', function (): void {
	stub_roles_for_migration();
	Functions\when( 'get_option' )->alias( static fn ( $key ) => match ( $key ) {
		'kntnt_autolink_keywords' => [
			[ 'id' => 'k1', 'base' => 'Cat', 'variants' => [ 'cats' ], 'url' => 'https://example.com/', 'max' => 2 ],
		],
		'kntnt_autolink_settings' => [ 'nofollow' => true, 'new_tab' => false ],
		default => false,
	} );
	// Record every URL the migrator routes through esc_url_raw, so the migrated
	// URL is provably re-sanitised rather than copied verbatim from the option.
	$sanitised_urls = [];
	Functions\when( 'esc_url_raw' )->alias( static function ( $url ) use ( &$sanitised_urls ): string {
		$sanitised_urls[] = $url;
		return is_string( $url ) ? $url : '';
	} );
	$updates = [];
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload = true ) use ( &$updates ): bool {
		$updates[ $key ] = $value;
		return true;
	} );
	$deleted = [];
	Functions\when( 'delete_option' )->alias( static function ( $key ) use ( &$deleted ): bool {
		$deleted[] = $key;
		return true;
	} );
	$capabilities = Mockery::mock( Capabilities::class );
	$capabilities->shouldReceive( 'grant' )->once();

	( new Migrator( $capabilities, '1.1.0' ) )->maybe_upgrade();

	expect( $updates['kntnt_autolink_link_groups'] )->toHaveCount( 1 );
	$group = $updates['kntnt_autolink_link_groups'][0];
	expect( $group['id'] )->toBe( 'k1' );
	expect( $group['phrases'] )->toBe( [ 'Cat', 'cats' ] );
	expect( $group['url'] )->toBe( 'https://example.com/' );
	expect( $group['cap'] )->toBe( 2 );
	expect( $group['nofollow'] )->toBeTrue();
	expect( $group['new_tab'] )->toBeFalse();
	expect( $sanitised_urls )->toContain( 'https://example.com/' );
	expect( $deleted )->toContain( 'kntnt_autolink_keywords' );
	expect( $updates['kntnt_autolink_version'] )->toBe( '1.1.0' );
} );

it( 'grants the cap and stamps the version but keeps existing link groups untouched', function (): void {
	stub_roles_for_migration();
	Functions\when( 'get_option' )->alias( static fn ( $key ) => match ( $key ) {
		'kntnt_autolink_link_groups' => [ [ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1 ] ],
		'kntnt_autolink_keywords' => [ [ 'id' => 'k1', 'base' => 'dog', 'variants' => [], 'url' => 'https://example.com/d', 'max' => 1 ] ],
		default => false,
	} );
	$updates = [];
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload = true ) use ( &$updates ): bool {
		$updates[ $key ] = $value;
		return true;
	} );
	Functions\when( 'delete_option' )->justReturn( true );
	$capabilities = Mockery::mock( Capabilities::class );
	$capabilities->shouldReceive( 'grant' )->once();

	( new Migrator( $capabilities, '1.1.0' ) )->maybe_upgrade();

	expect( $updates )->not->toHaveKey( 'kntnt_autolink_link_groups' );
	expect( $updates['kntnt_autolink_version'] )->toBe( '1.1.0' );
} );
