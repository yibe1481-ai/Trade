<?php
declare( strict_types=1 );

namespace Trade\Orders;

use Trade\Core\Audit;
use Trade\Core\Error;
use Trade\Core\Events;
use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Listings\Service as ListingsService;
use WP_REST_Request;

/**
 * Orders module — the order lifecycle, DM trust loop, and reviews.
 *
 * States (§B.6.2): REQUESTED → {ACCEPTED, CANCELLED, EXPIRED, DISPUTED}
 *                         ACCEPTED → {COMPLETED, CANCELLED, DISPUTED}
 *   COMPLETED / CANCELLED / EXPIRED / DISPUTED are terminal.
 *
 * Invariants:
 *   - One open REQUESTED order per (customer, listing).
 *   - COMPLETED requires dual confirmation (§B.6.3): both customer AND merchant
 *     must confirm; single-sided confirmation never resolves to COMPLETED.
 *   - Stock decrements on ACCEPTED only (§B.7.2, atomic conditional UPDATE).
 *   - One review per COMPLETED order, within a 30-day window (§B.6.4).
 */
final class Service {

	/** §B.6.2 — every edge is [actors]: customer | merchant | system. */
	private const TRANSITIONS = array(
		'REQUESTED' => array(
			'ACCEPTED'  => array( 'actors' => array( 'merchant' ) ),
			'CANCELLED' => array( 'actors' => array( 'customer', 'merchant' ), 'requires_reason' => true ),
			'EXPIRED'   => array( 'actors' => array( 'system' ) ),
			'DISPUTED'  => array( 'actors' => array( 'customer', 'merchant' ), 'requires_reason' => true ),
		),
		'ACCEPTED' => array(
			'COMPLETED' => array( 'actors' => array( 'customer', 'merchant' ) ),
			'CANCELLED' => array( 'actors' => array( 'customer', 'merchant' ), 'requires_reason' => true ),
			'DISPUTED'  => array( 'actors' => array( 'customer', 'merchant' ), 'requires_reason' => true ),
		),
		// COMPLETED, CANCELLED, EXPIRED, DISPUTED: terminal (no outgoing edges).
	);

	/** §B.6.4 — review must be written within 30 days of completion. */
	private const REVIEW_WINDOW_SECONDS = 2592000; // 30 days.

	public static function routes(): void {
		Rest::register( 'orders', 'GET', 'tb_manage_own_orders', array( self::class, 'orders_list' ) );
		Rest::register( 'orders', 'POST', 'tb_manage_own_orders', array( self::class, 'order_create' ) );
		Rest::register( 'orders/(?P<id>[0-9]+)', 'GET', 'tb_manage_own_orders', array( self::class, 'order_read' ) );
		Rest::register( 'orders/(?P<id>[0-9]+)/transition', 'POST', 'tb_manage_own_orders', array( self::class, 'transition' ) );
		Rest::register( 'orders/(?P<id>[0-9]+)/review', 'GET', 'tb_manage_own_orders', array( self::class, 'review_read' ) );
		Rest::register( 'orders/(?P<id>[0-9]+)/review', 'POST', 'tb_manage_own_orders', array( self::class, 'review_create' ) );
	}

	// ── Service API (consumed by worker/notifications) ───────────────────────

	public static function order_row( int $order_id, ?Store $store = null ): ?array {
		return self::store( $store )->get_row( 'tb_orders', 'id = %d', array( $order_id ) );
	}

	/** §B.6.4 — read the review for an order (null if none yet). */
	public static function review_row( int $order_id, ?Store $store = null ): ?array {
		return self::store( $store )->get_row( 'tb_reviews', 'order_id = %d', array( $order_id ) );
	}

