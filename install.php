<?php
/**
 * Activation routine: grant the keyword-management capability.
 *
 * Runs in the plugin's process during activation. The main file requires the
 * autoloader before registering the activation hook, so Capabilities is loadable.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Autolink;

// Grant the custom capability to editor-and-above roles.
( new Capabilities() )->grant();
