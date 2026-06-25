<?php
/**
 * PSR-4 autoloader for the \Kntnt\Autolink namespace.
 *
 * @since 1.0.0
 */

declare( strict_types = 1 );

spl_autoload_register( static function ( string $class ): void {

	// Only handle this plugin's namespace; ignore everything else.
	$prefix = 'Kntnt\\Autolink\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	// Map the relative class name to a file under classes/, preserving sub-namespaces.
	$relative = substr( $class, strlen( $prefix ) );
	$path = __DIR__ . '/classes/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $path ) ) {
		require_once $path;
	}

} );
