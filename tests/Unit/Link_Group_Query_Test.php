<?php

declare( strict_types = 1 );

use Kntnt\Autolink\Link_Group;
use Kntnt\Autolink\Link_Group_Query;

/**
 * Build a Link_Group with as little ceremony as the query tests need. The id is
 * derived from the first phrase so assertions can address rows by it.
 *
 * @param list<string> $phrases
 */
function lgq_group( array $phrases, string $url = 'https://example.com/', int $cap = 1 ): Link_Group {
	return new Link_Group( id: $phrases[0] ?? '', phrases: $phrases, url: $url, cap: $cap );
}

/**
 * @param array{items: list<Link_Group>, total: int} $result
 * @return list<string>
 */
function lgq_first_phrases( array $result ): array {
	return array_map( static fn ( Link_Group $g ): string => $g->phrases[0] ?? '', $result['items'] );
}

it( 'returns every group sorted by first phrase A→Z by default', function (): void {
	$result = ( new Link_Group_Query() )->results( [
		lgq_group( [ 'banana' ] ),
		lgq_group( [ 'apple' ] ),
		lgq_group( [ 'cherry' ] ),
	] );
	expect( lgq_first_phrases( $result ) )->toBe( [ 'apple', 'banana', 'cherry' ] );
	expect( $result['total'] )->toBe( 3 );
} );

it( 'narrows the result to groups whose phrase matches the search, case-insensitively', function (): void {
	$result = ( new Link_Group_Query( search: 'AP' ) )->results( [
		lgq_group( [ 'Apple' ] ),
		lgq_group( [ 'apricot' ] ),
		lgq_group( [ 'banana' ] ),
	] );
	expect( lgq_first_phrases( $result ) )->toBe( [ 'Apple', 'apricot' ] );
	expect( $result['total'] )->toBe( 2 );
} );

it( 'narrows the result to groups whose URL matches the search', function (): void {
	$result = ( new Link_Group_Query( search: 'cats' ) )->results( [
		lgq_group( [ 'x' ], 'https://example.com/cats' ),
		lgq_group( [ 'y' ], 'https://example.com/dogs' ),
	] );
	expect( lgq_first_phrases( $result ) )->toBe( [ 'x' ] );
	expect( $result['total'] )->toBe( 1 );
} );

it( 'sorts by first phrase descending when asked', function (): void {
	$result = ( new Link_Group_Query( orderby: 'phrases', order: 'desc' ) )->results( [
		lgq_group( [ 'apple' ] ),
		lgq_group( [ 'cherry' ] ),
		lgq_group( [ 'banana' ] ),
	] );
	expect( lgq_first_phrases( $result ) )->toBe( [ 'cherry', 'banana', 'apple' ] );
} );

it( 'sorts by group cap numerically, not lexically', function (): void {
	$asc = ( new Link_Group_Query( orderby: 'cap', order: 'asc' ) )->results( [
		lgq_group( [ 'a' ], 'https://example.com/', 2 ),
		lgq_group( [ 'b' ], 'https://example.com/', 10 ),
		lgq_group( [ 'c' ], 'https://example.com/', 1 ),
	] );
	expect( array_map( static fn ( Link_Group $g ): int => $g->cap, $asc['items'] ) )->toBe( [ 1, 2, 10 ] );

	$desc = ( new Link_Group_Query( orderby: 'cap', order: 'desc' ) )->results( [
		lgq_group( [ 'a' ], 'https://example.com/', 2 ),
		lgq_group( [ 'b' ], 'https://example.com/', 10 ),
		lgq_group( [ 'c' ], 'https://example.com/', 1 ),
	] );
	expect( array_map( static fn ( Link_Group $g ): int => $g->cap, $desc['items'] ) )->toBe( [ 10, 2, 1 ] );
} );

it( 'orders a multi-phrase group by its first phrase only', function (): void {
	$result = ( new Link_Group_Query() )->results( [
		lgq_group( [ 'zebra', 'apple' ] ),
		lgq_group( [ 'mango' ] ),
	] );
	expect( lgq_first_phrases( $result ) )->toBe( [ 'mango', 'zebra' ] );
} );

it( 'paginates the sorted result and reports the full total, not the page slice', function (): void {
	$result = ( new Link_Group_Query( orderby: 'phrases', order: 'asc', page: 2, per_page: 2 ) )->results( [
		lgq_group( [ 'a' ] ),
		lgq_group( [ 'b' ] ),
		lgq_group( [ 'c' ] ),
		lgq_group( [ 'd' ] ),
		lgq_group( [ 'e' ] ),
	] );
	expect( lgq_first_phrases( $result ) )->toBe( [ 'c', 'd' ] );
	expect( $result['total'] )->toBe( 5 );
} );

it( 'normalises an unknown orderby, order and a non-positive page or per-page', function (): void {
	$query = new Link_Group_Query( orderby: 'bogus', order: 'BOGUS', page: 0, per_page: 0 );
	expect( $query->orderby )->toBe( 'phrases' );
	expect( $query->order )->toBe( 'asc' );
	expect( $query->page )->toBe( 1 );
	expect( $query->per_page )->toBe( 1 );
	expect( ( new Link_Group_Query( order: 'DESC' ) )->order )->toBe( 'desc' );
} );
