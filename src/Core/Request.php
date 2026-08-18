<?php
declare( strict_types=1 );

namespace Trade\Core;

/**
 * X-Request-ID: honor a validated inbound header, else generate. Memoized per request
 * so audit rows, job payloads, and error envelopes all carry the same id (§B.5).
 */
final class Request {

	private static ?string $current = null;

	public static function id(): string {
		if ( null === self::$current ) {
			$raw = isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ? (string) $_SERVER['HTTP_X_REQUEST_ID'] : '';
			self::$current = preg_match( '/^[A-Za-z0-9_-]{6,64}$/', $raw ) ? $raw : self::generate();
		}
		return self::$current;
	}

	public static function generate(): string {
		return 'req_' . bin2hex( random_bytes( 10 ) );
	}

	/** Test seam. */
	public static function reset( ?string $id = null ): void {
		self::$current = $id;
	}
}