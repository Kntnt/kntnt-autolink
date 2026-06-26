<?php
/**
 * Adds quick links to the two Autolink admin screens — the Tools link-group
 * manager and the Settings structural rules — to the plugin's action-links row
 * on the Plugins screen (ADR-0002). Both links are shown unconditionally; the
 * screens themselves enforce their own capability gates.
 *
 * @since 1.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink\Admin;

final class Action_Links {

	/**
	 * @since 1.1.0
	 *
	 * @param string $plugin_file The main plugin file, for the plugin basename.
	 */
	public function __construct(
		private readonly string $plugin_file,
	) {}

	/**
	 * Register the action-links filter for this plugin's row.
	 *
	 * @since 1.1.0
	 */
	public function register_hooks(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), $this->add_links( ... ) );
	}

	/**
	 * Prepend the Tools-manager and Settings links to the plugin's action-links
	 * row, ahead of core's Deactivate/Edit, so both Autolink screens are one click
	 * from the Plugins list. URLs come from each screen's own slug authority.
	 *
	 * @since 1.1.0
	 *
	 * @param array<int|string, string> $links Core's existing action links.
	 * @return array<int|string, string>
	 */
	public function add_links( array $links ): array {

		$own = [
			'<a href="' . esc_url( Tools_Page::url() ) . '">' . esc_html__( 'Link groups', 'kntnt-autolink' ) . '</a>',
			'<a href="' . esc_url( Settings_Page::url() ) . '">' . esc_html__( 'Settings', 'kntnt-autolink' ) . '</a>',
		];

		return [ ...$own, ...$links ];

	}

}
