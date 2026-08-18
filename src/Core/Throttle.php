<?php
declare( strict_types=1 );

namespace Trade\Core;

/**
 * Sliding-window throttle (§B.3.5 rate limits) using one bucket table. Also serves the
 * initData replay window (§B.3.1 step 5) as a `limit=1` bucket. Spec-silent storage
 * (§B.4 has no rate-limit table) — single generic primitive both features share.
 *
 * First hit inserts a bucket with count=1 (that use is allowed); later in-window hits
 * with count >= limit are denied. Replay = limit 1 → any second use in 300s is denied.
 */
final class Throttle {

	/**
	 * Record one use of $bucket. Counts this hit toward the window.
	 *
	 * @return array{allowed: bool, retry_after: int} retry_after seconds, 0 when allowed.
	 */
	public static function hit( string $bucket, int $window_seconds, int $limit, ?Store $store = null ): array {
		$store = $store ?? Store::default();
		$now   = time();

		$row = $store->get_row( 'tb_throttle', 'bucket_key = %s', array( $bucket ) );
		if ( ! $row ) {
			// ponytail: no unique-row lock; a raced double-create is absorbed by the PK (second insert
			// returns false and falls through to the reuse path below). Exact atomicity not needed at MVP.
			$inserted = $store->insert( 'tb_throttle', array(
				'bucket_key'        => $bucket,
				'window_started_at' => gmdate( 'Y-m-d H:i:s', $now ),
				'window_seconds'    => $window_seconds,
				'count'             => 1,
			) );
			if ( $inserted ) {
				return array( 'allowed' => true, 'retry_after' => 0 );
			}
			$row = $store->get_row( 'tb_throttle', 'bucket_key = %s', array( $bucket ) );
		}

		$start = (int) strtotime( (string) $row['window_started_at'] );
		if ( $now - $start >= (int) $row['window_seconds'] ) {
			$store->update_where(
				'tb_throttle',
				array( 'window_started_at' => gmdate( 'Y-m-d H:i:s', $now ), 'count' => 1 ),
				'bucket_key = %s',
				array( $bucket )
			);
			return array( 'allowed' => true, 'retry_after' => 0 );
		}

		if ( (int) $row['count'] >= $limit ) {
			return array(
				'allowed'     => false,
				'retry_after' => max( 1, ( $start + (int) $row['window_seconds'] ) - $now ),
			);
		}

		$store->update( 'tb_throttle', array( 'count' => (int) $row['count'] + 1 ), array( 'bucket_key' => $bucket ) );
		return array( 'allowed' => true, 'retry_after' => 0 );
	}
}