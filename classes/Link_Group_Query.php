<?php
/**
 * The list query over the in-memory link groups: a normalised search term,
 * sort column and direction, and a page, applied to a list of Link_Group value
 * objects. It searches by phrase or URL, sorts by first phrase or group cap,
 * and pages the result while still reporting the full match count so a caller
 * can build pagination over the whole result rather than the current page.
 *
 * Pure value object — no WordPress calls. The admin and REST boundaries read
 * and sanitise the raw request, then hand normalised values here; the per-page
 * size is resolved at those boundaries through the PER_PAGE_FILTER hook.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final readonly class Link_Group_Query {

	/** @since 1.1.0 */
	public const int DEFAULT_PER_PAGE = 20;

	/** @since 1.1.0 */
	public const string PER_PAGE_FILTER = 'kntnt_autolink_per_page';

	/** @since 1.1.0 */
	public string $search;

	/** @since 1.1.0 */
	public string $orderby;

	/** @since 1.1.0 */
	public string $order;

	/** @since 1.1.0 */
	public int $page;

	/** @since 1.1.0 */
	public int $per_page;

	/**
	 * Normalise the raw query into a closed, safe set of values: the only sort
	 * columns are "phrases" and "cap", the only directions "asc" and "desc", and
	 * the page and per-page are at least one. A search term keeps its surrounding
	 * trimmed so a stray space never hides every group.
	 *
	 * @since 1.1.0
	 *
	 * @param string $orderby  Either "phrases" (the default) or "cap".
	 * @param string $order    Either "asc" (the default) or "desc".
	 * @param int    $page     One-based page number; clamped to at least 1.
	 * @param int    $per_page Page size; clamped to at least 1.
	 */
	public function __construct(
		string $search = '',
		string $orderby = 'phrases',
		string $order = 'asc',
		int $page = 1,
		int $per_page = self::DEFAULT_PER_PAGE,
	) {
		$this->search = trim( $search );
		$this->orderby = $orderby === 'cap' ? 'cap' : 'phrases';
		$this->order = strtolower( $order ) === 'desc' ? 'desc' : 'asc';
		$this->page = max( 1, $page );
		$this->per_page = max( 1, $per_page );
	}

	/**
	 * Search, sort and page the given groups.
	 *
	 * @since 1.1.0
	 *
	 * @param list<Link_Group> $groups The full set of groups to query over.
	 * @return array{items: list<Link_Group>, total: int} The current page of
	 *         groups and the total number that matched the search.
	 */
	public function results( array $groups ): array {

		// Keep only the groups the search matches, then order the survivors by the
		// chosen column and direction.
		$matched = array_values( array_filter( $groups, $this->matches( ... ) ) );
		usort( $matched, $this->compare( ... ) );

		// Slice out the requested page, but report the full match count so the
		// caller can paginate over the whole result rather than this page alone.
		$total = count( $matched );
		$items = array_slice( $matched, ( $this->page - 1 ) * $this->per_page, $this->per_page );

		return [ 'items' => $items, 'total' => $total ];

	}

	/**
	 * Whether a group matches the search: an empty search matches every group,
	 * otherwise the term must appear, case-insensitively, in the URL or in one of
	 * the phrases.
	 *
	 * @since 1.1.0
	 */
	private function matches( Link_Group $group ): bool {

		if ( $this->search === '' ) {
			return true;
		}

		// The URL and every phrase are equal search targets — a group is a hit on
		// the first of them that contains the term.
		if ( mb_stripos( $group->url, $this->search ) !== false ) {
			return true;
		}
		foreach ( $group->phrases as $phrase ) {
			if ( mb_stripos( $phrase, $this->search ) !== false ) {
				return true;
			}
		}

		return false;

	}

	/**
	 * Compare two groups by the active sort column and direction. Phrases sort by
	 * the first phrase, case-insensitively; group cap sorts numerically.
	 *
	 * @since 1.1.0
	 */
	private function compare( Link_Group $a, Link_Group $b ): int {
		$direction = $this->order === 'desc' ? -1 : 1;
		$ordering = $this->orderby === 'cap'
			? $a->cap <=> $b->cap
			: strcmp( $this->sort_key( $a ), $this->sort_key( $b ) );
		return $direction * $ordering;
	}

	/**
	 * The case-folded first phrase a group sorts under; empty when it has none.
	 *
	 * @since 1.1.0
	 */
	private function sort_key( Link_Group $group ): string {
		return mb_strtolower( $group->phrases[0] ?? '' );
	}

}
