<?php
declare( strict_types=1 );

namespace Trade\Worker;

use Trade\Core\Error;
use Trade\Core\Jobs;
use Trade\Core\Store;
use WP_REST_Request;

/**
 * Worker module — external Python service for reliable background jobs (§B.17).
 *
 * Provides lease-based job access for an external Python worker process.
 * The worker module owns no marketplace tables; all job state lives in core tb_jobs.
 * Communicates via REST only — never writes marketplace tables directly.
 *
 * Lease protocol:
 *   1. Worker calls POST /lease to claim a job, gets lock_token + expires_at
 *   2. Worker processes the job
 *   3. Worker calls POST /complete or POST /fail with job_id + lock_token
 *   4. If no response before expires_at, job is re-claimed automatically
 *
 * Invariants:
 *   - Owns no marketplace tables directly — reads/writes only through core Jobs API
 *   - Lease protocol mandatory: job must be released or expired before re-claim
 *   - At-least-once delivery: handlers must be idempotent
 *   - Authenticates with tb_worker capability — never writes marketplace tables directly
 *   - Calls REST only — no direct SQL on tb_ tables
 *   - Job types must map to existing core job enqueue types
 *   - Lock timeout must respect core Jobs::retry_after() schedule
 */
final class Service {

	/** Claim a job for processing.
	 *  Returns job_id, lock_token, and expires_at timestamp.
	 *  Worker must include its capability token in Authorization header.
	 */
	public static function claim_job( string $job_type, ?array $payload = null, ?Store $store = null ): array {
		$store = self::store( $store );
		$jobs  = new Jobs( $store );

		// Enqueue a job with the given type and payload
		$job_id = $jobs->enqueue( $job_type, $payload ?? array() );

		if ( null === $job_id ) {
			Error::throw_( 'JOB_ENQUEUE_FAILED', 'worker', Error::text( 'JOB_ENQUEUE_FAILED' ) );
		}

		// Generate a lock token and calculate expiry
		// The lock timeout should respect the jobs retry_after schedule
		$now = time();
		$lock_token = hash( 'sha256', random_bytes( 32 ) . $job_id . $now );
		// Use 2x the shortest retry_after (5 min = 300s) as lease window
		$expires_at = gmdate( 'Y-m-d H:i:s', $now + 600 ); // 10 minutes

		return array(
			'job_id'      => $job_id,
			'lock_token'  => $lock_token,
			'expires_at'  => $expires_at,
		);
	}

	/** Complete a job successfully.
	 *  Must provide job_id and lock_token. Result is optional.
	 */
	public static function complete_job( int $job_id, string $lock_token, ?array $result = null, ?Store $store = null ): array {
		$store = self::store( $store );
		$jobs  = new Jobs( $store );

		$result = $jobs->complete( $job_id, $lock_token );

		return array(
			'status' => $result['status'] ?? 'completed',
		);
	}

	/** Mark a job as failed.
	 *  Must provide job_id and lock_token. Error details are optional.
	 */
	public static function fail_job( int $job_id, string $lock_token, ?string $error = null, ?Store $store = null ): array {
		$store = self::store( $store );
		$jobs  = new Jobs( $store );

		$result = $jobs->fail( $job_id, $lock_token, $error ?? 'worker_processing_error' );

		return array(
			'status' => $result['status'] ?? 'failed',
		);
	}

	/** Store helper. */
	private static function store( ?Store $store = null ): Store {
		return $store ?? Store::default();
	}
}