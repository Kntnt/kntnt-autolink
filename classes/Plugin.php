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

	/** @since 1.0.0 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the one shared instance, creating it on first call.
	 *
	 * @since 1.0.0
	 */
	public static function get_instance(): Plugin {
		return self::$instance ??= new self();
	}

	/**
	 * Wires components in dependency order and registers their hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		// Instantiate the repositories and the pure engine, then wire the
		// content filter that bridges them to the_content.
		$settings = new Settings_Repository();
		$keywords = new Keyword_Repository();
		$linker = new Linker();

		$content_filter = new Content_Filter( $settings, $keywords, $linker );
		$content_filter->register_hooks();

		// The admin UI is only needed in the admin context.
		if ( is_admin() ) {
			( new Admin\Tools_Page( $settings, $keywords ) )->register_hooks();
		}

	}

}
