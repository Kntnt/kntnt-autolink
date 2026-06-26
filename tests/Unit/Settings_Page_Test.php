<?php

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Autolink\Admin\Settings_Page;
use Kntnt\Autolink\Settings_Repository;

/**
 * Build the page with a real repository and arbitrary asset metadata. The
 * repository reads the option through get_option, which each test stubs.
 */
function make_settings_page(): Settings_Page {
	return new Settings_Page( new Settings_Repository(), 'kntnt-autolink/kntnt-autolink.php', '1.1.0' );
}

/**
 * Translation and escaping pass-throughs so rendered markup is assertable.
 */
function stub_settings_page_i18n(): void {
	Functions\when( '__' )->returnArg( 1 );
	Functions\when( 'esc_html__' )->returnArg( 1 );
	Functions\when( 'esc_html' )->returnArg( 1 );
	Functions\when( 'esc_attr' )->returnArg( 1 );
	Functions\when( 'esc_attr__' )->returnArg( 1 );
	Functions\when( 'esc_textarea' )->returnArg( 1 );
}

/**
 * A registered-public-post-types stub: the 'objects' form returns label-bearing
 * doubles, the names form returns the slug map, matching get_post_types().
 *
 * @param array<string, string> $types Slug => human label.
 */
function stub_public_post_types( array $types ): void {
	Functions\when( 'get_post_types' )->alias( static function ( $args = [], $output = 'names' ) use ( $types ): array {
		if ( $output === 'objects' ) {
			$objects = [];
			foreach ( $types as $slug => $label ) {
				$objects[ $slug ] = (object) [ 'label' => $label ];
			}
			return $objects;
		}
		$names = [];
		foreach ( array_keys( $types ) as $slug ) {
			$names[ $slug ] = $slug;
		}
		return $names;
	} );
}

it( 'registers the structural-rules page under the Settings menu, administrators only', function (): void {
	Functions\when( '__' )->returnArg( 1 );

	// add_options_page (not add_management_page) is what places the page under
	// Settings → Autolink, gated by manage_options — the deliberate Tools/Settings
	// split of ADR-0002.
	Functions\expect( 'add_options_page' )->once()->with(
		'Autolink',
		'Autolink',
		'manage_options',
		'kntnt-autolink',
		Mockery::type( Closure::class ),
	)->andReturn( 'settings_page_kntnt-autolink' );

	make_settings_page()->add_page();
	expect( true )->toBeTrue();
} );

it( 'registers the option with a sanitize callback and three native sections', function (): void {
	stub_settings_page_i18n();

	// Capture the Settings-API registration so the native save path (options.php)
	// and the three-section layout are both pinned.
	$registered_option = null;
	$registered_args = null;
	Functions\when( 'register_setting' )->alias( static function ( $group, $option, $args ) use ( &$registered_option, &$registered_args ): void {
		$registered_option = [ $group, $option ];
		$registered_args = $args;
	} );

	$sections = [];
	Functions\when( 'add_settings_section' )->alias( static function ( $id, ...$rest ) use ( &$sections ): void {
		$sections[] = $id;
	} );

	$fields = [];
	Functions\when( 'add_settings_field' )->alias( static function ( $id, ...$rest ) use ( &$fields ): void {
		$fields[] = $id;
	} );

	make_settings_page()->register_settings();

	expect( $registered_option )->toBe( [ 'kntnt_autolink', 'kntnt_autolink_settings' ] );
	expect( $registered_args['sanitize_callback'] )->toBeInstanceOf( Closure::class );
	expect( $sections )->toHaveCount( 3 );

	// Every documented field must be wired so the page renders and saves it.
	expect( $fields )->toContain( 'post_types' );
	expect( $fields )->toContain( 'link_class' );
	expect( $fields )->toContain( 'max_links_per_post' );
	expect( $fields )->toContain( 'deny_tags' );
	expect( $fields )->toContain( 'skip_class' );
	expect( $fields )->toContain( 'deny_xpath' );
	expect( $fields )->toContain( 'allow_only_xpath' );

	// nofollow / new-tab are per-group now and must never reappear as global fields.
	expect( $fields )->not->toContain( 'nofollow' );
	expect( $fields )->not->toContain( 'new_tab' );
} );

it( 'sanitize delegates to the repository, rejecting unregistered post types', function (): void {
	stub_settings_sanitisers();

	$result = make_settings_page()->sanitize( [ 'post_types' => [ 'post', 'bogus_type' ], 'max_links_per_post' => '0' ] );

	expect( $result['post_types'] )->toBe( [ 'post' ] );
	expect( $result['max_links_per_post'] )->toBe( 1 );
} );

