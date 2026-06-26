<?php
/**
 * A link group: a set of equal, interchangeable phrases that all link to one
 * URL, share one group cap, and carry their own link behaviour (nofollow and
 * new-tab). It has no canonical member — every phrase is a peer — and a
 * surrogate id independent of its phrases.
 *
 * Pure value object — no WordPress calls. Sanitisation of url/phrases happens at
 * the repository/REST boundary (Link_Group_Repository / Rest_Controller), never
 * here.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final readonly class Link_Group {

	/**
	 * @since 1.1.0
	 *
	 * @param string       $id       Surrogate identifier, independent of the phrases.
	 * @param list<string> $phrases  Equal, interchangeable literal surface forms.
	 * @param string       $url      Destination all phrases link to.
	 * @param int          $cap      Group cap: links this group may make per post. Default 1.
	 * @param bool         $nofollow Whether this group's links carry rel="nofollow".
	 * @param bool         $new_tab  Whether this group's links open in a new tab.
	 */
	public function __construct(
		public string $id,
		public array $phrases,
		public string $url,
		public int $cap = 1,
		public bool $nofollow = false,
		public bool $new_tab = false,
	) {}

	/**
	 * This group's own link-policy attributes (rel/target), independent of the
	 * global link class. Empty when the group neither nofollows nor opens a new tab.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, string>
	 */
	public function link_attributes(): array {

		// rel collects nofollow and noopener; target marks a new tab.
		$attributes = [];
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
