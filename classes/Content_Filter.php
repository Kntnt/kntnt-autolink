<?php
/**
 * Bridges the_content to the pure Linker: targeting, should_run, Ruleset
 * assembly via the public filters, and the per-match attribute filter seam.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Content_Filter {

	/**
	 * @since 1.0.0
	 */
	public function __construct(
		private readonly Settings_Repository $settings,
		private readonly Keyword_Repository $keywords,
		private readonly Linker $linker,
	) {}

	/**
	 * Register the the_content filter at the configurable priority.
	 *
	 * @since 1.0.0
	 */
	public function register_hooks(): void {
		$priority = apply_filters( 'kntnt_autolink_content_priority', 20 );
		add_filter( 'the_content', $this->filter_content( ... ), is_numeric( $priority ) ? (int) $priority : 20 );
	}

	/**
	 * Link eligible keyword occurrences in post content, or pass it through
	 * untouched when out of scope or short-circuited.
	 *
	 * @since 1.0.0
	 */
	public function filter_content( string $content ): string {

		// Resolve the current post; bail when there is none (e.g. a feed of terms).
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		// Targeting: only run on configured post types (and terms, when set).
		if ( ! $this->is_in_scope( $post ) ) {
			return $content;
		}

		// Per-request short-circuit.
		if ( ! apply_filters( 'kntnt_autolink_should_run', true, $post ) ) {
			return $content;
		}

		// Keyword set, filterable for config-as-code / overrides.
		$filtered = apply_filters( 'kntnt_autolink_keywords', $this->keywords->all() );
		if ( ! is_array( $filtered ) ) {
			return $content;
		}
		$keywords = array_values( array_filter( $filtered, static fn ( mixed $keyword ): bool => $keyword instanceof Keyword ) );
		if ( $keywords === [] ) {
			return $content;
		}

		// Assemble the Ruleset from settings, then apply the per-context filters.
		$rules = $this->build_ruleset( $post );

		// Hand off to the pure engine, exposing the attribute filter through its callback.
		return $this->linker->link(
			$content,
			$keywords,
			$rules,
			static function ( array $attributes, array $context ): array {
				$result = apply_filters( 'kntnt_autolink_link_attributes', $attributes, $context );
				return is_array( $result ) ? $result : $attributes;
			},
		);

	}

	/**
	 * Whether the linker should run on this post: its type is configured, and —
	 * when term targeting is set — it carries a matching term.
	 *
	 * @since 1.0.0
	 */
	private function is_in_scope( \WP_Post $post ): bool {
		if ( ! in_array( $post->post_type, $this->settings->get_post_types(), true ) ) {
			return false;
		}
		$terms = $this->settings->get_terms();
		if ( $terms === [] ) {
			return true;
		}
		foreach ( $terms as $taxonomy => $term_ids ) {
			if ( has_term( $term_ids, $taxonomy, $post ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the Ruleset for this post: the settings ruleset with the deny and
	 * allow-only filters applied on top.
	 *
	 * @since 1.0.0
	 */
	private function build_ruleset( \WP_Post $post ): Ruleset {

		$base = $this->settings->get_ruleset();
		$deny = apply_filters( 'kntnt_autolink_deny', [ 'tags' => $base->deny_tags, 'xpath' => $base->deny_xpath ], $post );
		$allow_only = apply_filters( 'kntnt_autolink_allow_only', $base->allow_only_xpath ?? '', $post );

		// Start from the settings values; let the deny filter override tags/xpath.
		$tags = $base->deny_tags;
		$deny_xpath = $base->deny_xpath;
		if ( is_array( $deny ) ) {
			if ( isset( $deny['tags'] ) ) {
				$tags = $this->string_list( $deny['tags'] );
			}
			if ( array_key_exists( 'xpath', $deny ) ) {
				$candidate = $deny['xpath'];
				$deny_xpath = is_string( $candidate ) && $candidate !== '' ? $candidate : null;
			}
		}

		$allow_only_xpath = is_string( $allow_only ) && $allow_only !== '' ? $allow_only : null;

		return new Ruleset(
			deny_tags: $tags,
			skip_class: $base->skip_class,
			deny_xpath: $deny_xpath,
			allow_only_xpath: $allow_only_xpath,
			link_class: $base->link_class,
			nofollow: $base->nofollow,
			new_tab: $base->new_tab,
			max_links_per_post: $base->max_links_per_post,
		);

	}

	/**
	 * Coerce a value into a list of non-empty strings.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	private function string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$result = [];
		foreach ( $value as $item ) {
			if ( is_string( $item ) && $item !== '' ) {
				$result[] = $item;
			}
		}
		return $result;
	}

}
