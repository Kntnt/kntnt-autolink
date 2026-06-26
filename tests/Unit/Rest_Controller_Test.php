<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Link_Group_Query;
use Kntnt\Autolink\Link_Group_Repository;
use Kntnt\Autolink\Rest_Controller;

/**
 * Stub the i18n and sanitiser functions the controller and its repository use.
 */
function stub_rest_functions(): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_url_raw' )->alias( static fn ( $url ): string => trim( (string) $url ) );
	Functions\when( 'sanitize_text_field' )->alias( static fn ( $text ): string => trim( (string) $text ) );
	Functions\when( 'absint' )->alias( static fn ( $value ): int => abs( (int) $value ) );
	Functions\when( 'sanitize_key' )->alias( static fn ( $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );
	Functions\when( 'wp_generate_uuid4' )->justReturn( 'generated-uuid-1234' );
}

/**
 * A controller backed by a real repository, with a fixed rows renderer so the
 * response payload is assertable without a WP_List_Table.
 */
function make_rest_controller(): Rest_Controller {
	return new Rest_Controller( new Link_Group_Repository(), static fn ( Link_Group_Query $query ): array => [ 'rows' => 'ROWS-HTML', 'total' => 0 ] );
}

it( 'registers create / rows / update / delete under the kntnt-autolink/v1 namespace, each gated by the capability check', function (): void {
	$registered = [];
	Functions\when( 'register_rest_route' )->alias( static function ( $namespace, $route, $args ) use ( &$registered ): bool {
		$registered[ $route ] = [ 'namespace' => $namespace, 'endpoints' => $args ];
		return true;
	} );

	make_rest_controller()->register_routes();

	// The three routes live under the plugin's own v1 namespace and cover the CRUD surface.
	expect( array_keys( $registered ) )->toBe( [
		'/link-groups',
		'/link-groups/rows',
		'/link-groups/(?P<id>[A-Za-z0-9_\-]+)',
	] );
	foreach ( $registered as $route ) {
		expect( $route['namespace'] )->toBe( 'kntnt-autolink/v1' );
	}

	// The methods wired on each route.
	expect( $registered['/link-groups']['endpoints'][0]['methods'] )->toBe( 'POST' );
	expect( $registered['/link-groups/rows']['endpoints'][0]['methods'] )->toBe( 'GET' );
	expect( $registered['/link-groups/(?P<id>[A-Za-z0-9_\-]+)']['endpoints'][0]['methods'] )->toBe( 'POST, PUT, PATCH' );
	expect( $registered['/link-groups/(?P<id>[A-Za-z0-9_\-]+)']['endpoints'][1]['methods'] )->toBe( 'DELETE' );

	// Every endpoint is gated by the capability check, never a public pass-through:
	// with the capability denied each permission_callback returns false, whereas an
	// unguarded __return_true surface would return true.
	Functions\when( 'current_user_can' )->justReturn( false );
	foreach ( $registered as $route ) {
		foreach ( $route['endpoints'] as $endpoint ) {
			expect( $endpoint['permission_callback'] )->toBeCallable();
			expect( ( $endpoint['permission_callback'] )() )->toBeFalse();
		}
	}
} );

it( 'permits management only for users with the manage-link-groups capability', function (): void {
	Functions\expect( 'current_user_can' )->twice()->with( 'kntnt_autolink_manage_link_groups' )->andReturn( true, false );
	$controller = make_rest_controller();
	expect( $controller->can_manage() )->toBeTrue();
	expect( $controller->can_manage() )->toBeFalse();
} );

it( 'creates a sanitised group and returns the re-rendered rows', function (): void {
	stub_rest_functions();
	Functions\when( 'get_option' )->justReturn( false );
	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	$request = new WP_REST_Request( [
		'phrases' => [ 'cat', 'cats' ],
		'url' => 'https://example.com/',
		'cap' => 2,
		'nofollow' => true,
		'new_tab' => false,
	] );
	$response = make_rest_controller()->create( $request );

	expect( $response->get_status() )->toBe( 200 );
	expect( $response->get_data() )->toBe( [ 'rows' => 'ROWS-HTML', 'total' => 0, 'per_page' => 20 ] );
	expect( $captured )->toHaveCount( 1 );
	expect( $captured[0]['id'] )->toBe( 'generated-uuid-1234' );
	expect( $captured[0]['phrases'] )->toBe( [ 'cat', 'cats' ] );
	expect( $captured[0]['url'] )->toBe( 'https://example.com/' );
	expect( $captured[0]['cap'] )->toBe( 2 );
	expect( $captured[0]['nofollow'] )->toBeTrue();
	expect( $captured[0]['new_tab'] )->toBeFalse();
} );

it( 'accepts a newline-delimited phrases string', function (): void {
	stub_rest_functions();
	Functions\when( 'get_option' )->justReturn( false );
	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	$request = new WP_REST_Request( [ 'phrases' => "cat\ncats\n", 'url' => 'https://example.com/' ] );
	make_rest_controller()->create( $request );

	expect( $captured[0]['phrases'] )->toBe( [ 'cat', 'cats' ] );
} );