	/**
	 * §B.6.2 — create a REQUESTED order for a customer against an active listing.
	 * Snapshot price/qty from the listing at order time.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function create_order( array $payload, int $customer_id, ?Store $store = null ): array {
		$store = self::store( $store );
		$errors = array();

		$listing_id = isset( $payload['listing_id'] ) ? (int) $payload['listing_id'] : 0;
		$qty        = isset( $payload['qty'] ) ? (int) $payload['qty'] : 1;

		if ( $listing_id <= 0 ) {
			$errors[] = 'listing_id';
		}
		if ( $qty < 1 || $qty > 99 ) {
			$errors[] = 'qty';
		}
		if ( $errors ) {
			throw Error::validation( array_values( array_unique( $errors ) ), 'orders' );
		}

		// Listing must be live and owned by a merchant (§B.6.2).
		if ( ! ListingsService::listing_is_active( $listing_id, $store ) ) {
			Error::throw_( 'LISTING_NOT_AVAILABLE', 'orders', Error::text( 'LISTING_NOT_AVAILABLE' ), array( 'listing_id' => $listing_id ) );
		}
		$listing    = ListingsService::find_listing_row( $listing_id, $store );
		$merchant_id = (int) ( $listing['merchant_id'] ?? 0 );

		// One open REQUESTED order per (customer, listing).
		$open = $store->get_row(
			'tb_orders',
			'customer_id = %d AND listing_id = %d AND status = %s',
			array( $customer_id, $listing_id, 'REQUESTED' )
		);
		if ( null !== $open ) {
			Error::throw_( 'ORDER_ALREADY_OPEN', 'orders', Error::text( 'ORDER_ALREADY_OPEN' ), array( 'customer_id' => $customer_id, 'listing_id' => $listing_id ) );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$store->insert( 'tb_orders', array(
			'customer_id'          => $customer_id,
			'merchant_id'          => $merchant_id,
			'listing_id'           => $listing_id,
			'price'                => (int) ( $listing['price'] ?? 0 ),
			'currency'             => (string) ( $listing['currency'] ?? 'ETB' ),
			'qty'                  => $qty,
			'status'               => 'REQUESTED',
			'customer_confirmed_at' => null,
			'merchant_confirmed_at' => null,
			'cancel_reason'        => null,
			'disputed_by'          => null,
			'created_at'           => $now,
			'updated_at'           => $now,
		) );
		$order_id = $store->last_insert_id();

		Events::emit( 'ORDER_CREATED', array(
			'order_id'    => $order_id,
			'customer_id' => $customer_id,
			'merchant_id' => $merchant_id,
			'listing_id'  => $listing_id,
			'source'      => 'web',
		) );

		Audit::write( 'order.create', 'order', (string) $order_id, array(), self::format_order( $store->get_row( 'tb_orders', 'id = %d', array( $order_id ) ) ), array( 'listing_id' => $listing_id ), 'user', (string) $customer_id, 'rest' );

		return array( 'order_id' => $order_id, 'status' => 'REQUESTED' );
	}

	/**
	 * §B.6.2 — apply a transition for $actor (customer|merchant|system).
	 * COMPLETED is special (§B.6.3): it only resolves when both sides have confirmed.
	 *
	 * @return array<string, mixed>
	 */
	public static function apply_transition( array $row, string $to, string $actor, string $reason = '', ?Store $store = null ): array {
		$store    = self::store( $store );
		$order_id = (int) $row['id'];
		$from     = (string) $row['status'];

		if ( ! isset( self::TRANSITIONS[ $from ][ $to ] ) ) {
			Error::throw_( 'ORDER_INVALID_TRANSITION', 'orders', Error::text( 'ORDER_INVALID_TRANSITION' ), array( 'order_id' => $order_id, 'from' => $from, 'to' => $to ) );
		}
		$spec = self::TRANSITIONS[ $from ][ $to ];
		if ( ! in_array( $actor, $spec['actors'], true ) ) {
			Error::throw_( 'ORDER_INVALID_TRANSITION', 'orders', Error::text( 'ORDER_INVALID_TRANSITION' ), array( 'order_id' => $order_id, 'from' => $from, 'to' => $to, 'actor' => $actor ) );
		}
		if ( ! empty( $spec['requires_reason'] ) && '' === trim( $reason ) ) {
			throw Error::validation( array( 'reason' ), 'orders' );
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		// §B.6.3 — dual confirmation: record this side; resolve only when both set.
		if ( 'COMPLETED' === $to ) {
			return self::confirm_completion( $store, $row, $actor );
		}

		// §B.7.2 — atomic stock decrement on ACCEPTED only.
		if ( 'ACCEPTED' === $to ) {
			$ok = ListingsService::decrement_stock( (int) $row['listing_id'], (int) ( $row['qty'] ?? 1 ), $store );
			if ( ! $ok ) {
				Error::throw_( 'INVENTORY_INSUFFICIENT_STOCK', 'orders', Error::text( 'INVENTORY_INSUFFICIENT_STOCK' ), array( 'order_id' => $order_id, 'listing_id' => (int) $row['listing_id'] ) );
			}
		}

		$set = array( 'status' => $to, 'updated_at' => $now );
		if ( 'CANCELLED' === $to ) {
			$set['cancel_reason'] = $reason;
		}
		if ( 'DISPUTED' === $to ) {
			$set['disputed_by'] = $actor;
		}
		$store->update_where( 'tb_orders', $set, 'id = %d', array( $order_id ) );

		$events = array(
			'order_id'    => $order_id,
			'customer_id' => (int) $row['customer_id'],
			'merchant_id' => (int) $row['merchant_id'],
		);
		if ( 'CANCELLED' === $to ) {
			$events['cancelled_by'] = $actor;
			$events['reason']       = $reason;
		}
		if ( 'DISPUTED' === $to ) {
			$events['raised_by'] = $actor;
			$events['reason']    = $reason;
		}
		Events::emit( 'ORDER_' . $to, $events );

		Audit::write( 'order.transition', 'order', (string) $order_id, array( 'status' => $from ), array( 'status' => $to ), array( 'actor' => $actor, 'reason' => $reason ), 'user', $actor, 'rest' );

		return array( 'order_id' => $order_id, 'status' => $to, 'from' => $from );
	}

	/**
	 * §B.6.4 — write a review for a COMPLETED order, once, within 30 days.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function create_review( array $payload, int $customer_id, ?Store $store = null ): array {
		$store = self::store( $store );
		$errors = array();

		$order_id = isset( $payload['order_id'] ) ? (int) $payload['order_id'] : 0;
		$rating   = isset( $payload['rating'] ) ? (int) $payload['rating'] : 0;
		$comment  = isset( $payload['comment'] ) ? (string) $payload['comment'] : '';

		if ( $order_id <= 0 ) {
			$errors[] = 'order_id';
		}
		if ( $rating < 1 || $rating > 5 ) {
			$errors[] = 'rating';
		}
		if ( mb_strlen( $comment ) > 1000 ) {
			$errors[] = 'comment';
		}
		if ( $errors ) {
			throw Error::validation( array_values( array_unique( $errors ) ), 'orders' );
		}

		$order = $store->get_row( 'tb_orders', 'id = %d', array( $order_id ) );
		if ( null === $order ) {
			Error::throw_( 'ORDER_NOT_FOUND', 'orders', Error::text( 'ORDER_NOT_FOUND' ), array( 'order_id' => $order_id ) );
		}
		if ( (int) $order['customer_id'] !== $customer_id ) {
			Error::throw_( 'FORBIDDEN_NOT_OWNER', 'core', Error::text( 'FORBIDDEN_NOT_OWNER' ), array( 'order_id' => $order_id ) );
		}
		if ( 'COMPLETED' !== (string) $order['status'] ) {
			Error::throw_( 'REVIEW_NOT_ELIGIBLE', 'orders', Error::text( 'REVIEW_NOT_ELIGIBLE' ), array( 'order_id' => $order_id, 'reason' => 'not_completed' ) );
		}
		$completed_at = strtotime( (string) $order['updated_at'] );
		if ( $completed_at > 0 && ( time() - $completed_at ) > self::REVIEW_WINDOW_SECONDS ) {
			Error::throw_( 'REVIEW_NOT_ELIGIBLE', 'orders', Error::text( 'REVIEW_NOT_ELIGIBLE' ), array( 'order_id' => $order_id, 'reason' => 'window_closed' ) );
		}
		if ( null !== self::review_row( $order_id, $store ) ) {
			Error::throw_( 'REVIEW_NOT_ELIGIBLE', 'orders', Error::text( 'REVIEW_NOT_ELIGIBLE' ), array( 'order_id' => $order_id, 'reason' => 'already_reviewed' ) );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$store->insert( 'tb_reviews', array(
			'order_id'    => $order_id,
			'customer_id' => $customer_id,
			'merchant_id' => (int) $order['merchant_id'],
			'listing_id'  => (int) $order['listing_id'],
			'rating'      => $rating,
			'comment'     => $comment,
			'created_at'  => $now,
		) );
		$review_id = $store->last_insert_id();

		Events::emit( 'REVIEW_CREATED', array(
			'review_id'           => $review_id,
			'order_id'            => $order_id,
			'subject_merchant_id' => (int) $order['merchant_id'],
			'rating'              => $rating,
		) );

		Audit::write( 'review.create', 'review', (string) $review_id, array(), array( 'order_id' => $order_id, 'rating' => $rating ), array(), 'user', (string) $customer_id, 'rest' );

		return array( 'review_id' => $review_id, 'order_id' => $order_id, 'rating' => $rating );
	}

	// ── REST controllers ─────────────────────────────────────────────────────

	public static function orders_list( WP_REST_Request $request ): array {
		$user_id = get_current_user_id();
		$rows    = array();
		foreach ( self::store()->get_rows( 'tb_orders', '1=1' ) as $row ) {
			if ( (int) $row['customer_id'] === $user_id || (int) $row['merchant_id'] === $user_id || current_user_can( 'manage_options' ) ) {
				$rows[] = self::format_order( $row );
			}
		}
		// Newest first.
		usort( $rows, static fn( $a, $b ) => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) ) );
		return array( 'data' => $rows );
	}

	public static function order_read( WP_REST_Request $request ): array {
		$order = self::find_visible( (int) $request->get_param( 'id' ) );
		return array( 'data' => $order );
	}

	public static function order_create( WP_REST_Request $request ): array {
		$payload = $request->get_json_params() ?: array();
		return array( 'data' => self::create_order( is_array( $payload ) ? $payload : array(), get_current_user_id() ) );
	}

	public static function transition( WP_REST_Request $request ): array {
		$order  = self::find_visible( (int) $request->get_param( 'id' ) );
		$row    = self::order_row( (int) $order['order_id'] );
		$actor  = self::resolve_actor( $row );

		$payload = $request->get_json_params() ?: array();
		$payload = is_array( $payload ) ? $payload : array();
		$to      = (string) ( $payload['to'] ?? '' );
		$reason  = (string) ( $payload['reason'] ?? '' );

		return array( 'data' => self::apply_transition( $row, $to, $actor, $reason ) );
	}

	public static function review_read( WP_REST_Request $request ): array {
		$order  = self::find_visible( (int) $request->get_param( 'id' ) );
		$review = self::review_row( (int) $order['order_id'] );
		return array( 'data' => null === $review ? null : self::format_review( $review ) );
	}

	public static function review_create( WP_REST_Request $request ): array {
		$order   = self::find_visible( (int) $request->get_param( 'id' ) );
		$payload = $request->get_json_params() ?: array();
		$payload = is_array( $payload ) ? $payload : array();
		$payload['order_id'] = (int) $order['order_id'];

		return array( 'data' => self::create_review( $payload, get_current_user_id() ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/** §B.6.3 — record the confirming side; resolve COMPLETED only when both set. */
	private static function confirm_completion( Store $store, array $row, string $actor ): array {
		$order_id = (int) $row['id'];
		$col      = 'customer' === $actor ? 'customer_confirmed_at' : 'merchant_confirmed_at';

		if ( empty( $row[ $col ] ) ) {
			$store->update_where( 'tb_orders', array( $col => gmdate( 'Y-m-d H:i:s' ) ), 'id = %d', array( $order_id ) );
		}
		$fresh = $store->get_row( 'tb_orders', 'id = %d', array( $order_id ) );
		$both  = ! empty( $fresh['customer_confirmed_at'] ) && ! empty( $fresh['merchant_confirmed_at'] );

		if ( ! $both ) {
			Events::emit( 'ORDER_CONFIRMED_ONE_SIDE', array( 'order_id' => $order_id, 'confirmed_by' => $actor ) );
			return array( 'order_id' => $order_id, 'status' => 'ACCEPTED', 'completed' => false, 'confirmed_by' => $actor );
		}

		$store->update_where( 'tb_orders', array( 'status' => 'COMPLETED', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), 'id = %d', array( $order_id ) );
		Events::emit( 'ORDER_COMPLETED', array(
			'order_id'        => $order_id,
			'customer_id'     => (int) $fresh['customer_id'],
			'merchant_id'     => (int) $fresh['merchant_id'],
			'auto_reconciled' => false,
		) );
		return array( 'order_id' => $order_id, 'status' => 'COMPLETED', 'completed' => true );
	}

	/** Load an order and enforce the caller can see it. */
	private static function find_visible( int $order_id ): array {
		$row = self::order_row( $order_id );
		if ( null === $row ) {
			Error::throw_( 'ORDER_NOT_FOUND', 'orders', Error::text( 'ORDER_NOT_FOUND' ), array( 'order_id' => $order_id ) );
		}
		$user_id = get_current_user_id();
		if ( (int) $row['customer_id'] !== $user_id && (int) $row['merchant_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			Error::throw_( 'ORDER_NOT_FOUND', 'orders', Error::text( 'ORDER_NOT_FOUND' ), array( 'order_id' => $order_id ) );
		}
		return self::format_order( $row );
	}

	/** Map the acting WP user to an order actor role. */
	private static function resolve_actor( array $row ): string {
		$user_id = get_current_user_id();
		if ( (int) $row['customer_id'] === $user_id ) {
			return 'customer';
		}
		if ( (int) $row['merchant_id'] === $user_id || current_user_can( 'manage_options' ) ) {
			return 'merchant';
		}
		return 'system';
	}

	/** @return array<string, mixed> */
	private static function format_order( array $row ): array {
		return array(
			'order_id'   => (int) $row['id'],
			'customer_id' => (int) $row['customer_id'],
			'merchant_id' => (int) $row['merchant_id'],
			'listing_id' => (int) $row['listing_id'],
			'price'      => (int) $row['price'],
			'currency'   => (string) ( $row['currency'] ?? 'ETB' ),
			'qty'        => (int) $row['qty'],
			'status'     => (string) $row['status'],
			'completed'  => 'COMPLETED' === (string) $row['status'],
			'created_at' => $row['created_at'] ?? null,
			'updated_at' => $row['updated_at'] ?? null,
		);
	}

	/** @return array<string, mixed> */
	private static function format_review( array $row ): array {
		return array(
			'review_id'   => (int) $row['id'],
			'order_id'    => (int) $row['order_id'],
			'merchant_id' => (int) $row['merchant_id'],
			'listing_id'  => (int) $row['listing_id'],
			'rating'      => (int) $row['rating'],
			'comment'     => (string) ( $row['comment'] ?? '' ),
			'created_at'  => $row['created_at'] ?? null,
		);
	}

	private static function store( ?Store $store = null ): Store {
		return $store ?? Store::default();
	}
}
