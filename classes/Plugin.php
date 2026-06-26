<?php
/**
 * Plugin bootstrap singleton.
 *
 * Instantiates every component in dependency order and registers their
 * WordPress hooks. The constructor is the single wiring point.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

final class Plugin {

	/** @since 1.1.0 */
	public const VERSION = '1.1.0';

	/** @since 1.0.0 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the one shared instance, creating it on first call.
	 *
	 * @since 1.0.0
	 */
	public static function get_instance( string $plugin_file = '' ): Plugin {
		return self::$instance ??= new self( $plugin_file );
	}

	/**
	 * Wires components in dependency order and registers their hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_file The main plugin file, for admin asset URLs.
	 */
	private function __construct( string $plugin_file ) {

		// An in-place update never fires the activation hook, so a version-keyed
		// upgrade routine re-grants the renamed capability and migrates the renamed
		// option on the first request after the update.
		( new Migrator( new Capabilities(), self::VERSION ) )->register_hooks();

		// Instantiate the repositories and the pure engine, then wire the content
		// filter that bridges them to the_content.
		$settings = new Settings_Repository();
		$groups = new Link_Group_Repository();
		$linker = new Linker();

		$content_filter = new Content_Filter( $settings, $groups, $linker );
		$content_filter->register_hooks();

		// The REST API re-renders the list-table body server-side after each change,
		// for the current search/sort/page the request carries, and reports the total
		// match count so the client can keep pagination honest. Building the table
		// needs WP_List_Table, whose constructor calls convert_to_screen()/WP_Screen —
		// the whole admin screen and template API is absent on a REST (non-admin)
		// request, so the closure loads it on demand in core's own order; require_once
		// is a no-op when wp-admin is already loaded.
		$render_rows = static function ( Link_Group_Query $query ) use ( $groups ): array {
			if ( ! class_exists( \WP_List_Table::class ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
				require_once ABSPATH . 'wp-admin/includes/screen.php';
				require_once ABSPATH . 'wp-admin/includes/template.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			}
			$table = new Admin\Link_Groups_List_Table( $groups );
			$table->prepare_for( $query );
			return [ 'rows' => $table->rows_html(), 'total' => (int) $table->get_pagination_arg( 'total_items' ) ];
		};
		( new Rest_Controller( $groups, $render_rows ) )->register_hooks();

		// The admin screens are only needed in the admin context: the link-group
		// manager under Tools, the structural rules under Settings.
		if ( is_admin() ) {
			( new Admin\Tools_Page( $groups, $plugin_file, self::VERSION ) )->register_hooks();
			( new Admin\Settings_Page( $settings, $plugin_file, self::VERSION ) )->register_hooks();
		}

	}

}
