<?php
/**
 * Reads and writes the link-group option, hydrating stored entries into
 * Link_Group value objects and sanitising on write. The only code that touches
 * kntnt_autolink_link_groups.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Link_Group_Repository {

	/** @since 1.1.0 */
	private const OPTION = 'kntnt_autolink_link_groups';

	/**
	 * All stored link groups, hydrated.
	 *
	 * @since 1.1.0
	 *
	 * @return list<Link_Group>
	 */
	public function all(): array {
		$groups = [];
		foreach ( $this->raw_entries() as $entry ) {
			$groups[] = $this->hydrate( $entry );
		}
		return $groups;
	}

	/**
	 * The link group with the given id, or null when none matches.
	 *
	 * @since 1.1.0
	 */
	public function find( string $id ): ?Link_Group {
		foreach ( $this->raw_entries() as $entry ) {
			if ( $this->to_string( $entry['id'] ?? '' ) === $id ) {
				return $this->hydrate( $entry );
			}
		}
		return null;
	}

	/**
	 * Upsert a link group by id, sanitising before storage. Returns the stored
	 * group, so callers learn a freshly generated id.
	 *
	 * @since 1.1.0
	 */
	public function save( Link_Group $group ): Link_Group {
		$entry = $this->to_entry( $group );
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
		return $this->hydrate( $entry );
	}

	/**
	 * Delete the link group with the given id.
	 *
	 * @since 1.1.0
	 */
	public function delete( string $id ): void {
		$entries = array_values( array_filter(
			$this->raw_entries(),
			fn ( array $entry ): bool => $this->to_string( $entry['id'] ?? '' ) !== $id,
		) );
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Hydrate a stored entry into a Link_Group, defaulting missing fields.
	 *
	 * @since 1.1.0
	 *
	 * @param array<array-key, mixed> $entry
	 */
	private function hydrate( array $entry ): Link_Group {
		return new Link_Group(
			id: $this->to_string( $entry['id'] ?? '' ),
			phrases: $this->as_string_list( $entry['phrases'] ?? [] ),
			url: $this->to_string( $entry['url'] ?? '' ),
			cap: max( 1, $this->to_int( $entry['cap'] ?? 1 ) ),
			nofollow: ! empty( $entry['nofollow'] ),
			new_tab: ! empty( $entry['new_tab'] ),
		);
	}

	/**
	 * Stored entries as a list of associative arrays, ignoring malformed rows.
	 *
	 * @since 1.1.0
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
	 * Sanitise a Link_Group into its stored shape, generating an id when missing.
	 *
	 * @since 1.1.0
	 *
	 * @return array{id: string, phrases: list<string>, url: string, cap: int, nofollow: bool, new_tab: bool}
	 */
	private function to_entry( Link_Group $group ): array {
		$id = sanitize_key( $group->id );
		if ( $id === '' ) {
			$id = sanitize_key( wp_generate_uuid4() );
		}
		return [
			'id' => $id,
			'phrases' => $this->sanitise_phrases( $group->phrases ),
			'url' => esc_url_raw( $group->url ),
			'cap' => max( 1, absint( $group->cap ) ),
			'nofollow' => $group->nofollow,
			'new_tab' => $group->new_tab,
		];
	}

	/**
	 * Sanitise phrases, dropping empties.
	 *
	 * @since 1.1.0
	 *
	 * @param list<string> $phrases
	 * @return list<string>
	 */
	private function sanitise_phrases( array $phrases ): array {
		$result = [];
		foreach ( $phrases as $phrase ) {
			$clean = sanitize_text_field( $phrase );
			if ( $clean !== '' ) {
				$result[] = $clean;
			}
		}
		return $result;
	}

	/**
	 * Coerce a value into a list of non-empty strings.
	 *
	 * @since 1.1.0
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
	 * @since 1.1.0
	 */
	private function to_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Coerce a numeric value to int; non-numerics become zero.
	 *
	 * @since 1.1.0
	 */
	private function to_int( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

}
