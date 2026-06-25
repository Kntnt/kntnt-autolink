<?php

declare( strict_types = 1 );

it( 'has a working test toolchain', function (): void {
	expect( true )->toBeTrue();
} );

it( 'autoloads the Plugin singleton class', function (): void {
	expect( class_exists( \Kntnt\Autolink\Plugin::class ) )->toBeTrue();
} );
