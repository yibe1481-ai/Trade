<?php
declare( strict_types=1 );

namespace Trade\Verification;

use Trade\Core\Audit;
use Trade\Core\Error;
use Trade\Core\Events;
use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Merchant\Service as MerchantService;
use WP_REST_Request;

/**
 * Verification module — merchant document review workflow (§B.6.5).
 *
 * States: NONE → {PENDING, VERIFIED, REJECTED}; VERIFIED → REVOKED (terminal);
 *   REVOKED cascades: all ACTIVE listings → PAUSED.
 *
 * Events:
 *   MERCHANT_VERIFIED     {merchant_id, reviewed_by}   pending → verified
 *   MERCHANT_VERIFICATION_REJECTED {merchant_id, reviewed_by, note} pending → rejected
 *   MERCHANT_VERIFICATION_REVOKED {merchant_id, revoked_by, reason} verified → revoked
 *     → all merchant ACTIVE listings → PAUSED
 */
final class Service {

	/** NONE → PENDING via create_verification.
	 *  PENDING → VERIFIED via apply_transition( 'VERIFIED', reviewer ).
	 *  PENDING → REJECTED via apply_transition( 'REJECTED', reviewer, [ 'note' ] ).
	 *  VERIFIED → REVOKED via apply_transition( 'REVOKED', admin, [ 'reason' ] ).
	 *  REVOKED: terminal.
	 */
	private const TRANSITIONS = array(
		'NONE' => array(
			'PENDING'   => array( 'actors' => array( 'system' ) ),
		),
		'PENDING' => array(
			'VERIFIED' => array( 'actors' => array( 'system' ) ),
			'REJECTED' => array( 'actors' => array( 'system' ) ),
		),
		'VERIFIED' => array(
			'REVOKED' => array( 'actors' => array( 'admin' ), 'requires_reason' => true ),
		),
		// REVOKED: terminal (no outgoing edges).
	);

	public static function routes(): void {
		Rest::register( 'verification', 'POST', 'tb_manage_own_merchant_profile', array( self::class, 'create' ) );
		Rest::register( 'verification/(?P<merchant_id>[0-9]+)', 'GET', 'tb_manage_own_merchant_profile', array( self::class, 'read' ) );
		Rest::register( 'verification/(?P<merchant_id>[0-9]+)/transition', 'POST', 'tb_manage_own_merchant_profile', array( self::class, 'transition' ) );
	}

	/** REST wrapper: POST /verification. Body {merchant_id}. */
	public static function create( WP_REST_Request $request ): array {
		$payload = $request->get_json_params() ?: array();
		$payload = is_array( $payload ) ? $payload : array();
		return array( 'data' => self::create_verification( (int) ( $payload['merchant_id'] ?? 0 ) ) );
	}

	/** REST wrapper: GET /verification/{merchant_id}. */
	public static function read( WP_REST_Request $request ): array {
		return array( 'data' => self::read_verification( (int) $request->get_param( 'merchant_id' ) ) );
	}

	// ── Service API ──────────────────────────────────────────────────────────

	public static function merchant_row( int $merchant_id, ?Store $store = null ): ?array {
		return self::store( $store )->get_row( 'tb_merchants', 'id = %d', array( $merchant_id ) );
	}