it( 'sanitize tolerates a non-array submission', function (): void {
	stub_settings_sanitisers();
	expect( make_settings_page()->sanitize( 'tampered' ) )->toBeArray();
} );

it( 'renders the form against options.php through the Settings API', function (): void {
	stub_settings_page_i18n();
	Functions\when( 'current_user_can' )->justReturn( true );
	Functions\when( 'get_admin_page_title' )->justReturn( 'Autolink' );
	Functions\when( 'settings_fields' )->alias( static fn ( $group ) => print( "[settings_fields:{$group}]" ) );
	Functions\when( 'do_settings_sections' )->alias( static fn ( $page ) => print( "[do_settings_sections:{$page}]" ) );
	Functions\when( 'submit_button' )->alias( static fn () => print( '[submit_button]' ) );

	ob_start();
	make_settings_page()->render();
	$html = (string) ob_get_clean();

	expect( $html )->toContain( 'action="options.php"' );
	expect( $html )->toContain( '[settings_fields:kntnt_autolink]' );
	expect( $html )->toContain( '[do_settings_sections:kntnt-autolink]' );
	expect( $html )->toContain( '[submit_button]' );
} );

it( 'refuses to render without manage_options', function (): void {
	stub_settings_page_i18n();
	Functions\when( 'current_user_can' )->justReturn( false );
	Functions\when( 'wp_die' )->alias( static fn ( ...$args ) => throw new RuntimeException( 'wp_die' ) );
	expect( fn () => make_settings_page()->render() )->toThrow( RuntimeException::class );
} );

it( 'renders post types as a closed-list chip field that degrades to a named text field', function (): void {
	stub_settings_page_i18n();
	Functions\when( 'get_option' )->justReturn( false );
	stub_public_post_types( [ 'post' => 'Posts', 'page' => 'Pages', 'attachment' => 'Media' ] );

	ob_start();
	make_settings_page()->render_post_types_field();
	$html = (string) ob_get_clean();

	// The chip widget is a closed selector carrying the available options for JS.
	expect( $html )->toContain( 'data-kntnt-autolink-chips' );
	expect( $html )->toContain( 'data-mode="closed"' );
	expect( $html )->toContain( 'kntnt-autolink-chips__options' );
	expect( $html )->toContain( 'Posts' );

	// Without JS the same field is a textarea posting under the option key, so the
	// Settings API still saves it; the sanitiser then drops anything unregistered.
	expect( $html )->toContain( 'name="kntnt_autolink_settings[post_types]"' );

	// Every field carries a grey help line.
	expect( $html )->toContain( 'class="description"' );
} );

it( 'renders deny tags as a free-text chip field prefilled with the defaults', function (): void {
	stub_settings_page_i18n();
	Functions\when( 'get_option' )->justReturn( false );
	stub_public_post_types( [ 'post' => 'Posts', 'page' => 'Pages' ] );

	ob_start();
	make_settings_page()->render_deny_tags_field();
	$html = (string) ob_get_clean();

	expect( $html )->toContain( 'data-kntnt-autolink-chips' );
	expect( $html )->toContain( 'data-mode="free"' );

	// The no-JS textarea posts under the option key and is prefilled with the
	// h1–h6, a, code, pre, script, style defaults.
	expect( $html )->toContain( 'name="kntnt_autolink_settings[deny_tags]"' );
	expect( $html )->toContain( 'h1' );
	expect( $html )->toContain( 'style' );
	expect( $html )->toContain( 'class="description"' );
} );

it( 'enqueues the chip assets only on its own settings screen', function (): void {
	stub_settings_page_i18n();
	Functions\when( 'add_options_page' )->justReturn( 'settings_page_kntnt-autolink' );
	Functions\when( 'plugins_url' )->alias( static fn ( $path, $file ): string => 'http://example.test/' . $path );

	$styles = [];
	$scripts = [];
	Functions\when( 'wp_enqueue_style' )->alias( static function ( $handle, ...$rest ) use ( &$styles ): void {
		$styles[] = $handle;
	} );
	Functions\when( 'wp_enqueue_script' )->alias( static function ( $handle, ...$rest ) use ( &$scripts ): void {
		$scripts[] = $handle;
	} );

	$page = make_settings_page();
	$page->add_page();

	$page->enqueue( 'some-other-screen' );
	expect( $styles )->toBe( [] );
	expect( $scripts )->toBe( [] );

	$page->enqueue( 'settings_page_kntnt-autolink' );
	expect( $styles )->toContain( 'kntnt-autolink-chips' );
	expect( $scripts )->toContain( 'kntnt-autolink-chips' );
} );