it( 'rejects a create with no phrases or no url and saves nothing', function (): void {
	stub_rest_functions();
	Functions\expect( 'update_option' )->never();

	$response = make_rest_controller()->create( new WP_REST_Request( [ 'phrases' => [], 'url' => '' ] ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 400 );
} );

it( 'updates the group named by the route id', function (): void {
	stub_rest_functions();
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1 ],
		[ 'id' => 'g2', 'phrases' => [ 'dog' ], 'url' => 'https://example.com/d', 'cap' => 1 ],
	] );
	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	$request = new WP_REST_Request( [ 'id' => 'g1', 'phrases' => [ 'feline' ], 'url' => 'https://example.com/', 'cap' => 1 ] );
	$response = make_rest_controller()->update( $request );

	expect( $response->get_status() )->toBe( 200 );
	expect( $captured )->toHaveCount( 2 );
	expect( $captured[0]['id'] )->toBe( 'g1' );
	expect( $captured[0]['phrases'] )->toBe( [ 'feline' ] );
} );

it( 'rejects an update for an unknown id with a 404 and saves nothing', function (): void {
	stub_rest_functions();
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1 ],
	] );
	Functions\expect( 'update_option' )->never();

	$response = make_rest_controller()->update( new WP_REST_Request( [
		'id' => 'does-not-exist',
		'phrases' => [ 'dog' ],
		'url' => 'https://example.com/d',
	] ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 404 );
} );

it( 'rejects an update without an id', function (): void {
	stub_rest_functions();
	Functions\expect( 'update_option' )->never();

	$response = make_rest_controller()->update( new WP_REST_Request( [ 'phrases' => [ 'cat' ], 'url' => 'https://example.com/' ] ) );

	expect( $response )->toBeInstanceOf( WP_Error::class );
	expect( $response->get_error_data()['status'] )->toBe( 400 );
} );

it( 'deletes the group named by the route id and re-renders the rows', function (): void {
	stub_rest_functions();
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1 ],
		[ 'id' => 'g2', 'phrases' => [ 'dog' ], 'url' => 'https://example.com/d', 'cap' => 1 ],
	] );
	$captured = null;
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload ) use ( &$captured ): bool {
		$captured = $value;
		return true;
	} );

	$response = make_rest_controller()->delete( new WP_REST_Request( [ 'id' => 'g1' ] ) );

	expect( $response->get_status() )->toBe( 200 );
	expect( $response->get_data() )->toBe( [ 'rows' => 'ROWS-HTML', 'total' => 0, 'per_page' => 20 ] );
	expect( $captured )->toHaveCount( 1 );
	expect( $captured[0]['id'] )->toBe( 'g2' );
} );

it( 'renders the current rows for the rows route', function (): void {
	stub_rest_functions();
	$response = make_rest_controller()->rows( new WP_REST_Request() );
	expect( $response->get_status() )->toBe( 200 );
	expect( $response->get_data() )->toBe( [ 'rows' => 'ROWS-HTML', 'total' => 0, 'per_page' => 20 ] );
} );

it( 'reports the page size in the rows response so the client can keep pagination honest', function (): void {
	stub_rest_functions();

	// The per-page filter is the single source of truth for the page size, and the
	// admin JS needs it to know where the last page sits after a mutation; pin it to
	// a non-default value so the assertion fails if the response ever drops it or
	// reports the wrong size.
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $hook === Link_Group_Query::PER_PAGE_FILTER ? 5 : $value );

	$response = make_rest_controller()->rows( new WP_REST_Request( [ 'paged' => '2' ] ) );

	expect( $response->get_data()['per_page'] )->toBe( 5 );
} );

it( 'passes the search, sort and page request params to the renderer and returns its total on the rows route', function (): void {
	stub_rest_functions();
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );
	$captured = null;
	$controller = new Rest_Controller(
		new Link_Group_Repository(),
		static function ( Link_Group_Query $query ) use ( &$captured ): array {
			$captured = $query;
			return [ 'rows' => 'ROWS-HTML', 'total' => 42 ];
		},
	);

	$response = $controller->rows( new WP_REST_Request( [ 's' => 'cat', 'orderby' => 'cap', 'order' => 'desc', 'paged' => '3' ] ) );

	expect( $response->get_status() )->toBe( 200 );
	expect( $response->get_data() )->toBe( [ 'rows' => 'ROWS-HTML', 'total' => 42, 'per_page' => 20 ] );
	expect( $captured )->toBeInstanceOf( Link_Group_Query::class );
	expect( $captured->search )->toBe( 'cat' );
	expect( $captured->orderby )->toBe( 'cap' );
	expect( $captured->order )->toBe( 'desc' );
	expect( $captured->page )->toBe( 3 );
} );

/**
 * A controller whose renderer records the Link_Group_Query it is handed, so a
 * test can assert exactly which view a mutation re-renders. The non-default sort
 * (cap / desc) the preservation tests pass makes those assertions load-bearing:
 * were the controller to stop forwarding orderby / order, the query would fall
 * back to the phrases / asc default and the assertion would fail.
 *
 * @param Link_Group_Query|null $captured Receives the query the renderer saw.
 */
