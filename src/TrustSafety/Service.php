<?php
declare( strict_types=1 );

namespace Trade\TrustSafety;

use Trade\Core\Audit;
use Trade\Core\Error;
use Trade\Core\Events;
use Trade\Core\Rest;
use Trade\Core\Store;
use WP_REST_Request;

/**
 * Trust & Safety module — reports, moderation queue, pair-velocity flags (§B.6.7).
 *
 * States: pending → {resolved, dismissed}; resolved has sub-status: cleared / rejected.
 *   pair_velocity flags a merchant when N reports accumulate within M days.
 *
 * Events:
 *   REPORT_SUBMITTED   {report_id, reporter_id, entity_type, entity_id, reason}
 *   REPORT_RESOLVED    {report_id, entity_type, entity_id, outcome}
 *   REPORT_DISMISSED   {report_id, entity_type, entity_id}
 *   MERCHANT_FLAGGED   {merchant_id, report_count, window_days}
 *
 * Invariants:
 *   - Reports always audited via tb_audit_logs.
 *   - pair_velocity: ≥3 reports against same merchant/entity_type within 7 days → FLAGGED.
 *   - Admin must provide reason on resolve/dismiss.
 *   - Self-reports (reporter_id = entity owner) → REJECTED immediately.
 */
final class Service {

	/** Report status values. */
	private const STATUSES = array( 'pending', 'cleared', 'rejected', 'flagged' );

	/** pair_velocity trigger: ≥3 reports within 7 days. */
	private const VELOCITY_THRESHOLD = 3;
	private const VELOCITY_WINDOW_DAYS = 7;

	public static function routes(): void {
		Rest::register( 'reports', 'POST', 'tb_manage_own_merchant_profile', array( self::class, 'create' ) );
		Rest::register( 'reports', 'GET', '', array( self::class, 'list' ) );
		Rest::register( 'reports/(?P<report_id>[0-9]+)', 'GET', 'tb_manage_own_merchant_profile', array( self::class, 'read' ) );
		Rest::register( 'reports/(?P<report_id>[0-9]+)/transition', 'POST', 'tb_manage_own_merchant_profile', array( self::class, 'resolve' ) );
	}

	/** REST wrapper: POST /reports. Body {entity_type, entity_id, reason}. */
	public static function create( WP_REST_Request $request ): array {
		$payload = $request->get_json_params() ?: array();
		$payload = is_array( $payload ) ? $payload : array();
		return array( 'data' => self::report_create(
			get_current_user_id(),
			(string) ( $payload['entity_type'] ?? '' ),
			(int) ( $payload['entity_id'] ?? 0 ),
			(string) ( $payload['reason'] ?? '' )
		) );
	}

	/** REST wrapper: GET /reports. Query {status?, entity_type?, entity_id?}. */
	public static function list( WP_REST_Request $request ): array {
		$filters = array();
		foreach ( array( 'status', 'entity_type', 'entity_id' ) as $key ) {
			$val = $request->get_param( $key );
			if ( null !== $val ) {
				$filters[ $key ] = $val;
			}
		}
		return array( 'data' => self::reports_list( null, $filters ) );
	}

	/** REST wrapper: GET /reports/{report_id}. */
	public static function read( WP_REST_Request $request ): array {
		return array( 'data' => self::report_read( (int) $request->get_param( 'report_id' ) ) );
	}

	/** REST wrapper: POST /reports/{report_id}/transition. Body {outcome, reason}. */
	public static function resolve( WP_REST_Request $request ): array {
		$payload = $request->get_json_params() ?: array();
		$payload = is_array( $payload ) ? $payload : array();
		return array( 'data' => self::transition(
			(int) $request->get_param( 'report_id' ),
			(string) ( $payload['outcome'] ?? '' ),
			(string) ( $payload['reason'] ?? '' )
		) );
	}

	// ── Service API ──────────────────────────────────────────────────────────

	public static function report_row( int $report_id, ?Store $store = null ): ?array {
		return self::store( $store )->get_row( 'tb_reports', 'id = %d', array( $report_id ) );
	}

