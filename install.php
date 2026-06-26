<?php
/**
 * Activation routine: grant the link-group-management capability and stamp the
 * installed version.
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

// Stamp the installed schema version so the upgrade routine treats this as a
// fresh install rather than an in-place update.
update_option( Migrator::VERSION_OPTION, Plugin::VERSION, false );
