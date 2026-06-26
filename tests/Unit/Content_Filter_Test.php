<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Content_Filter;
use Kntnt\Autolink\Link_Group_Repository;
use Kntnt\Autolink\Linker;
use Kntnt\Autolink\Settings_Repository;

/**
 * A single default link group as stored in the option.
 *
 * @return list<array<string, mixed>>
 */
function one_group(): array {
	return [ [ 'id' => 'cat', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1, 'nofollow' => false, 'new_tab' => false ] ];
}

/**
 * Build a Content_Filter backed by real repositories and the real engine, with
 * get_option stubbed for both option keys.
 *
 * @param array<string, mixed>       $settings    Stored settings (empty = defaults).
 * @param list<array<string, mixed>> $link_groups Stored link-group entries.
 */
function make_content_filter( array $settings, array $link_groups ): Content_Filter {
	Functions\when( 'get_option' )->alias( static fn ( $key ) => match ( $key ) {
		'kntnt_autolink_settings' => $settings === [] ? false : $settings,
		'kntnt_autolink_link_groups' => $link_groups === [] ? false : $link_groups,
		default => false,
	} );
	return new Content_Filter( new Settings_Repository(), new Link_Group_Repository(), new Linker() );
}

/**
 * Make every filter a passthrough that returns its value argument.
 */
function passthrough_filters(): void {
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value, ...$rest ) => $value );
}

/**
 * A WP_Post-like object of the given type.
 */
function fake_post( string $post_type ): object {
	$post = Mockery::mock( 'WP_Post' );
	$post->post_type = $post_type;
	return $post;
}

it( 'leaves content untouched for an out-of-scope post type', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'attachment' ) );
	passthrough_filters();
	$cf = make_content_filter( [], one_group() );
	expect( $cf->filter_content( '<p>cat</p>' ) )->toBe( '<p>cat</p>' );
} );

it( 'leaves content untouched when should_run is false', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'post' ) );
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value, ...$rest ) => $hook === 'kntnt_autolink_should_run' ? false : $value );
	$cf = make_content_filter( [], one_group() );
	expect( $cf->filter_content( '<p>cat</p>' ) )->toBe( '<p>cat</p>' );
} );

it( 'links content for an in-scope post', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'post' ) );
	passthrough_filters();
	$cf = make_content_filter( [], one_group() );
	expect( $cf->filter_content( '<p>cat</p>' ) )->toContain( '<a class="kntnt-autolink" href="https://example.com/">cat</a>' );
} );

it( 'applies a group own nofollow policy when building its links', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'post' ) );
	passthrough_filters();
	$cf = make_content_filter( [], [ [ 'id' => 'cat', 'phrases' => [ 'cat' ], 'url' => 'https://example.com/', 'cap' => 1, 'nofollow' => true, 'new_tab' => false ] ] );
	expect( $cf->filter_content( '<p>cat</p>' ) )->toContain( '<a class="kntnt-autolink" rel="nofollow" href="https://example.com/">cat</a>' );
} );

it( 'reflects the deny filter in the assembled ruleset', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'post' ) );
	Functions\when( 'apply_filters' )->alias( static function ( $hook, $value, ...$rest ) {
		return $hook === 'kntnt_autolink_deny' ? [ 'tags' => [ 'p' ], 'xpath' => null ] : $value;
	} );
	$cf = make_content_filter( [], one_group() );
	// The phrase sits inside <p>, now denied, so nothing is linked.
	expect( $cf->filter_content( '<p>cat</p>' ) )->toBe( '<p>cat</p>' );
} );

it( 'reflects the allow_only filter in the assembled ruleset', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'post' ) );
	Functions\when( 'apply_filters' )->alias( static function ( $hook, $value, ...$rest ) {
		return $hook === 'kntnt_autolink_allow_only' ? '//main' : $value;
	} );
	$cf = make_content_filter( [], one_group() );
	$out = $cf->filter_content( '<aside>cat</aside><main>cat</main>' );
	expect( $out )->toContain( '<main><a class="kntnt-autolink" href="https://example.com/">cat</a></main>' );
	expect( $out )->toContain( '<aside>cat</aside>' );
	expect( substr_count( $out, '<a ' ) )->toBe( 1 );
} );

it( 'exposes the link_attributes filter through the engine callback', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'post' ) );
	Functions\when( 'apply_filters' )->alias( static function ( $hook, $value, ...$rest ) {
		if ( $hook === 'kntnt_autolink_link_attributes' && is_array( $value ) ) {
			$value['data-test'] = 'yes';
			return $value;
		}
		return $value;
	} );
	$cf = make_content_filter( [], one_group() );
	expect( $cf->filter_content( '<p>cat</p>' ) )->toContain( 'data-test="yes"' );
} );

it( 'reads the link-group set through the kntnt_autolink_link_groups filter', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'post' ) );
	Functions\when( 'apply_filters' )->alias( static fn ( $hook, $value, ...$rest ) => $hook === 'kntnt_autolink_link_groups' ? [] : $value );
	$cf = make_content_filter( [], one_group() );
	// The filter empties the set, so nothing is linked.
	expect( $cf->filter_content( '<p>cat</p>' ) )->toBe( '<p>cat</p>' );
} );

it( 'leaves content untouched when there are no link groups', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'post' ) );
	passthrough_filters();
	$cf = make_content_filter( [], [] );
	expect( $cf->filter_content( '<p>cat</p>' ) )->toBe( '<p>cat</p>' );
} );

it( 'runs only on posts matching configured terms', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'post' ) );
	Functions\when( 'has_term' )->justReturn( false );
	passthrough_filters();
	$cf = make_content_filter( [ 'terms' => [ 'category' => [ 5 ] ] ], one_group() );
	expect( $cf->filter_content( '<p>cat</p>' ) )->toBe( '<p>cat</p>' );
} );

it( 'runs on a post that has a configured term', function (): void {
	Functions\when( 'get_post' )->justReturn( fake_post( 'post' ) );
	Functions\when( 'has_term' )->justReturn( true );
	passthrough_filters();
	$cf = make_content_filter( [ 'terms' => [ 'category' => [ 5 ] ] ], one_group() );
	expect( $cf->filter_content( '<p>cat</p>' ) )->toContain( '<a class="kntnt-autolink"' );
} );