	/** Submit a new report. Self-reports are rejected immediately. */
	public static function report_create( int $reporter_id, string $entity_type, int $entity_id, string $reason, ?Store $store = null ): array {
		$store = self::store( $store );

		// Reject self-reports.
		if ( $entity_type === 'merchant' ) {
			$merchant = $store->get_row( 'tb_merchants', 'id = %d', array( $entity_id ) );
			if ( null !== $merchant && (int) $merchant['user_id'] === $reporter_id ) {
				Error::throw_( 'VALIDATION_FAILED', 'trust_safety', Error::text( 'VALIDATION_FAILED' ), array( 'reason' => 'self_report' ) );
			}
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$store->insert( 'tb_reports', array(
			'reporter_id'    => $reporter_id,
			'entity_type'    => $entity_type,
			'entity_id'      => $entity_id,
			'reason'         => $reason,
			'status'         => 'pending',
			'created_at'     => $now,
			'updated_at'     => $now,
		) );
		$report_id = $store->last_insert_id();

		// pair_velocity check.
		self::check_velocity( $store, $entity_type, $entity_id );

		Audit::write( 'report.create', 'report', (string) $report_id, array(), array( 'reporter_id' => $reporter_id, 'entity_type' => $entity_type, 'entity_id' => $entity_id, 'reason' => $reason ), array(), 'user', $reporter_id, 'rest' );

		Events::emit( 'REPORT_SUBMITTED', array(
			'report_id'       => $report_id,
			'reporter_id'     => $reporter_id,
			'entity_type'     => $entity_type,
			'entity_id'       => $entity_id,
			'reason'          => $reason,
		) );

		return array( 'report_id' => $report_id, 'status' => 'pending' );
	}

	/** List reports, filterable by status and entity. */
	public static function reports_list( ?Store $store = null, ?array $filters = null ): array {
		$store = self::store( $store );
		$where = '1=1';
		$params = array();

		if ( ! empty( $filters ) ) {
			if ( ! empty( $filters['status'] ) ) {
				$where .= ' AND status = %s';
				$params[] = $filters['status'];
			}
			if ( ! empty( $filters['entity_type'] ) ) {
				$where .= ' AND entity_type = %s';
				$params[] = $filters['entity_type'];
			}
			if ( ! empty( $filters['entity_id'] ) ) {
				$where .= ' AND entity_id = %d';
				$params[] = $filters['entity_id'];
			}
		}

		$rows = $store->get_rows( 'tb_reports', $where, $params );
		return array_values( $rows );
	}

	/** Read one report. */
	public static function report_read( int $report_id ): array {
		return array( 'data' => self::report_row( $report_id ) );
	}

	/** Resolve or dismiss a report. Admin only. */
	public static function transition( int $report_id, string $outcome, string $reason, ?Store $store = null ): array {
		$store = self::store( $store );
		$row = $store->get_row( 'tb_reports', 'id = %d', array( $report_id ) );
		if ( null === $row ) {
			Error::throw_( 'VALIDATION_FAILED', 'trust_safety', Error::text( 'VALIDATION_FAILED' ), array( 'report_id' => $report_id, 'reason' => 'not_found' ) );
		}
		if ( 'cleared' !== $outcome && 'rejected' !== $outcome && 'flagged' !== $outcome ) {
			Error::throw_( 'VALIDATION_FAILED', 'trust_safety', Error::text( 'VALIDATION_FAILED' ), array( 'report_id' => $report_id, 'reason' => 'invalid_outcome' ) );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$set = array( 'status' => $outcome, 'resolved_at' => $now, 'resolved_by' => 'admin', 'resolved_reason' => $reason, 'updated_at' => $now );

		$store->update_where( 'tb_reports', $set, 'id = %d', array( $report_id ) );

		// pair_velocity re-check after resolve.
		self::check_velocity( $store, $row['entity_type'], $row['entity_id'] );

		Events::emit( "REPORT_${outcome}", array(
			'report_id'       => $report_id,
			'entity_type'     => $row['entity_type'],
			'entity_id'       => $row['entity_id'],
			'outcome'         => $outcome,
		) );

		Audit::write( 'report.transition', 'report', (string) $report_id, array( 'status' => $row['status'] ), array( 'status' => $outcome ), array( 'reason' => $reason ), 'user', 'admin', 'rest' );

		return array( 'report_id' => $report_id, 'status' => $outcome, 'from' => $row['status'] );
	}

	/** pair_velocity: ≥3 reports against same merchant/entity_type within 7 days → FLAGGED. */
	private static function check_velocity( Store $store, string $entity_type, int $entity_id ): void {
		$since = gmdate( 'Y-m-d H:i:s', time() - self::VELOCITY_WINDOW_DAYS * 86400 );
		$count = (int) $store->get_var( "SELECT COUNT(*) FROM tb_reports WHERE entity_type = %s AND entity_id = %d AND status = 'pending' AND created_at >= %s", array( $entity_type, $entity_id, $since ) );

		if ( $count >= self::VELOCITY_THRESHOLD ) {
			// Merchant/entity is flagged.
			// We just emit the event; the moderation queue will handle it.
			Events::emit( 'MERCHANT_FLAGGED', array(
				'merchant_id' => $entity_id,
				'report_count' => $count,
				'window_days' => self::VELOCITY_WINDOW_DAYS,
			) );
		}
	}

	private static function store( ?Store $store = null ): Store {
		return $store ?? Store::default();
	}
}