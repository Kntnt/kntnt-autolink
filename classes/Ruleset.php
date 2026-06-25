<?php
/**
 * Where links may go. Compiles a friendly deny-tag list, a skip class, and two
 * raw-XPath escape hatches (deny and allow-only) into a single XPath selecting
 * the eligible text nodes, and builds the static <a> attributes.
 *
 * Pure value object — no WordPress calls.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final readonly class Ruleset {

	/**
	 * @since 1.0.0
	 *
	 * @param list<string> $deny_tags          Tags whose subtree is never linked.
	 * @param string       $skip_class         Class that silences an element and its descendants.
	 * @param string|null  $deny_xpath         Optional raw XPath; ancestors in its node-set are excluded.
	 * @param string|null  $allow_only_xpath   Optional raw XPath; when set, only its subtree is eligible.
	 * @param string       $link_class         Class applied to generated links.
	 * @param bool         $nofollow           Add rel="nofollow".
	 * @param bool         $new_tab            Open in a new tab (target=_blank, rel adds noopener).
	 * @param int          $max_links_per_post Global cap on total links per post.
	 */
	public function __construct(
		public array $deny_tags,
		public string $skip_class,
		public ?string $deny_xpath,
		public ?string $allow_only_xpath,
		public string $link_class,
		public bool $nofollow,
		public bool $new_tab,
		public int $max_links_per_post,
	) {}

	/**
	 * The XPath selecting eligible text nodes (design §4).
	 *
	 * Candidate set is the whole document, or the allow-only subtree when set;
	 * the deny predicate then removes text nodes whose ancestor-or-self is a
	 * denied tag, carries the skip class, or is in the deny-XPath node-set.
	 *
	 * @since 1.0.0
	 */
	public function eligible_text_nodes_query(): string {

		// Candidate text nodes: whole document, or restricted to the allow-only subtree.
		$candidate = $this->allow_only_xpath !== null
			? '(' . $this->allow_only_xpath . ')//text()'
			: '//text()';

		// Build the exclusion clauses; only non-empty ones join with " and ".
		$clauses = [];

		if ( $this->deny_tags !== [] ) {
			$tags = implode( ' or ', array_map(
				static fn ( string $tag ): string => 'ancestor-or-self::' . $tag,
				$this->deny_tags,
			) );
			$clauses[] = 'not(' . $tags . ')';
		}

		$clauses[] = "not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' " . $this->skip_class . " ')])";

		if ( $this->deny_xpath !== null ) {
			$clauses[] = 'not(ancestor-or-self::*[count(. | (' . $this->deny_xpath . ')) = count((' . $this->deny_xpath . '))])';
		}

		return $candidate . '[' . implode( ' and ', $clauses ) . ']';

	}

	/**
	 * The static attributes for a generated <a>, before href and the per-match
	 * attribute filter are applied (Linker / Content_Filter).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public function link_attributes(): array {

		// Class is always present; rel/target depend on the link policy.
		$attributes = [ 'class' => $this->link_class ];

		$rel = [];
		if ( $this->nofollow ) {
			$rel[] = 'nofollow';
		}
		if ( $this->new_tab ) {
			$rel[] = 'noopener';
		}
		if ( $rel !== [] ) {
			$attributes['rel'] = implode( ' ', $rel );
		}
		if ( $this->new_tab ) {
			$attributes['target'] = '_blank';
		}

		return $attributes;

	}

}
