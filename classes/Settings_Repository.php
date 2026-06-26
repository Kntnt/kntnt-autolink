<?php
/**
 * Reads and writes the plugin settings option, hydrating it into a Ruleset and
 * exposing the targeting fields. The only code that touches
 * kntnt_autolink_settings. The link policy (nofollow / new tab) is no longer a
 * global setting; it lives on each Link_Group.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Settings_Repository {

	/** @since 1.0.0 */
	private const OPTION = 'kntnt_autolink_settings';

	/**
	 * @since 1.0.0
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = [
		'deny_tags' => [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'a', 'code', 'pre', 'script', 'style' ],
		'skip_class' => 'no-autolink',
		'deny_xpath' => '',
		'allow_only_xpath' => '',
		'link_class' => 'kntnt-autolink',
		'max_links_per_post' => 10,
		'post_types' => [ 'post', 'page' ],
		'terms' => [],
	];

	/**
	 * Settings with the stored values merged over the defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			return self::DEFAULTS;
		}
		$merged = self::DEFAULTS;
		foreach ( $stored as $key => $value ) {
			$merged[ (string) $key ] = $value;
		}
		return $merged;
	}

	/**
	 * Hydrate a Ruleset from settings. Empty XPath strings become null.
	 *
	 * @since 1.0.0
	 */
	public function get_ruleset(): Ruleset {
		$s = $this->get_settings();
		$deny_xpath = trim( $this->to_string( $s['deny_xpath'] ) );
		$allow_only_xpath = trim( $this->to_string( $s['allow_only_xpath'] ) );
		return new Ruleset(
			deny_tags: $this->as_string_list( $s['deny_tags'] ),
			skip_class: $this->to_string( $s['skip_class'] ),
			deny_xpath: $deny_xpath === '' ? null : $deny_xpath,
			allow_only_xpath: $allow_only_xpath === '' ? null : $allow_only_xpath,
			link_class: $this->to_string( $s['link_class'] ),
			max_links_per_post: $this->to_int( $s['max_links_per_post'] ),
		);
	}

	/**
	 * The post types the linker runs on.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	public function get_post_types(): array {
		return $this->as_string_list( $this->get_settings()['post_types'] );
	}

	/**
	 * The taxonomy-term targeting map (taxonomy => list of term ids).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, list<int>>
	 */
	public function get_terms(): array {
		$terms = $this->get_settings()['terms'];
		if ( ! is_array( $terms ) ) {
			return [];
		}
		$result = [];
		foreach ( $terms as $taxonomy => $ids ) {
			if ( ! is_array( $ids ) ) {
				continue;
			}
			$result[ (string) $taxonomy ] = array_values( array_map( fn ( $id ): int => $this->to_int( $id ), $ids ) );
		}
		return $result;
	}

	/**
	 * The global per-post link cap.
	 *
	 * @since 1.0.0
	 */
	public function get_max_links_per_post(): int {
		return $this->to_int( $this->get_settings()['max_links_per_post'] );
	}

	/**
	 * Sanitise raw settings input into the canonical option shape, without
	 * persisting. This is the single source of truth for the option's shape and
	 * the callback the Settings-API page registers via register_setting(); the
	 * native save path on options.php runs it before update_option() stores the
	 * result. Both the chip fields' JS hidden-input arrays and their no-JS
	 * comma/newline strings are accepted, so the page saves the same either way.
	 *
	 * Two rules harden the targeting and limits: post types are intersected with
	 * the registered public set, so a tampered POST or a typo in the no-JS text
	 * field can never enable an unregistered type; and the post cap is coerced to
	 * a positive integer, never zero or negative.
	 *
	 * @since 1.2.0
	 *
	 * @param array<array-key, mixed> $input Raw form input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $input ): array {

		// Targeting is limited to the registered public post types; anything else
		// the field could carry — a stale slug, a tampered value — is dropped.
		$public_types = get_post_types( [ 'public' => true ] );
		$post_types = array_values( array_filter(
			$this->sanitise_keys( $input['post_types'] ?? [] ),
			static fn ( string $type ): bool => isset( $public_types[ $type ] ),
		) );

		return [
			'deny_tags' => $this->sanitise_tags( $input['deny_tags'] ?? [] ),
			'skip_class' => isset( $input['skip_class'] ) ? sanitize_html_class( $this->to_string( $input['skip_class'] ) ) : self::DEFAULTS['skip_class'],
			'deny_xpath' => isset( $input['deny_xpath'] ) ? trim( $this->to_string( $input['deny_xpath'] ) ) : '',
			'allow_only_xpath' => isset( $input['allow_only_xpath'] ) ? trim( $this->to_string( $input['allow_only_xpath'] ) ) : '',
			'link_class' => isset( $input['link_class'] ) ? sanitize_html_class( $this->to_string( $input['link_class'] ) ) : self::DEFAULTS['link_class'],
			'max_links_per_post' => max( 1, abs( $this->to_int( $input['max_links_per_post'] ?? self::DEFAULTS['max_links_per_post'] ) ) ),
			'post_types' => $post_types,
			'terms' => $this->sanitise_terms( $input['terms'] ?? [] ),
		];

	}

	/**
	 * Sanitise and persist settings (non-autoloaded).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Raw form input.
	 */
	public function save_settings( array $input ): void {
		update_option( self::OPTION, $this->sanitize_settings( $input ), false );
	}

	/**
	 * Coerce a value into a list of non-empty strings.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	private function as_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}
		$result = [];
		foreach ( $value as $item ) {
			$string = $this->to_string( $item );
			if ( $string !== '' ) {
				$result[] = $string;
			}
		}
		return $result;
	}

	/**
	 * Normalise a chip-field value into a list of trimmed, non-empty tokens. A
	 * value is either the array of hidden inputs the chip JS serialises, or — with
	 * JS off — the single comma/newline string the textarea posts; both reduce to
	 * the same token list so the rest of the sanitiser is representation-agnostic.
	 *
	 * @since 1.2.0
	 *
	 * @return list<string>
	 */
	private function to_list( mixed $value ): array {

		// A no-JS submission is one delimited string; split it on commas/newlines.
		if ( is_string( $value ) ) {
			$parts = preg_split( '/[\r\n,]+/', $value );
			$value = $parts === false ? [] : $parts;
		}

		if ( ! is_array( $value ) ) {
			return [];
		}

		$result = [];
		foreach ( $value as $item ) {
			$token = trim( $this->to_string( $item ) );
			if ( $token !== '' ) {
				$result[] = $token;
			}
		}
		return $result;

	}

	/**
	 * Sanitise tag names: lowercased, reduced to [a-z0-9], de-duplicated. Accepts
	 * both the chip array and the no-JS comma/newline string.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	private function sanitise_tags( mixed $tags ): array {
		$result = [];
		foreach ( $this->to_list( $tags ) as $tag ) {
			$clean = preg_replace( '/[^a-z0-9]/', '', strtolower( $tag ) );
			if ( $clean !== null && $clean !== '' ) {
				$result[] = $clean;
			}
		}
		return array_values( array_unique( $result ) );
	}

	/**
	 * Sanitise keys via sanitize_key, de-duplicated. Accepts both the chip array
	 * and the no-JS comma/newline string.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	private function sanitise_keys( mixed $values ): array {
		$result = [];
		foreach ( $this->to_list( $values ) as $value ) {
			$clean = sanitize_key( $value );
			if ( $clean !== '' ) {
				$result[] = $clean;
			}
		}
		return array_values( array_unique( $result ) );
	}

	/**
	 * Sanitise the taxonomy-term targeting map into taxonomy => list<int>.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, list<int>>
	 */
	private function sanitise_terms( mixed $terms ): array {
		if ( ! is_array( $terms ) ) {
			return [];
		}
		$result = [];
		foreach ( $terms as $taxonomy => $ids ) {
			$tax = sanitize_key( (string) $taxonomy );
			if ( $tax === '' || ! is_array( $ids ) ) {
				continue;
			}
			$clean_ids = [];
			foreach ( $ids as $id ) {
				$value = $this->to_int( $id );
				if ( $value > 0 ) {
					$clean_ids[] = $value;
				}
			}
			if ( $clean_ids !== [] ) {
				$result[ $tax ] = $clean_ids;
			}
		}
		return $result;
	}

	/**
	 * Coerce a scalar value to string; non-scalars become an empty string.
	 *
	 * @since 1.0.0
	 */
	private function to_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Coerce a numeric value to int; non-numerics become zero.
	 *
	 * @since 1.0.0
	 */
	private function to_int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

}
