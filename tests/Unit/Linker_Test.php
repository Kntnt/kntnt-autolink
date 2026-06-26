<?php

declare( strict_types = 1 );

use Kntnt\Autolink\Link_Group;
use Kntnt\Autolink\Linker;
use Kntnt\Autolink\Ruleset;

/**
 * Terse link-group constructor for the behaviour matrix.
 *
 * @param list<string> $phrases
 */
function lg( array $phrases, string $url = 'https://example.com/', int $cap = 1, bool $nofollow = false, bool $new_tab = false ): Link_Group {
	return new Link_Group( id: $phrases[0] ?? 'g', phrases: $phrases, url: $url, cap: $cap, nofollow: $nofollow, new_tab: $new_tab );
}

/**
 * Default Ruleset for the matrix, with per-case named overrides.
 *
 * @param array<string, mixed> $overrides
 */
function link_rules( array $overrides = [] ): Ruleset {
	$defaults = [
		'deny_tags' => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'code', 'pre', 'script', 'style' ],
		'skip_class' => 'no-autolink',
		'deny_xpath' => null,
		'allow_only_xpath' => null,
		'link_class' => 'kntnt-autolink',
		'max_links_per_post' => 10,
	];
	$a = [ ...$defaults, ...$overrides ];
	return new Ruleset(
		deny_tags: $a['deny_tags'],
		skip_class: $a['skip_class'],
		deny_xpath: $a['deny_xpath'],
		allow_only_xpath: $a['allow_only_xpath'],
		link_class: $a['link_class'],
		max_links_per_post: $a['max_links_per_post'],
	);
}

// 1. Happy path.
it( 'links a simple phrase occurrence and preserves the wrapper', function (): void {
	$out = ( new Linker() )->link( '<p>I love cats.</p>', [ lg( [ 'cats' ] ) ], link_rules() );
	expect( $out )->toContain( '<a class="kntnt-autolink" href="https://example.com/">cats</a>' );
	expect( $out )->toContain( '<p>I love ' );
	expect( $out )->toContain( '.</p>' );
} );

// 2. Word boundary — no partial match (headline correctness case).
it( 'does not link a phrase inside a longer word', function (): void {
	$html = '<p>Visit the homepage today.</p>';
	$out = ( new Linker() )->link( $html, [ lg( [ 'page' ] ) ], link_rules() );
	expect( $out )->not->toContain( '<a' );
	expect( $out )->toBe( $html );
} );

// 3. Case-insensitive, first occurrence only, matched casing preserved.
it( 'matches case-insensitively and preserves the matched casing', function (): void {
	$out = ( new Linker() )->link( '<p>A CAT and a cat.</p>', [ lg( [ 'Cat' ] ) ], link_rules() );
	expect( $out )->toContain( '>CAT</a>' );
	expect( substr_count( $out, '<a ' ) )->toBe( 1 );
	expect( $out )->toContain( ' and a cat.' );
} );

// 4. Skip inside headings via the ancestor walk.
it( 'skips a phrase inside a heading reached through a nested element', function (): void {
	$out = ( new Linker() )->link( '<h2>A <em>cat</em></h2><p>a cat</p>', [ lg( [ 'cat' ] ) ], link_rules() );
	expect( $out )->toContain( '<em>cat</em>' );
	expect( $out )->toContain( '<a class="kntnt-autolink" href="https://example.com/">cat</a>' );
	expect( substr_count( $out, '<a ' ) )->toBe( 1 );
} );

// 5. The .no-autolink class, and class-token safety.
it( 'skips inside .no-autolink but not inside a class that merely starts with it', function (): void {
	$skipped = ( new Linker() )->link( '<p class="no-autolink">cat</p><p>cat</p>', [ lg( [ 'cat' ] ) ], link_rules() );
	expect( substr_count( $skipped, '<a ' ) )->toBe( 1 );
	expect( $skipped )->toContain( '<p class="no-autolink">cat</p>' );

	$linked = ( new Linker() )->link( '<p class="no-autolink-foo">cat</p>', [ lg( [ 'cat' ] ) ], link_rules() );
	expect( $linked )->toContain( '<a class="kntnt-autolink" href="https://example.com/">cat</a>' );
} );

// 6. Allow-only XPath (include-only).
it( 'links only within the allow-only subtree', function (): void {
	$out = ( new Linker() )->link( '<aside>cat</aside><main>cat</main>', [ lg( [ 'cat' ] ) ], link_rules( [ 'allow_only_xpath' => '//main' ] ) );
	expect( $out )->toContain( '<aside>cat</aside>' );
	expect( $out )->toContain( '<main><a class="kntnt-autolink" href="https://example.com/">cat</a></main>' );
	expect( substr_count( $out, '<a ' ) )->toBe( 1 );
} );

