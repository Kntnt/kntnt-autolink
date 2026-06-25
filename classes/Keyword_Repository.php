<?php
/**
 * Reads and writes the keyword option, hydrating stored entries into Keyword
 * value objects and sanitising on write. The only code that touches
 * kntnt_autolink_keywords.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Keyword_Repository {

	/** @since 1.0.0 */
	private const OPTION = 'kntnt_autolink_keywords';

	/**
	 * All stored keywords, hydrated.
	 *
	 * @since 1.0.0
	 *
	 * @return list<Keyword>
	 */
	public function all(): array {
		$keywords = [];
		foreach ( $this->raw_entries() as $entry ) {
			$keywords[] = new Keyword(
				id: $this->to_string( $entry['id'] ?? '' ),
				base: $this->to_string( $entry['base'] ?? '' ),
				variants: $this->as_string_list( $entry['variants'] ?? [] ),
				url: $this->to_string( $entry['url'] ?? '' ),
				max: max( 1, $this->to_int( $entry['max'] ?? 1 ) ),
			);
		}
		return $keywords;
	}

	/**
	 * Upsert a keyword by id, sanitising before storage.
	 *
	 * @since 1.0.0
	 */
	public function save( Keyword $keyword ): void {
		$entry = $this->to_entry( $keyword );
		$entries = $this->raw_entries();
		$replaced = false;
		foreach ( $entries as $index => $existing ) {
			if ( $this->to_string( $existing['id'] ?? '' ) === $entry['id'] ) {
				$entries[ $index ] = $entry;
				$replaced = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$entries[] = $entry;
		}
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Delete the keyword with the given id.
	 *
	 * @since 1.0.0
	 */
	public function delete( string $id ): void {
		$entries = array_values( array_filter(
			$this->raw_entries(),
			fn ( array $entry ): bool => $this->to_string( $entry['id'] ?? '' ) !== $id,
		) );
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Overwrite the whole list with the given keywords.
	 *
	 * @since 1.0.0
	 *
	 * @param list<Keyword> $keywords
	 */
	public function replace_all( array $keywords ): void {
		$entries = array_map( fn ( Keyword $keyword ): array => $this->to_entry( $keyword ), $keywords );
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Stored entries as a list of associative arrays, ignoring malformed rows.
	 *
	 * @since 1.0.0
	 *
	 * @return list<array<array-key, mixed>>
	 */
	private function raw_entries(): array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			return [];
		}
		$entries = [];
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	/**
	 * Sanitise a Keyword into its stored shape, generating an id when missing.
	 *
	 * @since 1.0.0
	 *
	 * @return array{id: string, base: string, variants: list<string>, url: string, max: int}
	 */
	private function to_entry( Keyword $keyword ): array {
		$id = sanitize_key( $keyword->id );
		if ( $id === '' ) {
			$id = sanitize_key( wp_generate_uuid4() );
		}
		return [
			'id' => $id,
			'base' => sanitize_text_field( $keyword->base ),
			'variants' => $this->sanitise_variants( $keyword->variants ),
			'url' => esc_url_raw( $keyword->url ),
			'max' => max( 1, absint( $keyword->max ) ),
		];
	}

	/**
	 * Sanitise variant surface forms, dropping empties.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $variants
	 * @return list<string>
	 */
	private function sanitise_variants( array $variants ): array {
		$result = [];
		foreach ( $variants as $variant ) {
			$clean = sanitize_text_field( $variant );
			if ( $clean !== '' ) {
				$result[] = $clean;
			}
		}
		return $result;
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
