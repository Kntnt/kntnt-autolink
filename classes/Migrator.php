<?php
/**
 * Version-keyed upgrade routine.
 *
 * WordPress fires the activation hook only when a plugin is activated, never on
 * an in-place update. Between 1.0.0 and 1.1.0 this plugin renamed its gating
 * capability (kntnt_autolink_manage_keywords → kntnt_autolink_manage_link_groups)
 * and its storage option (kntnt_autolink_keywords → kntnt_autolink_link_groups).
 * Without a self-healing upgrade, a site updated in place would have no role
 * holding the new capability — locking even administrators out of Tools → Autolink
 * and every REST route — and its existing keyword data orphaned under the old key.
 *
 * On the first request after an update this routine compares the stored schema
 * version to the running one and, on a mismatch, re-grants the renamed
 * capability, folds the legacy keyword entries into link groups, drops the legacy
 * capability and option, then stamps the running version so it runs once.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Migrator {

	/**
	 * Option stamping the installed schema version. Public so install.php and
	 * uninstall.php can stamp and remove it without a second source of truth.
	 *
	 * @since 1.1.0
	 */
	public const VERSION_OPTION = 'kntnt_autolink_version';

	/** @since 1.1.0 */
	private const LEGACY_KEYWORDS_OPTION = 'kntnt_autolink_keywords';

	/** @since 1.1.0 */
	private const LINK_GROUPS_OPTION = 'kntnt_autolink_link_groups';

	/** @since 1.1.0 */
	private const SETTINGS_OPTION = 'kntnt_autolink_settings';

	/** @since 1.1.0 */
	private const LEGACY_CAPABILITY = 'kntnt_autolink_manage_keywords';

	/**
	 * @since 1.1.0
	 *
	 * @param Capabilities $capabilities Grants the renamed capability on upgrade.
	 * @param string       $version      The running plugin version to converge to.
	 */
	public function __construct(
		private readonly Capabilities $capabilities,
		private readonly string $version,
	) {}

	/**
	 * Run the upgrade check on init, which fires for admin, front-end and REST
	 * requests alike — so the lock-out self-heals whichever surface is hit first.
	 *
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_action( 'init', $this->maybe_upgrade( ... ) );
	}

	/**
	 * Converge the site to the running version when the stored version trails it.
	 *
	 * @since 1.1.0
	 */
	public function maybe_upgrade(): void {

		// The stored version trails the running one only after an in-place update,
		// where the activation hook never ran; nothing to do once they match.
		$stored = get_option( self::VERSION_OPTION );
		if ( is_string( $stored ) && $stored === $this->version ) {
			return;
		}

		// Re-grant the renamed capability the update path never granted, carry the
		// legacy keyword data forward, retire the legacy capability, then stamp the
		// running version so this work happens exactly once.
		$this->capabilities->grant();
		$this->migrate_keywords();
		$this->remove_legacy_capability();
		update_option( self::VERSION_OPTION, $this->version, false );

	}

	/**
	 * Fold legacy keyword entries into link groups, then drop the legacy option.
	 *
	 * @since 1.1.0
	 */
	private function migrate_keywords(): void {

		// Migrate only when legacy data exists and the link-group option is still
		// unset, so a re-run can never clobber link groups created since.
		$legacy = get_option( self::LEGACY_KEYWORDS_OPTION );
		if ( ! is_array( $legacy ) || get_option( self::LINK_GROUPS_OPTION ) !== false ) {
			return;
		}

		// nofollow / new-tab were global before 1.1.0; carry the old global flags
		// onto every migrated group so the rendered links do not silently change.
		$settings = get_option( self::SETTINGS_OPTION );
		$nofollow = is_array( $settings ) && ! empty( $settings['nofollow'] );
		$new_tab = is_array( $settings ) && ! empty( $settings['new_tab'] );

		// Map each keyword { id, base, variants, url, max } to a link group: base
		// and variants fold into one flat phrase list, max becomes the cap, and the
		// surrogate id is preserved so already-generated links stay stable. The URL
		// is re-run through esc_url_raw — the same gate the REST/repository write
		// paths apply — so the sanitisation invariant is local to this migrator
		// rather than inherited from the upstream option.
		$groups = [];
		foreach ( $legacy as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$phrases = array_values( array_filter(
				[ $this->to_string( $entry['base'] ?? '' ), ...$this->string_list( $entry['variants'] ?? [] ) ],
				static fn ( string $phrase ): bool => $phrase !== '',
			) );
			if ( $phrases === [] ) {
				continue;
			}
			$groups[] = [
				'id' => $this->to_string( $entry['id'] ?? '' ),
				'phrases' => $phrases,
				'url' => esc_url_raw( $this->to_string( $entry['url'] ?? '' ) ),
				'cap' => max( 1, $this->to_int( $entry['max'] ?? 1 ) ),
				'nofollow' => $nofollow,
				'new_tab' => $new_tab,
			];
		}

		update_option( self::LINK_GROUPS_OPTION, $groups, false );
		delete_option( self::LEGACY_KEYWORDS_OPTION );

	}

	/**
	 * Remove the pre-1.1.0 capability from every role.
	 *
	 * @since 1.1.0
	 */
	private function remove_legacy_capability(): void {
		foreach ( array_keys( wp_roles()->roles ) as $slug ) {
			get_role( (string) $slug )?->remove_cap( self::LEGACY_CAPABILITY );
		}
	}

	/**
	 * Coerce a value into a list of non-empty strings.
	 *
	 * @since 1.1.0
	 *
	 * @return list<string>
	 */
	private function string_list( mixed $value ): array {
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
