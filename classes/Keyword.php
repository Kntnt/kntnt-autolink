<?php
/**
 * A keyword group: one canonical base form plus equal-weight variant surface
 * forms, all linking to the same URL.
 *
 * Pure value object — no WordPress calls. Sanitisation of url/forms happens at
 * the repository/admin boundary (Settings_Repository / Keyword_Repository /
 * Tools_Page), never here.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final readonly class Keyword {

	/**
	 * @since 1.0.0
	 *
	 * @param string       $id       Stable identifier.
	 * @param string       $base     Canonical surface form (display/management).
	 * @param list<string> $variants Additional literal surface forms.
	 * @param string       $url      Destination all forms link to.
	 * @param int          $max      Per-keyword link cap. Default 1.
	 */
	public function __construct(
		public string $id,
		public string $base,
		public array $variants,
		public string $url,
		public int $max = 1,
	) {}

	/**
	 * All surface forms, base first, de-duplicated, preserving order.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function forms(): array {
		return array_values( array_unique( [ $this->base, ...$this->variants ] ) );
	}

}
