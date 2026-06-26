<?php

declare( strict_types = 1 );

use Kntnt\Autolink\Ruleset;

// The default deny-tag clause, written as a literal so the test pins the
// contract independently of the production code.
const DENY_TAGS_CLAUSE = 'not(ancestor-or-self::h1 or ancestor-or-self::h2 or ancestor-or-self::h3 or ancestor-or-self::h4 or ancestor-or-self::h5 or ancestor-or-self::h6 or ancestor-or-self::a or ancestor-or-self::code or ancestor-or-self::pre or ancestor-or-self::script or ancestor-or-self::style)';

const DEFAULT_DENY_TAGS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'code', 'pre', 'script', 'style' ];

/**
 * Build a Ruleset from the design defaults, overriding named arguments per case.
 *
 * @param array<string, mixed> $overrides
 */
function rules( array $overrides = [] ): Ruleset {
	$defaults = [
		'deny_tags' => DEFAULT_DENY_TAGS,
		'skip_class' => 'no-autolink',
		'deny_xpath' => null,
		'allow_only_xpath' => null,
		'link_class' => 'kntnt-autolink',
		'max_links_per_post' => 10,
	];
	$args = [ ...$defaults, ...$overrides ];
	return new Ruleset(
		deny_tags: $args['deny_tags'],
		skip_class: $args['skip_class'],
		deny_xpath: $args['deny_xpath'],
		allow_only_xpath: $args['allow_only_xpath'],
		link_class: $args['link_class'],
		max_links_per_post: $args['max_links_per_post'],
	);
}

it( 'compiles the default eligibility query (no allow-only, no deny-xpath)', function (): void {
	$expected = "//text()[" . DENY_TAGS_CLAUSE . " and not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' no-autolink ')])]";
	expect( rules()->eligible_text_nodes_query() )->toBe( $expected );
} );

it( 'restricts the candidate set when allow_only_xpath is set', function (): void {
	$expected = "(//main)//text()[" . DENY_TAGS_CLAUSE . " and not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' no-autolink ')])]";
	expect( rules( [ 'allow_only_xpath' => '//main' ] )->eligible_text_nodes_query() )->toBe( $expected );
} );

it( 'appends a set-membership clause when deny_xpath is set', function (): void {
	$expected = "//text()[" . DENY_TAGS_CLAUSE . " and not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' no-autolink ')]) and not(ancestor-or-self::*[count(. | (//figure)) = count((//figure))])]";
	expect( rules( [ 'deny_xpath' => '//figure' ] )->eligible_text_nodes_query() )->toBe( $expected );
} );

it( 'uses the configured skip class in the predicate', function (): void {
	$expected = "//text()[" . DENY_TAGS_CLAUSE . " and not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' noindex ')])]";
	expect( rules( [ 'skip_class' => 'noindex' ] )->eligible_text_nodes_query() )->toBe( $expected );
} );

it( 'omits the deny-tag clause entirely when deny_tags is empty', function (): void {
	$expected = "//text()[not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' noindex ')])]";
	expect( rules( [ 'deny_tags' => [], 'skip_class' => 'noindex' ] )->eligible_text_nodes_query() )->toBe( $expected );
} );

it( 'builds the global link attributes with only the link class', function (): void {
	expect( rules()->link_attributes() )->toBe( [ 'class' => 'kntnt-autolink' ] );
} );

it( 'reflects a custom link class in the link attributes', function (): void {
	expect( rules( [ 'link_class' => 'my-link' ] )->link_attributes() )->toBe( [ 'class' => 'my-link' ] );
} );
