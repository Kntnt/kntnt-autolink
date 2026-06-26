<?php

declare( strict_types = 1 );

use Kntnt\Autolink\Link_Group;

it( 'constructs and exposes its fields as readonly properties', function (): void {
	$group = new Link_Group( id: 'g1', phrases: [ 'cat', 'feline' ], url: 'https://example.com/', cap: 3, nofollow: true, new_tab: true );
	expect( $group->id )->toBe( 'g1' );
	expect( $group->phrases )->toBe( [ 'cat', 'feline' ] );
	expect( $group->url )->toBe( 'https://example.com/' );
	expect( $group->cap )->toBe( 3 );
	expect( $group->nofollow )->toBeTrue();
	expect( $group->new_tab )->toBeTrue();
} );

it( 'defaults cap to 1 and the link policy off when omitted', function (): void {
	$group = new Link_Group( id: 'g1', phrases: [ 'cat' ], url: 'https://example.com/' );
	expect( $group->cap )->toBe( 1 );
	expect( $group->nofollow )->toBeFalse();
	expect( $group->new_tab )->toBeFalse();
} );

it( 'has no canonical member: all phrases are peers in the stored order', function (): void {
	$group = new Link_Group( id: 'g1', phrases: [ 'feline', 'cat' ], url: 'https://example.com/' );
	expect( $group->phrases )->toBe( [ 'feline', 'cat' ] );
} );

it( 'builds no policy attributes when neither nofollow nor new_tab is set', function (): void {
	expect( ( new Link_Group( id: 'g', phrases: [ 'x' ], url: 'https://example.com/' ) )->link_attributes() )->toBe( [] );
} );

it( 'adds rel=nofollow when nofollow is on', function (): void {
	$group = new Link_Group( id: 'g', phrases: [ 'x' ], url: 'https://example.com/', nofollow: true );
	expect( $group->link_attributes() )->toBe( [ 'rel' => 'nofollow' ] );
} );

it( 'adds rel=noopener and target when new_tab is on', function (): void {
	$group = new Link_Group( id: 'g', phrases: [ 'x' ], url: 'https://example.com/', new_tab: true );
	expect( $group->link_attributes() )->toBe( [ 'rel' => 'noopener', 'target' => '_blank' ] );
} );

it( 'combines nofollow and noopener when both are on', function (): void {
	$group = new Link_Group( id: 'g', phrases: [ 'x' ], url: 'https://example.com/', nofollow: true, new_tab: true );
	expect( $group->link_attributes() )->toBe( [ 'rel' => 'nofollow noopener', 'target' => '_blank' ] );
} );
