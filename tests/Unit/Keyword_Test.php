<?php

declare( strict_types = 1 );

use Kntnt\Autolink\Keyword;

it( 'constructs and exposes its fields as readonly properties', function (): void {
	$keyword = new Keyword( id: 'k1', base: 'cat', variants: [ 'cats' ], url: 'https://example.com/', max: 3 );
	expect( $keyword->id )->toBe( 'k1' );
	expect( $keyword->base )->toBe( 'cat' );
	expect( $keyword->variants )->toBe( [ 'cats' ] );
	expect( $keyword->url )->toBe( 'https://example.com/' );
	expect( $keyword->max )->toBe( 3 );
} );

it( 'defaults max to 1 when omitted', function (): void {
	$keyword = new Keyword( id: 'k1', base: 'cat', variants: [], url: 'https://example.com/' );
	expect( $keyword->max )->toBe( 1 );
} );

it( 'returns forms() as base first, de-duplicated, order preserved', function (): void {
	$keyword = new Keyword( id: 'k1', base: 'cat', variants: [ 'cats', 'cat' ], url: 'https://example.com/' );
	expect( $keyword->forms() )->toBe( [ 'cat', 'cats' ] );
} );
