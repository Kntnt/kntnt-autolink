<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Capabilities;

/**
 * A fake role whose edit_others_posts answer is fixed.
 */
function fake_role( bool $can_edit_others ): Mockery\MockInterface {
	$role = Mockery::mock();
	$role->shouldReceive( 'has_cap' )->with( 'edit_others_posts' )->andReturn( $can_edit_others );
	return $role;
}

it( 'grants the capability only to roles that can edit others posts', function (): void {
	$roles = Mockery::mock();
	$roles->roles = [ 'administrator' => [], 'editor' => [], 'author' => [], 'subscriber' => [] ];
	Functions\when( 'wp_roles' )->justReturn( $roles );

	$administrator = fake_role( true );
	$administrator->shouldReceive( 'add_cap' )->once()->with( 'kntnt_autolink_manage_keywords' );
	$editor = fake_role( true );
	$editor->shouldReceive( 'add_cap' )->once()->with( 'kntnt_autolink_manage_keywords' );
	$author = fake_role( false );
	$author->shouldNotReceive( 'add_cap' );
	$subscriber = fake_role( false );
	$subscriber->shouldNotReceive( 'add_cap' );

	Functions\when( 'get_role' )->alias( static fn ( $slug ) => match ( $slug ) {
		'administrator' => $administrator,
		'editor' => $editor,
		'author' => $author,
		'subscriber' => $subscriber,
		default => null,
	} );

	( new Capabilities() )->grant();
	expect( true )->toBeTrue();
} );

it( 'revokes the capability from every role', function (): void {
	$roles = Mockery::mock();
	$roles->roles = [ 'administrator' => [], 'editor' => [] ];
	Functions\when( 'wp_roles' )->justReturn( $roles );

	$administrator = Mockery::mock();
	$administrator->shouldReceive( 'remove_cap' )->once()->with( 'kntnt_autolink_manage_keywords' );
	$editor = Mockery::mock();
	$editor->shouldReceive( 'remove_cap' )->once()->with( 'kntnt_autolink_manage_keywords' );

	Functions\when( 'get_role' )->alias( static fn ( $slug ) => match ( $slug ) {
		'administrator' => $administrator,
		'editor' => $editor,
		default => null,
	} );

	( new Capabilities() )->revoke();
	expect( true )->toBeTrue();
} );
