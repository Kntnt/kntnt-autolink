<?php

declare( strict_types = 1 );

use Brain\Monkey;

// Lightweight WordPress REST class doubles, so the REST controller unit-tests
// without a WordPress bootstrap.
require_once __DIR__ . '/wp-doubles.php';

// Unit tests run inside Brain Monkey so WordPress functions can be stubbed. The
// pure-engine tests make no WordPress calls, so the setUp/tearDown is harmless
// there.
pest()->extend( PHPUnit\Framework\TestCase::class )
	->beforeEach( fn () => Monkey\setUp() )
	->afterEach( fn () => Monkey\tearDown() )
	->in( 'Unit' );

// Integration tests are shell-driven (Playground); keep the binding so the
// suite resolves even while the directory holds no PHP cases.
pest()->extend( PHPUnit\Framework\TestCase::class )->in( 'Integration' );