	/** NONE → PENDING: create a verification request for a merchant. */
	public static function create_verification( int $merchant_id, ?Store $store = null ): array {
		$store = self::store( $store );
		$row = $store->get_row( 'tb_merchants', 'id = %d', array( $merchant_id ) );
		if ( null === $row ) {
			Error::throw_( 'MERCHANT_NOT_FOUND', 'verification', Error::text( 'MERCHANT_NOT_FOUND' ), array( 'merchant_id' => $merchant_id ) );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$store->update_where( 'tb_merchants', array( 'verification_status' => 'pending' ), 'id = %d', array( $merchant_id ) );

		// Insert a default document record.
		$store->insert( 'tb_verification_documents', array(
			'merchant_id'    => $merchant_id,
			'document_type'  => 'profile',
			'storage_key'    => '',
			'status'         => 'pending',
			'created_at'     => $now,
			'updated_at'     => $now,
		) );

		Audit::write( 'verification.create', 'merchant', (string) $merchant_id, array(), array( 'verification_status' => 'pending' ), array(), 'user', $merchant_id, 'rest' );

		return array( 'merchant_id' => $merchant_id, 'status' => 'pending' );
	}

	/** PENDING → VERIFIED or REJECTED. Requires reason (which document outcome). */
	public static function apply_transition( array $row, string $to, string $actor, array $extra = array(), ?Store $store = null ): array {
		$store     = self::store( $store );
		$merchant_id = (int) $row['id'];
		$from      = (string) $row['verification_status'];

		if ( ! isset( self::TRANSITIONS[ $from ][ $to ] ) ) {
			Error::throw_( 'REQUEST_INVALID_TRANSITION', 'verification', Error::text( 'REQUEST_INVALID_TRANSITION' ), array( 'merchant_id' => $merchant_id, 'from' => $from, 'to' => $to ) );
		}
		$spec = self::TRANSITIONS[ $from ][ $to ];
		if ( ! in_array( $actor, $spec['actors'], true ) ) {
			Error::throw_( 'REQUEST_INVALID_TRANSITION', 'verification', Error::text( 'REQUEST_INVALID_TRANSITION' ), array( 'merchant_id' => $merchant_id, 'from' => $from, 'to' => $to, 'actor' => $actor ) );
		}
		if ( ! empty( $spec['requires_reason'] ) && '' === trim( $extra['reason'] ?? '' ) ) {
			throw Error::validation( array( 'reason' ), 'verification' );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$set = array( 'verification_status' => $to, 'updated_at' => $now );

		if ( 'VERIFIED' === $to ) {
			$store->update_where( 'tb_verification_documents', array( 'status' => 'verified', 'verified_at' => $now ), 'merchant_id = %d', array( $merchant_id ) );
			Events::emit( 'MERCHANT_VERIFIED', array( 'merchant_id' => $merchant_id, 'reviewed_by' => $actor ) );
		} elseif ( 'REJECTED' === $to ) {
			$note = $extra['reason'] ?? '';
			$store->update_where( 'tb_verification_documents', array( 'status' => 'rejected', 'revoked_at' => $now, 'revocation_reason' => $note ), 'merchant_id = %d', array( $merchant_id ) );
			Events::emit( 'MERCHANT_VERIFICATION_REJECTED', array( 'merchant_id' => $merchant_id, 'reviewed_by' => $actor, 'note' => $note ) );
		} elseif ( 'REVOKED' === $to ) {
			$reason = $extra['reason'] ?? '';
			$store->update_where( 'tb_merchants', array( 'verification_status' => 'revoked' ), 'id = %d', array( $merchant_id ) );
			$store->update_where( 'tb_verification_documents', array( 'status' => 'revoked', 'revoked_at' => $now, 'revocation_reason' => $reason ), 'merchant_id = %d', array( $merchant_id ) );
			// Cascade: pause all ACTIVE listings for this merchant.
			foreach ( $store->get_rows( 'tb_listings', 'merchant_id = %d AND status = %s', array( $merchant_id, 'ACTIVE' ) ) as $listing ) {
				ListingsService::apply_transition( $listing, 'PAUSED', 'admin', '', $store );
			}
			Events::emit( 'MERCHANT_VERIFICATION_REVOKED', array( 'merchant_id' => $merchant_id, 'revoked_by' => $actor, 'reason' => $reason ) );
		}

		Audit::write( 'verification.transition', 'merchant', (string) $merchant_id, array( 'status' => $from ), array( 'status' => $to ), array( 'actor' => $actor, 'reason' => $extra['reason'] ?? '' ), 'user', $actor, 'rest' );

		return array( 'merchant_id' => $merchant_id, 'status' => $to, 'from' => $from );
	}

	/** Read current verification state for a merchant. */
	public static function read_verification( int $merchant_id ): array {
		return array( 'data' => self::merchant_row( $merchant_id ) );
	}

	/** REST wrapper: POST /verification/{merchant_id}/transition. Body {to, reason?}. */
	public static function transition( WP_REST_Request $request ): array {
		$merchant_id = (int) $request->get_param( 'merchant_id' );
		$row = self::merchant_row( $merchant_id );
		if ( null === $row ) {
			Error::throw_( 'MERCHANT_NOT_FOUND', 'verification', Error::text( 'MERCHANT_NOT_FOUND' ), array( 'merchant_id' => $merchant_id ) );
		}
		$payload = $request->get_json_params() ?: array();
		$payload = is_array( $payload ) ? $payload : array();
		$actor = current_user_can( 'manage_options' ) ? 'admin' : 'system';
		return array( 'data' => self::apply_transition( $row, (string) ( $payload['to'] ?? '' ), $actor, array( 'reason' => (string) ( $payload['reason'] ?? '' ) ) ) );
	}

	private static function store( ?Store $store = null ): Store {
		return $store ?? Store::default();
	}
}