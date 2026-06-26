<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Term_Search_Controller;

/**
 * Stub the i18n and sanitiser functions the term-search controller uses,
 * modelling their real WordPress behaviour closely enough to assert on.
 */
function stub_term_search_functions(): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'sanitize_text_field' )->alias( static fn ( $text ): string => trim( (string) $text ) );
	Functions\when( 'sanitize_key' )->alias( static fn ( $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
}

it( 'registers GET /terms under the kntnt-autolink/v1 namespace, gated by manage_options', function (): void {
	$registered = [];
	Functions\when( 'register_rest_route' )->alias( static function ( $namespace, $route, $args ) use ( &$registered ): bool {
		$registered[ $route ] = [ 'namespace' => $namespace, 'endpoints' => $args ];
		return true;
	} );

	( new Term_Search_Controller() )->register_routes();

	// The one term-search route lives under the plugin's own v1 namespace and is a GET.
	expect( array_keys( $registered ) )->toBe( [ '/terms' ] );
	expect( $registered['/terms']['namespace'] )->toBe( 'kntnt-autolink/v1' );
	expect( $registered['/terms']['endpoints'][0]['methods'] )->toBe( 'GET' );

	// The route is gated by a capability check, never a public pass-through: with the
	// capability denied the permission_callback returns false.
	Functions\when( 'current_user_can' )->justReturn( false );
	$callback = $registered['/terms']['endpoints'][0]['permission_callback'];
	expect( $callback )->toBeCallable();
	expect( $callback() )->toBeFalse();
} );

it( 'permits term search only for users who can manage options', function (): void {
	Functions\expect( 'current_user_can' )->twice()->with( 'manage_options' )->andReturn( true, false );
	$controller = new Term_Search_Controller();
	expect( $controller->can_manage_settings() )->toBeTrue();
	expect( $controller->can_manage_settings() )->toBeFalse();
} );

it( 'rejects an unregistered taxonomy with a 400 and never queries terms', function (): void {
	stub_term_search_functions();
	Functions\when( 'taxonomy_exists' )->justReturn( false );
	Functions\expect( 'get_terms' )->never();

	$response = ( new Term_Search_Controller() )->search( new WP_REST_Request( [ 'taxonomy' => 'bogus', 'search' => 'x' ] ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 400 );
} );

it( 'rejects a missing taxonomy with a 400', function (): void {
	stub_term_search_functions();
	Functions\expect( 'get_terms' )->never();

	$response = ( new Term_Search_Controller() )->search( new WP_REST_Request( [] ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 400 );
} );

it( 'returns id/name pairs for a registered taxonomy, passing the sanitised query to get_terms', function (): void {
	stub_term_search_functions();
	Functions\when( 'taxonomy_exists' )->justReturn( true );
	$captured = null;
	Functions\when( 'get_terms' )->alias( static function ( $args ) use ( &$captured ): array {
		$captured = $args;
		return [
			(object) [ 'term_id' => 5, 'name' => 'Newsroom' ],
			(object) [ 'term_id' => 7, 'name' => 'Sport' ],
		];
	} );

	$response = ( new Term_Search_Controller() )->search( new WP_REST_Request( [ 'taxonomy' => 'Category', 'search' => '  New  ' ] ) );

	expect( $response )->toBeInstanceOf( WP_REST_Response::class );
	expect( $response->get_status() )->toBe( 200 );
	expect( $response->get_data() )->toBe( [
		[ 'id' => 5, 'name' => 'Newsroom' ],
		[ 'id' => 7, 'name' => 'Sport' ],
	] );

	// The taxonomy is sanitised as a key and the search trimmed before the query, and
	// the query never hides empty terms (a freshly-created term must be findable).
	expect( $captured['taxonomy'] )->toBe( 'category' );
	expect( $captured['search'] )->toBe( 'New' );
	expect( $captured['hide_empty'] )->toBeFalse();
} );

it( 'returns an empty list when get_terms yields no array, e.g. a WP_Error', function (): void {
	stub_term_search_functions();
	Functions\when( 'taxonomy_exists' )->justReturn( true );
	Functions\when( 'get_terms' )->justReturn( new WP_Error( 'oops', 'no terms' ) );

	$response = ( new Term_Search_Controller() )->search( new WP_REST_Request( [ 'taxonomy' => 'category' ] ) );

	expect( $response->get_status() )->toBe( 200 );
	expect( $response->get_data() )->toBe( [] );
} );
