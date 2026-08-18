<?php
declare( strict_types=1 );

namespace Trade\Core;

/**
 * §B.8 idempotency. UNIQUE key + INSERT IGNORE does the atomic fencing:
 * whoever inserts first owns the in-flight slot; a crashed request leaves
 * response_json NULL (= in-flight marker) until expiry.
 */
final class Idempotency {

	public const TTL_HOURS = 24;

	/**
	 * Pure decision logic — smoke-tested directly.
	 *
	 * @param string $attempt_hash sha256 of this request body
	 * @param string|null $stored_hash hash of the stored request
	 * @param bool $in_flight stored response_json is null
	 * @return string 'new' | 'replay' | 'different_body' | 'in_progress'
	 */
	public static function classify( string $attempt_hash, ?string $stored_hash, bool $in_flight ): string {
		if ( null === $stored_hash ) {
			return 'new';
		}
		if ( $in_flight ) {
			return 'in_progress';
		}
		return hash_equals( (string) $stored_hash, $attempt_hash ) ? 'replay' : 'different_body';
	}

	public static function capture( string $key, int $wp_user_id, string $endpoint, string $request_hash ): string {
		$store = Store::default();
		$inserted = $store->insert( 'tb_idempotency_keys', array(
			'idem_key'     => $key,
			'wp_user_id'   => $wp_user_id,
			'endpoint'     => $endpoint,
			'request_hash' => $request_hash,
			'status_code'  => 0, // response_json NULL → in flight
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + self::TTL_HOURS * 3600 ),
		) );
		if ( $inserted ) {
			return 'new';
		}

		$row = $store->get_row(
			'tb_idempotency_keys',
			'idem_key = %s AND wp_user_id = %d AND endpoint = %s',
			array( $key, $wp_user_id, $endpoint )
		);

		return self::classify( $request_hash, $row['request_hash'] ?? null, ( $row['response_json'] ?? null ) === null );
	}

	/** Record the finished response; row switches from in-flight to replayable. */
	public static function release( string $key, int $wp_user_id, string $endpoint, array $response, int $status ): void {
		Store::default()->update_where(
			'tb_idempotency_keys',
			array( 'response_json' => wp_json_encode( $response, JSON_UNESCAPED_UNICODE ), 'status_code' => $status ),
			'idem_key = %s AND wp_user_id = %d AND endpoint = %s',
			array( $key, $wp_user_id, $endpoint )
		);
	}

	/** Expose a stored response for replay. */
	public static function stored( string $key, int $wp_user_id, string $endpoint ): ?array {
		$row = Store::default()->get_row(
			'tb_idempotency_keys',
			'idem_key = %s AND wp_user_id = %d AND endpoint = %s',
			array( $key, $wp_user_id, $endpoint )
		);
		if ( ! $row || $row['response_json'] === null ) {
			return null;
		}
		return array(
			'status'  => (int) $row['status_code'],
			'body'    => json_decode( $row['response_json'], true ) ?? array(),
		);
	}

	/** §B.8 TTL: drop keys past 24h. Called opportunistically from write paths. */
	public static function prune(): void {
		Store::default()->delete_where( 'tb_idempotency_keys', 'expires_at < %s', array( gmdate( 'Y-m-d H:i:s' ) ) );
	}
}