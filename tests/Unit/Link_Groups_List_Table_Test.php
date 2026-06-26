<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Admin\Link_Groups_List_Table;
use Kntnt\Autolink\Link_Group_Repository;

/**
 * Stub the i18n and escaping functions the table renders through. The escapers
 * are aliased to real htmlspecialchars so the tests genuinely constrain
 * escaping, not merely pass values through.
 */
function stub_list_table_functions(): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html' )->alias( static fn ( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_attr' )->alias( static fn ( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_html__' )->alias( static fn ( $text, $domain = '' ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_attr__' )->alias( static fn ( $text, $domain = '' ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_html_e' )->alias( static function ( $text, $domain = '' ): void {
		echo htmlspecialchars( (string) $text, ENT_QUOTES );
	} );
	Functions\when( 'wp_kses_post' )->returnArg( 1 );
}

/**
 * A list table over a repository whose option holds the given stored entries.
 *
 * @param list<array<string, mixed>> $entries
 */
function make_list_table( array $entries ): Link_Groups_List_Table {
	Functions\when( 'get_option' )->justReturn( $entries );
	return new Link_Groups_List_Table( new Link_Group_Repository() );
}

it( 'exposes the Phrases, URL and Group cap columns', function (): void {
	stub_list_table_functions();
	$columns = make_list_table( [] )->get_columns();
	expect( array_keys( $columns ) )->toBe( [ 'phrases', 'url', 'cap' ] );
	expect( $columns['phrases'] )->toBe( 'Phrases' );
	expect( $columns['url'] )->toBe( 'URL' );
	expect( $columns['cap'] )->toBe( 'Group cap' );
} );

it( 'renders one row per group carrying the url, cap and seed data attributes', function (): void {
	stub_list_table_functions();
	$table = make_list_table( [
		[ 'id' => 'g1', 'phrases' => [ 'cat', 'cats' ], 'url' => 'https://example.com/', 'cap' => 3, 'nofollow' => true, 'new_tab' => false ],
	] );
	$table->prepare_items();
	$html = $table->rows_html();

	expect( $html )->toContain( 'data-id="g1"' );
	expect( $html )->toContain( 'data-url="https://example.com/"' );
	expect( $html )->toContain( 'data-cap="3"' );
	expect( $html )->toContain( 'data-nofollow="1"' );
	expect( $html )->toContain( 'data-new-tab="0"' );
	expect( $html )->toContain( 'class="url column-url"' );
	expect( $html )->toContain( 'class="cap column-cap"' );
	expect( $html )->toContain( 'cat, cats' );
	expect( $html )->toContain( 'kntnt-autolink-edit' );
	expect( $html )->toContain( 'kntnt-autolink-delete' );
	expect( $html )->toContain( 'Edit' );
	expect( $html )->toContain( 'Delete' );
} );

it( 'keeps the row-action data-* attributes through the real kses escaping path so Edit and Delete work', function (): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html' )->alias( static fn ( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_attr' )->alias( static fn ( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_html__' )->alias( static fn ( $text, $domain = '' ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_attr__' )->alias( static fn ( $text, $domain = '' ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_html_e' )->alias( static function ( $text, $domain = '' ): void {
		echo htmlspecialchars( (string) $text, ENT_QUOTES );
	} );

	// A faithful wp_kses_post double: like WordPress core, it removes data-*
	// attributes (they are absent from kses' allowed-attribute list for <a>). A
	// renderer that routes the row actions through kses therefore loses the very
	// data-* the admin JS reads to seed the edit modal and to address
	// DELETE /link-groups/{id} — so this guards the real escaping path, never a
	// passthrough that would mask the regression.
	Functions\when( 'wp_kses_post' )->alias( static fn ( $html ): string => (string) preg_replace( '/\s+data-[\w-]+="[^"]*"/', '', (string) $html ) );

	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1, 'nofollow' => true, 'new_tab' => true ],
	] );
	$table = new Link_Groups_List_Table( new Link_Group_Repository() );
	$table->prepare_items();
	$html = $table->rows_html();

	// The Delete row action must still carry the group id the JS reads as
	// del.dataset.id; without it the request becomes DELETE /link-groups/undefined.
	expect( $html )->toContain( 'class="kntnt-autolink-delete submitdelete" data-id="g1"' );

	// The Edit row action (the anchor, not the primary-cell button) must still carry
	// its data-* seed so the edit modal opens pre-filled.
	expect( $html )->toMatch( '/<a href="#" class="kntnt-autolink-edit"[^>]*data-url="https:\/\/example\.com\/"/' );
} );

it( 'escapes phrase and url values rendered into the row', function (): void {
	stub_list_table_functions();
	$table = make_list_table( [
		[ 'id' => 'x', 'phrases' => [ 'a"b<c' ], 'url' => 'https://example.com/', 'cap' => 1 ],
	] );
	$table->prepare_items();
	$html = $table->rows_html();

	expect( $html )->not->toContain( 'a"b<c' );
	expect( $html )->toContain( 'a&quot;b&lt;c' );
} );

it( 'renders the empty-state placeholder when there are no groups', function (): void {
	stub_list_table_functions();
	$table = make_list_table( [] );
	$table->prepare_items();
	expect( $table->rows_html() )->toContain( 'No link groups yet' );
} );
