<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Admin\Link_Groups_List_Table;
use Kntnt\Autolink\Link_Group_Repository;

afterEach( function (): void {
	$_REQUEST = [];
} );

/**
 * Stub the request-reading and per-page functions prepare_items() now calls so a
 * list table can honour search / sort / pagination from $_REQUEST in isolation.
 * The per-page filter resolves to the given page size.
 */
function stub_request_list_table_functions( int $per_page = 20 ): void {
	stub_list_table_functions();
	Functions\when( 'wp_unslash' )->returnArg( 1 );
	Functions\when( 'sanitize_text_field' )->alias( static fn ( $text ): string => trim( (string) $text ) );
	Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $hook === 'kntnt_autolink_per_page' ? $per_page : $value );
}

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
	Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );
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

it( 'exposes a selection checkbox column then the Phrases, URL and Group cap columns', function (): void {
	stub_list_table_functions();
	$columns = make_list_table( [] )->get_columns();
	expect( array_keys( $columns ) )->toBe( [ 'cb', 'phrases', 'url', 'cap' ] );
	expect( $columns['phrases'] )->toBe( 'Phrases' );
	expect( $columns['url'] )->toBe( 'URL' );
	expect( $columns['cap'] )->toBe( 'Group cap' );
} );

it( 'offers Delete and Set group cap as bulk actions', function (): void {
	stub_list_table_functions();
	$actions = make_list_table( [] )->get_bulk_actions();
	expect( $actions )->toHaveKey( 'delete' );
	expect( $actions )->toHaveKey( 'set-cap' );
	expect( $actions['delete'] )->toBe( 'Delete' );
} );

it( 'renders a per-row selection checkbox carrying the group id', function (): void {
	stub_list_table_functions();
	$table = make_list_table( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1 ],
	] );
	$table->prepare_items();
	$html = $table->rows_html();

	expect( $html )->toContain( 'check-column' );
	expect( $html )->toContain( 'name="ids[]"' );
	expect( $html )->toContain( 'value="g1"' );
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
	Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );

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

it( 'exposes the Phrases and Group cap columns as sortable, Phrases by its first phrase', function (): void {
	stub_list_table_functions();
	$sortable = make_list_table( [] )->get_sortable_columns();
	expect( array_keys( $sortable ) )->toBe( [ 'phrases', 'cap' ] );
	expect( $sortable['phrases'][0] )->toBe( 'phrases' );
	expect( $sortable['cap'][0] )->toBe( 'cap' );
} );

it( 'narrows the rendered rows to the groups matching the search request parameter', function (): void {
	stub_request_list_table_functions();
	$_REQUEST = [ 's' => 'cat' ];
	$table = make_list_table( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/c', 'cap' => 1 ],
		[ 'id' => 'g2', 'phrases' => [ 'dog' ], 'url' => 'https://example.com/d', 'cap' => 1 ],
	] );
	$table->prepare_items();
	$html = $table->rows_html();
	expect( $html )->toContain( 'data-id="g1"' );
	expect( $html )->not->toContain( 'data-id="g2"' );
} );

it( 'orders the rendered rows by group cap when the request asks for it', function (): void {
	stub_request_list_table_functions();
	$_REQUEST = [ 'orderby' => 'cap', 'order' => 'desc' ];
	$table = make_list_table( [
		[ 'id' => 'low', 'phrases' => [ 'a' ], 'url' => 'https://example.com/a', 'cap' => 1 ],
		[ 'id' => 'high', 'phrases' => [ 'b' ], 'url' => 'https://example.com/b', 'cap' => 9 ],
	] );
	$table->prepare_items();
	$html = $table->rows_html();
	expect( strpos( $html, 'data-id="high"' ) )->toBeLessThan( strpos( $html, 'data-id="low"' ) );
} );

it( 'shows only the requested page and records the full total for pagination', function (): void {
	stub_request_list_table_functions( per_page: 1 );
	$_REQUEST = [ 'orderby' => 'phrases', 'order' => 'asc', 'paged' => '2' ];
	$table = make_list_table( [
		[ 'id' => 'g-apple', 'phrases' => [ 'apple' ], 'url' => 'https://example.com/a', 'cap' => 1 ],
		[ 'id' => 'g-banana', 'phrases' => [ 'banana' ], 'url' => 'https://example.com/b', 'cap' => 1 ],
		[ 'id' => 'g-cherry', 'phrases' => [ 'cherry' ], 'url' => 'https://example.com/c', 'cap' => 1 ],
	] );
	$table->prepare_items();
	$html = $table->rows_html();
	expect( $html )->toContain( 'data-id="g-banana"' );
	expect( $html )->not->toContain( 'data-id="g-apple"' );
	expect( $html )->not->toContain( 'data-id="g-cherry"' );
	expect( $table->get_pagination_arg( 'total_items' ) )->toBe( 3 );
} );
