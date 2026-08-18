<?php
declare( strict_types=1 );

// PSR-4-style loader: Trade\X\Y → src/X/Y.php. No composer, no require lists.
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'Trade\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );