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
	 * Sanitise and persist settings (non-autoloaded).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input Raw form input.
	 */
	public function save_settings( array $input ): void {
		$sanitised = [
			'deny_tags' => $this->sanitise_tags( $input['deny_tags'] ?? [] ),
			'skip_class' => isset( $input['skip_class'] ) ? sanitize_html_class( $this->to_string( $input['skip_class'] ) ) : self::DEFAULTS['skip_class'],
			'deny_xpath' => isset( $input['deny_xpath'] ) ? trim( $this->to_string( $input['deny_xpath'] ) ) : '',
			'allow_only_xpath' => isset( $input['allow_only_xpath'] ) ? trim( $this->to_string( $input['allow_only_xpath'] ) ) : '',
			'link_class' => isset( $input['link_class'] ) ? sanitize_html_class( $this->to_string( $input['link_class'] ) ) : self::DEFAULTS['link_class'],
			'max_links_per_post' => isset( $input['max_links_per_post'] ) ? abs( $this->to_int( $input['max_links_per_post'] ) ) : self::DEFAULTS['max_links_per_post'],
			'post_types' => $this->sanitise_keys( $input['post_types'] ?? self::DEFAULTS['post_types'] ),
			'terms' => $this->sanitise_terms( $input['terms'] ?? [] ),
		];
		update_option( self::OPTION, $sanitised, false );
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
	 * Sanitise a list of tag names: lowercased, reduced to [a-z0-9], de-duplicated.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	private function sanitise_tags( mixed $tags ): array {
		if ( ! is_array( $tags ) ) {
			return [];
		}
		$result = [];
		foreach ( $tags as $tag ) {
			$clean = preg_replace( '/[^a-z0-9]/', '', strtolower( $this->to_string( $tag ) ) );
			if ( $clean !== null && $clean !== '' ) {
				$result[] = $clean;
			}
		}
		return array_values( array_unique( $result ) );
	}

	/**
	 * Sanitise a list of keys via sanitize_key, de-duplicated.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string>
	 */
	private function sanitise_keys( mixed $values ): array {
		if ( ! is_array( $values ) ) {
			return [];
		}
		$result = [];
		foreach ( $values as $value ) {
			$clean = sanitize_key( $this->to_string( $value ) );
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
