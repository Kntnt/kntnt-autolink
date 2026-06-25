<?php
/**
 * The pure autolinking engine. Given HTML, a keyword set, and a Ruleset, returns
 * the HTML with the first eligible occurrences linked. Makes no WordPress calls,
 * so it is fully unit-testable in isolation.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Linker {

	/**
	 * Links eligible keyword occurrences in the given HTML.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $html             Content HTML (a fragment, not a full document).
	 * @param list<Keyword> $keywords         Keyword groups to link.
	 * @param Ruleset       $rules            Eligibility rules and link policy.
	 * @param callable|null $attribute_filter fn( array $attrs, array $context ): array, applied per link.
	 * @return string The linked HTML, or the input unchanged when nothing matches.
	 */
	public function link( string $html, array $keywords, Ruleset $rules, ?callable $attribute_filter = null ): string {

		// Cheap pre-check: bail before any DOM work when no surface form is present.
		$forms = [];
		foreach ( $keywords as $keyword ) {
			$forms = [ ...$forms, ...$keyword->forms() ];
		}
		if ( $forms === [] || ! $this->contains_any( $html, $forms ) ) {
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
		$this->insert_links( $dom, $candidates, $keywords, $rules, $attribute_filter );

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
	 * True when any of the forms appears in the haystack, case-insensitively.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $forms
	 */
	private function contains_any( string $haystack, array $forms ): bool {
		foreach ( $forms as $form ) {
			if ( $form !== '' && stripos( $haystack, $form ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Walks the candidate text nodes and inserts anchors, respecting longest-first
	 * ordering, the per-keyword cap, and the global per-post cap.
	 *
	 * @since 1.0.0
	 *
	 * @param list<\DOMText> $candidates
	 * @param list<Keyword>  $keywords
	 */
	private function insert_links( \DOMDocument $dom, array $candidates, array $keywords, Ruleset $rules, ?callable $attribute_filter ): void {

		// Precompute keyword groups (immutable): each carries its forms
		// longest-first; the groups themselves are ordered longest-form-first.
		$groups = [];
		foreach ( $keywords as $keyword ) {
			$forms = $keyword->forms();
			usort( $forms, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
			$groups[] = [
				'id' => $keyword->id,
				'base' => $keyword->base,
				'url' => $keyword->url,
				'forms' => $forms,
				'max' => $keyword->max,
			];
		}
		usort( $groups, static fn ( array $a, array $b ): int => strlen( $b['forms'][0] ) <=> strlen( $a['forms'][0] ) );

		// Per-group remaining capacity, kept parallel to $groups by index so the
		// group shapes stay immutable while only the counters mutate.
		$remaining = [];
		foreach ( $groups as $index => $group ) {
			$remaining[ $index ] = $group['max'];
		}

		$static_attributes = $rules->link_attributes();
		$total = 0;

		foreach ( $candidates as $node ) {
			if ( $total >= $rules->max_links_per_post ) {
				break;
			}
			// Skip nodes a prior operation may have detached from the tree.
			if ( $node->parentNode === null ) {
				continue;
			}
			$total = $this->process_text_node( $dom, $node, $groups, $remaining, $rules, $static_attributes, $attribute_filter, $total );
		}

	}

	/**
	 * Links every eligible match within a single text node, left to right.
	 *
	 * @since 1.0.0
	 *
	 * @param list<array{id: string, base: string, url: string, forms: list<string>, max: int}> $groups
	 * @param array<int, int>        $remaining
	 * @param array<string, string>  $static_attributes
	 * @return int The running link total after this node.
	 */
	private function process_text_node( \DOMDocument $dom, \DOMText $node, array $groups, array &$remaining, Ruleset $rules, array $static_attributes, ?callable $attribute_filter, int $total ): int {

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
				$match = $this->first_match( $text, $group['forms'] );
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

			// Assemble the anchor attributes, then run the per-match filter seam.
			$attributes = [ ...$static_attributes, 'href' => $group['url'] ];
			if ( $attribute_filter !== null ) {
				/** @var array<string, string> $attributes */
				$attributes = $attribute_filter( $attributes, [
					'url' => $group['url'],
					'keyword_id' => $group['id'],
					'base' => $group['base'],
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
	 * The earliest literal match of any form in the text (longer wins a tie),
	 * using Unicode word boundaries so a form never matches inside a longer word.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $forms
	 * @return array{offset: int, length: int, matched: string}|null
	 */
	private function first_match( string $text, array $forms ): ?array {
		$best = null;
		foreach ( $forms as $form ) {
			if ( $form === '' ) {
				continue;
			}
			$pattern = '/(?<!\p{L})' . preg_quote( $form, '/' ) . '(?!\p{L})/iu';
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
