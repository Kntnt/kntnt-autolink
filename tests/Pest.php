<?php

declare( strict_types = 1 );

// Bind Pest's base test case to the Unit and Integration directories.
pest()->extend( PHPUnit\Framework\TestCase::class )->in( 'Unit', 'Integration' );
