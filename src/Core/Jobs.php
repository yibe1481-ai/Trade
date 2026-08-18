<?php
declare( strict_types=1 );

namespace Trade\Core;

/**
 * Job queue (§B.9) — at-least-once, lease-protocol claims.
 * The store SQL does the fencing (claim = conditional UPDATE, complete/fail = id+token WHERE);
 * the numeric/state decision layer (backoff ladder, dead-letter cutover) is pure and smoke-tested.
 */
final class Jobs {

	public function get( int $job_id ): ?array {
		return $this->store->get_row( 'tb_jobs', 'id = %d', array( $job_id ) );
	}

	private Store $store;

	public function __construct( ?Store $store = null ) {
		$this->store = $store ?? Store::default();
	}

	public const LEASE_SECONDS = 60;

	/** §B.9.3 backoff ladder in seconds; index = attempts already spent (0 → 1m). */
	private const BACKOFF = array( 0, 60, 300, 900, 3600, 21600 );

	/** Pure: next run-after seconds for the Nth failed attempt. */
	public static function retry_after( int $attempts ): int {
		return self::BACKOFF[ min( $attempts, 5 ) ];
	}

	/** Pure: §B.9.3 max_attempts exhausted → dead_letter. */
	public static function is_dead( int $attempts, int $max_attempts ): bool {
		return $attempts >= $max_attempts;
	}

	/** Idempotent-enqueue: returns existing job id if $idem_key already queued, else null. */
	public function enqueue( string $type, array $payload, array $opts = array() ): ?int {
		$idem = (string) ( $opts['idempotency_key'] ?? '' );
		if ( '' !== $idem ) {
			$existing = $this->store->get_row( 'tb_jobs', 'idempotency_key = %s', array( $idem ) );
			if ( $existing ) {
				return (int) $existing['id'];
			}
		}

		$run_after = isset( $opts['run_after'] ) ? gmdate( 'Y-m-d H:i:s', time() + (int) $opts['run_after'] ) : gmdate( 'Y-m-d H:i:s' );
		$inserted  = $this->store->insert( 'tb_jobs', array(
			'type'            => $type,
			'payload_json'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'run_after'       => $run_after,
			'status'          => 'queued',
			'attempts'        => 0,
			'max_attempts'    => (int) ( $opts['max_attempts'] ?? 5 ),
			'idempotency_key' => $idem,
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		) );
		return $inserted ? (int) $this->store->last_insert_id() : null;
	}

	/**
	 * Claim one ready job: atomic conditional UPDATE; repeats on race loss (§B.9.2).
	 * Returns the claimed job row (with lock_token) or null when the queue is drained.
	 */
	public function claim( ?string $type = null, int $lease_seconds = self::LEASE_SECONDS ): ?array {
		while ( true ) {
			$where  = "status = 'queued' AND run_after <= %s";
			$args   = array( gmdate( 'Y-m-d H:i:s' ) );
			if ( null !== $type ) {
				$where .= ' AND type = %s';
				$args[] = $type;
			}
			$candidate = $this->store->get_row( 'tb_jobs', $where, $args, 'ORDER BY run_after, id' );
			if ( ! $candidate ) {
				return null;
			}

			$token = bin2hex( random_bytes( 16 ) );
			$affected = $this->store->update_where(
				'tb_jobs',
				array(
					'status'           => 'running',
					'lock_token'       => $token,
					'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $lease_seconds ),
					'attempts'         => (int) $candidate['attempts'] + 1,
				),
				"id = %d AND status = 'queued' AND run_after <= %s",
				array( (int) $candidate['id'], gmdate( 'Y-m-d H:i:s' ) )
			);

			if ( 1 === $affected ) {
				$job = $this->store->get_row( 'tb_jobs', 'id = %d', array( (int) $candidate['id'] ) );
				$job['lock_token'] = $token;
				return $job;
			}
			// lost the race; loop for the next candidate
		}
	}

	/** Complete under the same lock token, else JOB_LEASE_LOST (§B.9.2). */
	public function complete( int $job_id, string $lock_token ): void {
		$affected = $this->store->update_where(
			'tb_jobs',
			array( 'status' => 'completed' ),
			'id = %d AND lock_token = %s AND status = %s',
			array( $job_id, $lock_token, 'running' )
		);
		if ( 1 !== $affected ) {
			Error::throw_( 'JOB_LEASE_LOST', 'core', 'Job lease lost.', array( 'job_id' => $job_id ) );
		}
	}

	/** Lease extension while a handler runs (§B.9.2). */
	public function heartbeat( int $job_id, string $lock_token, int $lease_sec = self::LEASE_SECONDS ): void {
		$this->store->update_where(
			'tb_jobs',
			array( 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + $lease_sec ) ),
			'id = %d AND lock_token = %s',
			array( $job_id, $lock_token )
		);
	}

	/** §B.9.3: requeue with backoff, or dead_letter at max_attempts (emits JOB_DEAD_LETTERED). */
	public function fail( int $job_id, string $lock_token, string $error ): void {
		$row = $this->store->get_row( 'tb_jobs', 'id = %d AND lock_token = %s', array( $job_id, $lock_token ) );
		if ( ! $row ) {
			Error::throw_( 'JOB_LEASE_LOST', 'core', 'Job lease lost.', array( 'job_id' => $job_id ) );
		}

		$attempts = (int) $row['attempts'];
		$max      = (int) $row['max_attempts'];

		if ( self::is_dead( $attempts, $max ) ) {
			$this->store->update_where(
				'tb_jobs',
				array( 'status' => 'dead_letter', 'last_error' => $error, 'lock_token' => '' ),
				'id = %d',
				array( $job_id )
			);
			Events::emit( 'JOB_DEAD_LETTERED', array(
				'job_id'     => $job_id,
				'type'       => $row['type'],
				'attempts'   => $attempts,
				'last_error' => $error,
			) );
			return;
		}

		$this->store->update_where(
			'tb_jobs',
			array(
				'status'           => 'queued',
				'lock_token'       => '',
				'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::retry_after( $attempts ) ),
				'last_error'       => $error,
			),
			'id = %d',
			array( $job_id )
		);
	}

	/** §B.9.2 Reaper: expired leases return to the queue. */
	public function reap(): int {
		return $this->store->update_where(
			'tb_jobs',
			array(
				'status'           => 'queued',
				'lock_token'       => '',
				'lease_expires_at' => null,
			),
			"status = 'running' AND lease_expires_at < %s",
			array( gmdate( 'Y-m-d H:i:s' ) )
		);
	}
}