function make_capturing_controller( ?Link_Group_Query &$captured ): Rest_Controller {
	return new Rest_Controller(
		new Link_Group_Repository(),
		static function ( Link_Group_Query $query ) use ( &$captured ): array {
			$captured = $query;
			return [ 'rows' => 'ROWS-HTML', 'total' => 1 ];
		},
	);
}

it( 'preserves the current search, sort and page when it re-renders after a create', function (): void {
	stub_rest_functions();
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );
	Functions\when( 'get_option' )->justReturn( false );
	Functions\when( 'update_option' )->justReturn( true );
	$captured = null;
	$controller = make_capturing_controller( $captured );

	$controller->create( new WP_REST_Request( [
		'phrases' => [ 'cat' ],
		'url' => 'https://example.com/',
		's' => 'dog',
		'orderby' => 'cap',
		'order' => 'desc',
		'paged' => '3',
	] ) );

	expect( $captured->search )->toBe( 'dog' );
	expect( $captured->orderby )->toBe( 'cap' );
	expect( $captured->order )->toBe( 'desc' );
	expect( $captured->page )->toBe( 3 );
} );

it( 'preserves the current search, sort and page when it re-renders after an edit', function (): void {
	stub_rest_functions();
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1 ],
	] );
	Functions\when( 'update_option' )->justReturn( true );
	$captured = null;
	$controller = make_capturing_controller( $captured );

	$controller->update( new WP_REST_Request( [
		'id' => 'g1',
		'phrases' => [ 'feline' ],
		'url' => 'https://example.com/',
		's' => 'dog',
		'orderby' => 'cap',
		'order' => 'desc',
		'paged' => '3',
	] ) );

	expect( $captured->search )->toBe( 'dog' );
	expect( $captured->orderby )->toBe( 'cap' );
	expect( $captured->order )->toBe( 'desc' );
	expect( $captured->page )->toBe( 3 );
} );

it( 'preserves the current search, sort and page when it re-renders after a delete', function (): void {
	stub_rest_functions();
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value ) => $value );
	Functions\when( 'get_option' )->justReturn( [
		[ 'id' => 'g1', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1 ],
		[ 'id' => 'g2', 'phrases' => [ 'dog' ], 'url' => 'https://example.com/d', 'cap' => 1 ],
	] );
	Functions\when( 'update_option' )->justReturn( true );
	$captured = null;
	$controller = make_capturing_controller( $captured );

	$response = $controller->delete( new WP_REST_Request( [ 'id' => 'g1', 's' => 'dog', 'orderby' => 'cap', 'order' => 'desc', 'paged' => '2' ] ) );

	expect( $response->get_data() )->toBe( [ 'rows' => 'ROWS-HTML', 'total' => 1, 'per_page' => 20 ] );
	expect( $captured->search )->toBe( 'dog' );
	expect( $captured->orderby )->toBe( 'cap' );
	expect( $captured->order )->toBe( 'desc' );
	expect( $captured->page )->toBe( 2 );
} );

it( 'returns the real list-table body HTML from the create route, not a stub', function (): void {
	stub_rest_functions();
	Functions\when( 'esc_html' )->alias( static fn ( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_attr' )->alias( static fn ( $text ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_html__' )->alias( static fn ( $text, $domain = '' ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_attr__' )->alias( static fn ( $text, $domain = '' ): string => htmlspecialchars( (string) $text, ENT_QUOTES ) );
	Functions\when( 'esc_html_e' )->alias( static function ( $text, $domain = '' ): void {
		echo htmlspecialchars( (string) $text, ENT_QUOTES );
	} );
	Functions\when( 'wp_kses_post' )->returnArg( 1 );

	// A repository over an in-memory option, so the create round-trips through real
	// persistence and the real renderer rather than a fixed closure.
	$store = [];
	Functions\when( 'get_option' )->alias( static function () use ( &$store ) {
		return $store === [] ? false : $store;
	} );
	Functions\when( 'update_option' )->alias( static function ( $key, $value, $autoload = true ) use ( &$store ): bool {
		$store = $value;
		return true;
	} );

	$repository = new Link_Group_Repository();
	$render_rows = static function ( Link_Group_Query $query ) use ( $repository ): array {
		$table = new \Kntnt\Autolink\Admin\Link_Groups_List_Table( $repository );
		$table->prepare_for( $query );
		return [ 'rows' => $table->rows_html(), 'total' => (int) $table->get_pagination_arg( 'total_items' ) ];
	};
	$controller = new Rest_Controller( $repository, $render_rows );

	$response = $controller->create( new WP_REST_Request( [ 'phrases' => [ 'cat' ], 'url' => 'https://example.com/' ] ) );
	$rows = $response->get_data()['rows'];

	expect( $rows )->toContain( 'class="url column-url"' );
	expect( $rows )->toContain( 'https://example.com/' );
	expect( $rows )->toContain( 'kntnt-autolink-edit' );
} );
