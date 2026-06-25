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
	 * Wires components and registers hooks.
	 *
	 * Intentionally empty until Plan 006 wires the Content_Filter.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {}

}
