<?php
/**
 * The pure autolinking engine. Given HTML, a set of link groups, and a Ruleset,
 * returns the HTML with the first eligible occurrences linked. Makes no
 * WordPress calls, so it is fully unit-testable in isolation. Each group's own
 * nofollow / new-tab policy is applied when building that group's links.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Linker {

	/**
	 * Links eligible phrase occurrences in the given HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param string           $html             Content HTML (a fragment, not a full document).
	 * @param list<Link_Group> $groups           Link groups to link.
	 * @param Ruleset          $rules            Eligibility rules and global link attributes.
	 * @param callable|null    $attribute_filter fn( array $attrs, array $context ): array, applied per link.
	 * @return string The linked HTML, or the input unchanged when nothing matches.
	 */
	public function link( string $html, array $groups, Ruleset $rules, ?callable $attribute_filter = null ): string {

		// Cheap pre-check: bail before any DOM work when no phrase is present.
		$phrases = [];
		foreach ( $groups as $group ) {
			$phrases = [ ...$phrases, ...$group->phrases ];
		}
		if ( $phrases === [] || ! $this->contains_any( $html, $phrases ) ) {
			return $html;
		}

		// Parse the fragment into a DOM without libxml injecting html/body/doctype.
		// The XML encoding prefix preserves UTF-8 on serialisation.
		$dom = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . '<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		// Locate the wrapper div (first element child; an XML PI may precede it).
		$container = null;
		foreach ( $dom->childNodes as $node ) {
			if ( $node instanceof \DOMElement ) {
				$container = $node;
				break;
			}
		}
		if ( $container === null ) {
			return $html;
		}

		// Collect eligible text nodes into a plain list before mutating the tree
		// (a live DOMNodeList must not be iterated while the tree changes).
		$xpath = new \DOMXPath( $dom );
		$found = $xpath->query( $rules->eligible_text_nodes_query() );
		$candidates = [];
		if ( $found !== false ) {
			foreach ( $found as $candidate ) {
				if ( $candidate instanceof \DOMText ) {
					$candidates[] = $candidate;
				}
			}
		}

		// Insert links across the collected text nodes, in document order.
		$this->insert_links( $dom, $candidates, $groups, $rules, $attribute_filter );

		// Serialise the container's children back to an HTML fragment.
		$out = '';
		foreach ( $container->childNodes as $child ) {
			$serialised = $dom->saveHTML( $child );
			if ( $serialised !== false ) {
				$out .= $serialised;
			}
		}
		return $out;

	}

	/**
	 * True when any of the phrases appears in the haystack, case-insensitively.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $phrases
	 */
	private function contains_any( string $haystack, array $phrases ): bool {
		foreach ( $phrases as $phrase ) {
			if ( $phrase !== '' && stripos( $haystack, $phrase ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Walks the candidate text nodes and inserts anchors, respecting longest-first
	 * ordering, each group cap, and the global per-post cap.
	 *
	 * @since 1.0.0
	 *
	 * @param list<\DOMText>   $candidates
	 * @param list<Link_Group> $groups
	 */
	private function insert_links( \DOMDocument $dom, array $candidates, array $groups, Ruleset $rules, ?callable $attribute_filter ): void {

		// Precompute each group's match set (immutable): non-empty phrases sorted
		// longest-first, the per-group static attributes (global class plus the
		// group's own policy), and the group ordered by their longest phrase.
		$base_attributes = $rules->link_attributes();
		$prepared = [];
		foreach ( $groups as $group ) {
			$phrases = array_values( array_filter( $group->phrases, static fn ( string $phrase ): bool => $phrase !== '' ) );
			if ( $phrases === [] ) {
				continue;
			}
			usort( $phrases, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
			$prepared[] = [
				'id' => $group->id,
				'url' => $group->url,
				'phrases' => $phrases,
				'cap' => $group->cap,
				'attributes' => [ ...$base_attributes, ...$group->link_attributes() ],
			];
		}
		usort( $prepared, static fn ( array $a, array $b ): int => strlen( $b['phrases'][0] ) <=> strlen( $a['phrases'][0] ) );

		// Per-group remaining capacity, kept parallel to $prepared by index so the
		// group shapes stay immutable while only the counters mutate.
		$remaining = [];
		foreach ( $prepared as $index => $group ) {
			$remaining[ $index ] = $group['cap'];
		}

		$total = 0;
		foreach ( $candidates as $node ) {
			if ( $total >= $rules->max_links_per_post ) {
				break;
			}
			// Skip nodes a prior operation may have detached from the tree.
			if ( $node->parentNode === null ) {
				continue;
			}
			$total = $this->process_text_node( $dom, $node, $prepared, $remaining, $rules, $attribute_filter, $total );
		}

	}

	/**
	 * Links every eligible match within a single text node, left to right.
	 *
	 * @since 1.0.0
	 *
	 * @param list<array{id: string, url: string, phrases: list<string>, cap: int, attributes: array<string, string>}> $groups
	 * @param array<int, int> $remaining
	 * @return int The running link total after this node.
	 */
	private function process_text_node( \DOMDocument $dom, \DOMText $node, array $groups, array &$remaining, Ruleset $rules, ?callable $attribute_filter, int $total ): int {

		$parent = $node->parentNode;
		if ( $parent === null ) {
			return $total;
		}

		// $current is the text node holding the not-yet-scanned remainder.
		$current = $node;
		$text = (string) $node->nodeValue;

		while ( $total < $rules->max_links_per_post ) {

			// Choose the earliest match across capable groups; longer wins a tie.
			$best = null;
			foreach ( $groups as $index => $group ) {
				if ( $remaining[ $index ] <= 0 ) {
					continue;
				}
				$match = $this->first_match( $text, $group['phrases'] );
				if ( $match === null ) {
					continue;
				}
				if ( $best === null
					|| $match['offset'] < $best['offset']
					|| ( $match['offset'] === $best['offset'] && $match['length'] > $best['length'] ) ) {
					$best = [
						'index' => $index,
						'group' => $group,
						'offset' => $match['offset'],
						'length' => $match['length'],
						'matched' => $match['matched'],
					];
				}
			}

			if ( $best === null ) {
				break;
			}

			// Split the current text into before / matched / after at the chosen match.
			$before = substr( $text, 0, $best['offset'] );
			$after = substr( $text, $best['offset'] + $best['length'] );
			$group = $best['group'];

			// Assemble the anchor attributes (group policy + href), then run the seam.
			$attributes = [ ...$group['attributes'], 'href' => $group['url'] ];
			if ( $attribute_filter !== null ) {
				/** @var array<string, string> $attributes */
				$attributes = $attribute_filter( $attributes, [
					'url' => $group['url'],
					'group_id' => $group['id'],
					'matched_text' => $best['matched'],
				] );
			}
			$anchor = $dom->createElement( 'a' );
			foreach ( $attributes as $name => $value ) {
				$anchor->setAttribute( (string) $name, (string) $value );
			}
			$anchor->appendChild( $dom->createTextNode( $best['matched'] ) );

			// Replace the current node with before-text, the anchor, and after-text.
			if ( $before !== '' ) {
				$parent->insertBefore( $dom->createTextNode( $before ), $current );
			}
			$parent->insertBefore( $anchor, $current );
			$after_node = $dom->createTextNode( $after );
			$parent->insertBefore( $after_node, $current );
			$parent->removeChild( $current );

			// Continue scanning the after-text only; never re-scan the new anchor.
			$current = $after_node;
			$text = $after;
			--$remaining[ $best['index'] ];
			++$total;

		}

		return $total;

	}

	/**
	 * The earliest literal match of any phrase in the text (longer wins a tie),
	 * using Unicode word boundaries so a phrase never matches inside a longer word.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $phrases
	 * @return array{offset: int, length: int, matched: string}|null
	 */
	private function first_match( string $text, array $phrases ): ?array {
		$best = null;
		foreach ( $phrases as $phrase ) {
			if ( $phrase === '' ) {
				continue;
			}
			$pattern = '/(?<!\p{L})' . preg_quote( $phrase, '/' ) . '(?!\p{L})/iu';
			if ( preg_match( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) === 1 ) {
				$offset = (int) $matches[0][1];
				$matched = (string) $matches[0][0];
				$length = strlen( $matched );
				if ( $best === null
					|| $offset < $best['offset']
					|| ( $offset === $best['offset'] && $length > $best['length'] ) ) {
					$best = [ 'offset' => $offset, 'length' => $length, 'matched' => $matched ];
				}
			}
		}
		return $best;
	}

}
