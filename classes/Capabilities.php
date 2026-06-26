<?php
/**
 * Registers and removes the custom capability that gates link-group management.
 *
 * The link-group list is editor-and-above: the capability is granted to every
 * role that can edit others' posts. Structural rules stay on manage_options and
 * are not represented here.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

// Not final: the Migrator depends on this as a collaborator and unit-tests mock it.
class Capabilities {

	/** @since 1.1.0 */
	public const MANAGE_LINK_GROUPS = 'kntnt_autolink_manage_link_groups';

	/**
	 * Grant the capability to every role that can edit others' posts.
	 *
	 * @since 1.0.0
	 */
	public function grant(): void {
		foreach ( array_keys( wp_roles()->roles ) as $slug ) {
			$role = get_role( (string) $slug );
			if ( $role !== null && $role->has_cap( 'edit_others_posts' ) ) {
				$role->add_cap( self::MANAGE_LINK_GROUPS );
			}
		}
	}

	/**
	 * Remove the capability from every role.
	 *
	 * @since 1.0.0
	 */
	public function revoke(): void {
		foreach ( array_keys( wp_roles()->roles ) as $slug ) {
			get_role( (string) $slug )?->remove_cap( self::MANAGE_LINK_GROUPS );
		}
	}

}