// 7. Longest-first across phrases of the same and different groups.
it( 'prefers the longest matching phrase and does not re-link its parts', function (): void {
	$out = ( new Linker() )->link( '<p>machine learning rocks</p>', [ lg( [ 'machine learning' ] ), lg( [ 'learning' ] ) ], link_rules() );
	expect( $out )->toContain( '>machine learning</a>' );
	expect( substr_count( $out, '<a ' ) )->toBe( 1 );
} );

// 8. A group with multiple equal phrases links any of them, sharing one budget.
it( 'links any phrase of a group and shares one group-cap budget', function (): void {
	$once = ( new Linker() )->link( '<p>cat then dog then cat</p>', [ lg( [ 'cat', 'dog' ] ) ], link_rules() );
	expect( substr_count( $once, '<a ' ) )->toBe( 1 );
	expect( $once )->toContain( '>cat</a>' );

	$twice = ( new Linker() )->link( '<p>cat then dog then cat</p>', [ lg( [ 'cat', 'dog' ], cap: 2 ) ], link_rules() );
	expect( substr_count( $twice, '<a ' ) )->toBe( 2 );
	expect( $twice )->toContain( '>cat</a>' );
	expect( $twice )->toContain( '>dog</a>' );
} );

// 9. Global cap, honouring document order.
it( 'stops at the global per-post cap in document order', function (): void {
	$out = ( new Linker() )->link(
		'<p>a b c</p>',
		[ lg( [ 'a' ], cap: 5 ), lg( [ 'b' ], cap: 5 ), lg( [ 'c' ], cap: 5 ) ],
		link_rules( [ 'max_links_per_post' => 2 ] ),
	);
	expect( substr_count( $out, '<a ' ) )->toBe( 2 );
	expect( $out )->toContain( '>a</a>' );
	expect( $out )->toContain( '>b</a>' );
	expect( $out )->not->toContain( '>c</a>' );
} );

// 10. Per-group nofollow / new-tab policy, applied only to that group's links.
it( 'applies each group own nofollow and new-tab policy when building its links', function (): void {
	$out = ( new Linker() )->link(
		'<p>cat dog</p>',
		[ lg( [ 'cat' ], url: 'https://example.com/c', nofollow: true ), lg( [ 'dog' ], url: 'https://example.com/d', new_tab: true ) ],
		link_rules(),
	);
	expect( $out )->toContain( '<a class="kntnt-autolink" rel="nofollow" href="https://example.com/c">cat</a>' );
	expect( $out )->toContain( '<a class="kntnt-autolink" rel="noopener" target="_blank" href="https://example.com/d">dog</a>' );
} );

// 11. UTF-8 integrity.
it( 'preserves multibyte characters without corruption', function (): void {
	$out = ( new Linker() )->link( '<p>Ett kafé och en kaffé.</p>', [ lg( [ 'kaffé' ] ) ], link_rules() );
	expect( $out )->toContain( 'kafé' );
	expect( $out )->toContain( '>kaffé</a>' );
	expect( $out )->not->toContain( '&' );

	$emoji = ( new Linker() )->link( '<p>Skåne 🌍 cat åäö</p>', [ lg( [ 'cat' ] ) ], link_rules() );
	expect( $emoji )->toContain( 'Skåne 🌍 ' );
	expect( $emoji )->toContain( 'åäö' );
	expect( $emoji )->toContain( '>cat</a>' );
} );

// 12. Idempotence / no nested links.
it( 'never links inside a link it already made', function (): void {
	$linker = new Linker();
	$once = $linker->link( '<p>cat</p>', [ lg( [ 'cat' ] ) ], link_rules() );
	$twice = $linker->link( $once, [ lg( [ 'cat' ] ) ], link_rules() );
	expect( $twice )->toBe( $once );
	expect( substr_count( $twice, '<a ' ) )->toBe( 1 );
} );

// 13. Pre-check short-circuit.
it( 'returns the input unchanged when no phrase is present', function (): void {
	$html = '<p>no match here</p>';
	expect( ( new Linker() )->link( $html, [ lg( [ 'zebra' ] ) ], link_rules() ) )->toBe( $html );
} );

// 14. Attribute filter callback and context.
it( 'passes generated attributes and context through the filter callback', function (): void {
	$filter = static fn ( array $attrs, array $context ): array => [
		...$attrs,
		'data-id' => $context['group_id'],
		'data-matched' => $context['matched_text'],
		'data-url' => $context['url'],
	];
	$out = ( new Linker() )->link( '<p>CAT</p>', [ lg( [ 'cat' ] ) ], link_rules(), $filter );
	expect( $out )->toContain( 'data-id="cat"' );
	expect( $out )->toContain( 'data-matched="CAT"' );
	expect( $out )->toContain( 'data-url="https://example.com/"' );
} );

// 15. Empty group set.
it( 'returns the input unchanged when there are no link groups', function (): void {
	$html = '<p>cat</p>';
	expect( ( new Linker() )->link( $html, [], link_rules() ) )->toBe( $html );
} );
