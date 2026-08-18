<?php
declare( strict_types=1 );

namespace Trade\Requests;

use Trade\Catalog\Service as CatalogService;
use Trade\Core\Audit;
use Trade\Core\Error;
use Trade\Core\Events;
use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Listings\Service as ListingsService;
use Trade\Merchant\Service as MerchantService;
use Trade\Search\Service as SearchService;
use WP_REST_Request;

/**
 * Requests module — customer requests and rule-based merchant matching.
 *
 * States (§B.6.6): OPEN → MATCHED → FULFILLED (terminal)
 *                  OPEN / MATCHED → CANCELLED | EXPIRED (terminal)
 *   Default expires_at = 14 days; expiry is a worker job (not wired here).
 *
 * Matching (§B.11.5): rule-based — category (required), location within region
 *   (required), budget (merchant holds ≥1 ACTIVE listing in category priced
 *   ≤ budget_max × 1.2), then ranked by §B.11.3 Search::rank. Cap 10 merchants
 *   per request; 3 request notifications per merchant per day.
 */
final class Service {

	/** §B.6.6 — every edge is [actors]: customer | system. */
	private const TRANSITIONS = array(
		'OPEN' => array(
			'MATCHED'   => array( 'actors' => array( 'system' ) ),
			'CANCELLED' => array( 'actors' => array( 'customer' ) ),
			'EXPIRED'   => array( 'actors' => array( 'system' ) ),
		),
		'MATCHED' => array(
			'FULFILLED' => array( 'actors' => array( 'customer' ), 'requires_order' => true ),
			'CANCELLED' => array( 'actors' => array( 'customer' ) ),
			'EXPIRED'   => array( 'actors' => array( 'system' ) ),
		),
		// FULFILLED, CANCELLED, EXPIRED: terminal.
	);

	/** §B.6.6 — default lifetime for a new request (14 days). */
	public const DEFAULT_EXPIRY_SECONDS = 14 * 86400;

	/** §B.11.5 — maximum merchants returned per request. */
	public const MAX_MATCHES = 10;

	/** §B.11.5 — budget tolerance factor (price ≤ budget_max × this). */
	public const BUDGET_TOLERANCE = 1.2;

	/** §B.11.5 — max request notifications to a merchant per day. */
	public const NOTIF_DAILY_CAP = 3;

	/** Allowed urgency values. */
	private const URGENCIES = array( 'low', 'normal', 'high', 'urgent' );

	public static function routes(): void {
		Rest::register( 'requests', 'GET', 'tb_manage_own_requests', array( self::class, 'requests_list' ) );
		Rest::register( 'requests', 'POST', 'tb_manage_own_requests', array( self::class, 'request_create' ) );
		Rest::register( 'requests/(?P<id>[0-9]+)', 'GET', 'tb_manage_own_requests', array( self::class, 'request_read' ) );
		Rest::register( 'requests/(?P<id>[0-9]+)/transition', 'POST', 'tb_manage_own_requests', array( self::class, 'transition' ) );
		Rest::register( 'requests/(?P<id>[0-9]+)/matches', 'GET', 'tb_manage_own_requests', array( self::class, 'matches' ) );
	}

	// ── Service API (consumed by worker/notifications) ───────────────────────

	public static function request_row( int $request_id, ?Store $store = null ): ?array {
		return self::store( $store )->get_row( 'tb_customer_requests', 'id = %d', array( $request_id ) );
	}

	/**
	 * §B.6.6 — create an OPEN customer request, defaulting expires_at to 14 days.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function create_request( array $payload, int $customer_id, ?Store $store = null ): array {
		$store  = self::store( $store );
		$errors = array();

		$category_id  = isset( $payload['category_id'] ) ? (int) $payload['category_id'] : 0;
		$location_id  = isset( $payload['location_id'] ) ? (int) $payload['location_id'] : 0;
		$budget_max   = isset( $payload['budget_max'] ) ? (int) $payload['budget_max'] : 0;
		$urgency      = isset( $payload['urgency'] ) ? (string) $payload['urgency'] : 'normal';
		$attributes   = isset( $payload['attributes'] ) ? (array) $payload['attributes'] : array();

		if ( $category_id <= 0 ) {
			$errors[] = 'category_id';
		}
		if ( $location_id <= 0 ) {
			$errors[] = 'location_id';
		}
		if ( $budget_max < 0 ) {
			$errors[] = 'budget_max';
		}
		if ( ! in_array( $urgency, self::URGENCIES, true ) ) {
			$errors[] = 'urgency';
		}
		if ( $errors ) {
			throw Error::validation( array_values( array_unique( $errors ) ), 'requests' );
		}

		if ( ! CatalogService::get_category( $category_id, $store ) ) {
			Error::throw_( 'CATEGORY_NOT_FOUND', 'requests', Error::text( 'CATEGORY_NOT_FOUND' ), array( 'category_id' => $category_id ) );
		}
		if ( ! CatalogService::location_exists( $location_id, $store ) ) {
			Error::throw_( 'LOCATION_NOT_FOUND', 'requests', Error::text( 'LOCATION_NOT_FOUND' ), array( 'location_id' => $location_id ) );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$store->insert( 'tb_customer_requests', array(
			'customer_id'     => $customer_id,
			'category_id'     => $category_id,
			'attributes_json' => $attributes ? wp_json_encode( $attributes ) : null,
			'budget_max'      => $budget_max,
			'location_id'     => $location_id,
			'urgency'         => $urgency,
			'status'          => 'OPEN',
			'fulfilled_order_id' => null,
			'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + self::DEFAULT_EXPIRY_SECONDS ),
			'created_at'      => $now,
			'updated_at'      => $now,
		) );
		$request_id = $store->last_insert_id();

		Audit::write( 'request.create', 'request', (string) $request_id, array(), self::format_request( $store->get_row( 'tb_customer_requests', 'id = %d', array( $request_id ) ) ), array( 'category_id' => $category_id, 'location_id' => $location_id ), 'user', (string) $customer_id, 'rest' );

		return array( 'request_id' => $request_id, 'status' => 'OPEN' );
	}

	/**
	 * §B.11.5 — run rule-based matching for a request. Idempotent: re-runs
	 * replace prior matches with the current eligible set.
	 *
	 * @return array<string, mixed>
	 */
	public static function run_matching( int $request_id, ?Store $store = null ): array {
		$store   = self::store( $store );
		$request = self::request_row( $request_id, $store );
		if ( null === $request ) {
			Error::throw_( 'REQUEST_NOT_FOUND', 'requests', Error::text( 'REQUEST_NOT_FOUND' ), array( 'request_id' => $request_id ) );
		}
		if ( ! in_array( (string) $request['status'], array( 'OPEN', 'MATCHED' ), true ) ) {
			return array( 'request_id' => $request_id, 'matches' => array() );
		}

		$category_id = (int) $request['category_id'];
		$budget_max  = (int) $request['budget_max'];
		$loc_region  = self::region_root( (int) $request['location_id'], $store );
		$price_ceil  = (int) ( $budget_max * self::BUDGET_TOLERANCE );
		$today       = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );

		// Candidate listings: active, in-category, in-region, within budget.
		$candidates = array();
		foreach ( $store->get_rows( 'tb_listings', '1=1' ) as $row ) {
			if ( 'ACTIVE' !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			if ( (int) ( $row['category_id'] ?? 0 ) !== $category_id ) {
				continue;
			}
			if ( self::region_root( (int) ( $row['location_id'] ?? 0 ), $store ) !== $loc_region ) {
				continue;
			}
			if ( 0 < $price_ceil && (int) ( $row['price'] ?? 0 ) > $price_ceil ) {
				continue;
			}
			$candidates[] = $row;
		}

		// §B.11.3 rank, best listing per merchant, drop merchants over daily cap.
		$daily = self::daily_cap_merchants( $store, $today );
		$best  = array(); // merchant_id => ['score', 'listing_id']
		foreach ( $candidates as $row ) {
			$merchant_id = (int) ( $row['merchant_id'] ?? 0 );
			$score  = SearchService::rank( $row, array(), array( 'location_id' => (int) $request['location_id'] ), $store );
			$cur    = $best[ $merchant_id ] ?? null;
			if ( null === $cur || $score > $cur['score'] ) {
				$best[ $merchant_id ] = array( 'score' => $score, 'listing_id' => (int) $row['id'] );
			}
		}
		// Sort desc by score, keep top MAX_MATCHES.
		uasort( $best, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		$keep = array_slice( $best, 0, self::MAX_MATCHES, true );

		// Persist matches (replace prior set for this request).
		$store->delete_where( 'tb_request_matches', 'request_id = %d', array( $request_id ) );
		$merchant_ids = array();
		foreach ( $keep as $merchant_id => $m ) {
			if ( in_array( $merchant_id, $daily, true ) ) {
				continue; // §B.11.5 — merchant already at daily notification cap.
			}
			$store->insert( 'tb_request_matches', array(
				'request_id'   => $request_id,
				'merchant_id'  => $merchant_id,
				'listing_id'   => $m['listing_id'],
				'score'        => $m['score'],
				'notified_at'  => gmdate( 'Y-m-d H:i:s' ),
			) );
			$merchant_ids[] = $merchant_id;
		}

		// Promote OPEN→MATCHED if any matches landed, then emit event.
		if ( $merchant_ids && 'OPEN' === (string) $request['status'] ) {
			$store->update_where( 'tb_customer_requests', array( 'status' => 'MATCHED', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), 'id = %d', array( $request_id ) );
		}
		if ( $merchant_ids ) {
			Events::emit( 'REQUEST_MATCHED', array( 'request_id' => $request_id, 'merchant_ids' => $merchant_ids ) );
		}

		return array( 'request_id' => $request_id, 'matches' => $merchant_ids );
	}

	/**
	 * §B.6.6 — apply a transition for $actor (customer|system).
	 * FULFILLED requires an order_id owned by the customer that matches a match listing.
	 *
	 * @return array<string, mixed>
	 */
	public static function apply_transition( array $row, string $to, string $actor, array $extra = array(), ?Store $store = null ): array {
		$store     = self::store( $store );
		$request_id = (int) $row['id'];
		$from      = (string) $row['status'];

		if ( ! isset( self::TRANSITIONS[ $from ][ $to ] ) ) {
			Error::throw_( 'REQUEST_INVALID_TRANSITION', 'requests', Error::text( 'REQUEST_INVALID_TRANSITION' ), array( 'request_id' => $request_id, 'from' => $from, 'to' => $to ) );
		}
		$spec = self::TRANSITIONS[ $from ][ $to ];
		if ( ! in_array( $actor, $spec['actors'], true ) ) {
			Error::throw_( 'REQUEST_INVALID_TRANSITION', 'requests', Error::text( 'REQUEST_INVALID_TRANSITION' ), array( 'request_id' => $request_id, 'from' => $from, 'to' => $to, 'actor' => $actor ) );
		}

		$now  = gmdate( 'Y-m-d H:i:s' );
		$set  = array( 'status' => $to, 'updated_at' => $now );

		if ( 'FULFILLED' === $to ) {
			$order_id = (int) ( $extra['order_id'] ?? 0 );
			if ( $order_id <= 0 ) {
				throw Error::validation( array( 'order_id' ), 'requests' );
			}
			self::assert_customer_order_against_match( $store, $row, $order_id );
			$set['fulfilled_order_id'] = $order_id;
		}

		$store->update_where( 'tb_customer_requests', $set, 'id = %d', array( $request_id ) );

		$events = array( 'request_id' => $request_id, 'customer_id' => (int) $row['customer_id'] );
		if ( 'FULFILLED' === $to ) {
			$events['order_id'] = (int) ( $extra['order_id'] ?? 0 );
			Events::emit( 'REQUEST_FULFILLED', $events );
		} elseif ( 'EXPIRED' === $to ) {
			Events::emit( 'REQUEST_EXPIRED', $events );
		} elseif ( 'CANCELLED' === $to ) {
			$events['cancelled_by'] = $actor;
			Events::emit( 'REQUEST_CANCELLED', $events );
		}

		Audit::write( 'request.transition', 'request', (string) $request_id, array( 'status' => $from ), array( 'status' => $to ), array( 'actor' => $actor ), 'user', $actor, 'rest' );

		return array( 'request_id' => $request_id, 'status' => $to, 'from' => $from );
	}

	/** §B.6.6 — worker job: expire OPEN/MATCHED requests past expires_at. */
	public static function expire_due( ?Store $store = null ): int {
		$store = self::store( $store );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$count = 0;
		foreach ( $store->get_rows( 'tb_customer_requests', '1=1' ) as $row ) {
			if ( ! in_array( (string) $row['status'], array( 'OPEN', 'MATCHED' ), true ) ) {
				continue;
			}
			if ( strtotime( (string) $row['expires_at'] ) >= strtotime( $now ) ) {
				continue;
			}
			self::apply_transition( $row, 'EXPIRED', 'system', array(), $store );
			$count++;
		}
		return $count;
	}

	/** §B.11.5 — formatted matches for a request (lazy: runs matching on first read). */
	public static function get_matches( int $request_id, ?Store $store = null ): array {
		$store   = self::store( $store );
		$request = self::request_row( $request_id, $store );
		if ( null === $request ) {
			Error::throw_( 'REQUEST_NOT_FOUND', 'requests', Error::text( 'REQUEST_NOT_FOUND' ), array( 'request_id' => $request_id ) );
		}
		if ( in_array( (string) $request['status'], array( 'OPEN', 'MATCHED' ), true ) ) {
			self::run_matching( $request_id, $store );
			$request = self::request_row( $request_id, $store );
		}
		$rows = $store->get_rows( 'tb_request_matches', 'request_id = %d', array( $request_id ) );
		usort( $rows, static fn( $a, $b ) => (float) ( $b['score'] ?? 0 ) <=> (float) ( $a['score'] ?? 0 ) );
		return array( 'status' => (string) ( $request['status'] ?? '' ), 'matches' => array_map( static fn( $r ) => self::format_match( $r ), $rows ) );
	}

	// ── REST controllers ─────────────────────────────────────────────────────

	public static function requests_list( WP_REST_Request $request ): array {
		$user_id = get_current_user_id();
		$rows    = array();
		foreach ( self::store()->get_rows( 'tb_customer_requests', '1=1' ) as $row ) {
			if ( (int) $row['customer_id'] === $user_id || current_user_can( 'manage_options' ) ) {
				$rows[] = self::format_request( $row );
			}
		}
		usort( $rows, static fn( $a, $b ) => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) ) );
		return array( 'data' => $rows );
	}

	public static function request_read( WP_REST_Request $request ): array {
		return array( 'data' => self::find_visible( (int) $request->get_param( 'id' ) ) );
	}

	public static function request_create( WP_REST_Request $request ): array {
		$payload = $request->get_json_params() ?: array();
		return array( 'data' => self::create_request( is_array( $payload ) ? $payload : array(), get_current_user_id() ) );
	}

	public static function transition( WP_REST_Request $request ): array {
		$req = self::find_visible( (int) $request->get_param( 'id' ) );
		$row = self::request_row( (int) $req['request_id'] );
		$actor = 'customer';
		if ( current_user_can( 'manage_options' ) ) {
			$actor = 'system';
		}
		$payload = $request->get_json_params() ?: array();
		$payload = is_array( $payload ) ? $payload : array();
		$to      = (string) ( $payload['to'] ?? '' );
		$extra   = array( 'order_id' => isset( $payload['order_id'] ) ? (int) $payload['order_id'] : 0 );
		return array( 'data' => self::apply_transition( $row, $to, $actor, $extra ) );
	}

	public static function matches( WP_REST_Request $request ): array {
		self::find_visible( (int) $request->get_param( 'id' ) );
		return array( 'data' => self::get_matches( (int) $request->get_param( 'id' ) ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/** Walk the location parent chain to the region (root) id. */
	private static function region_root( int $location_id, Store $store ): int {
		$seen = array();
		while ( $location_id > 0 && ! isset( $seen[ $location_id ] ) ) {
			$seen[ $location_id ] = true;
			$loc = $store->get_row( 'tb_locations', 'id = %d', array( $location_id ) );
			$parent = $loc ? (int) ( $loc['parent_id'] ?? 0 ) : 0;
			if ( $parent <= 0 ) {
				return $location_id;
			}
			$location_id = $parent;
		}
		return $location_id;
	}

	/** Merchants already at the §B.11.5 daily notification cap. */
	private static function daily_cap_merchants( Store $store, string $since ): array {
		$ids = array();
		foreach ( $store->get_rows( 'tb_request_matches', 'notified_at >= %s', array( $since ) ) as $m ) {
			$mid = (int) ( $m['merchant_id'] ?? 0 );
			$ids[ $mid ] = ( $ids[ $mid ] ?? 0 ) + 1;
		}
		return array_keys( array_filter( $ids, static fn( $n ) => $n >= self::NOTIF_DAILY_CAP ) );
	}

	/** FULFILLED order must be the caller's own and correspond to a matched listing. */
	private static function assert_customer_order_against_match( Store $store, array $request, int $order_id ): void {
		$order = $store->get_row( 'tb_orders', 'id = %d', array( $order_id ) );
		if ( null === $order ) {
			Error::throw_( 'ORDER_NOT_FOUND', 'orders', Error::text( 'ORDER_NOT_FOUND' ), array( 'order_id' => $order_id ) );
		}
		if ( (int) $order['customer_id'] !== (int) $request['customer_id'] ) {
			Error::throw_( 'FORBIDDEN_NOT_OWNER', 'core', Error::text( 'FORBIDDEN_NOT_OWNER' ), array( 'order_id' => $order_id ) );
		}
		if ( 'COMPLETED' !== (string) $order['status'] ) {
			Error::throw_( 'REQUEST_INVALID_TRANSITION', 'requests', Error::text( 'REQUEST_INVALID_TRANSITION' ), array( 'reason' => 'order_not_completed' ) );
		}
	}

	/** Load a request and enforce the caller owns it (or is admin). */
	private static function find_visible( int $request_id ): array {
		$row = self::request_row( $request_id );
		if ( null === $row ) {
			Error::throw_( 'REQUEST_NOT_FOUND', 'requests', Error::text( 'REQUEST_NOT_FOUND' ), array( 'request_id' => $request_id ) );
		}
		$user_id = get_current_user_id();
		if ( (int) $row['customer_id'] !== $user_id && ! current_user_can( 'manage_options' ) ) {
			Error::throw_( 'REQUEST_NOT_FOUND', 'requests', Error::text( 'REQUEST_NOT_FOUND' ), array( 'request_id' => $request_id ) );
		}
		return self::format_request( $row );
	}

	/** @return array<string, mixed> */
	private static function format_request( array $row ): array {
		return array(
			'request_id'       => (int) $row['id'],
			'customer_id'      => (int) $row['customer_id'],
			'category_id'      => (int) $row['category_id'],
			'attributes'       => ( $row['attributes_json'] ?? null ) ? (array) json_decode( (string) $row['attributes_json'], true ) : array(),
			'budget_max'       => (int) $row['budget_max'],
			'location_id'      => (int) $row['location_id'],
			'urgency'          => (string) $row['urgency'],
			'status'           => (string) $row['status'],
			'fulfilled_order_id' => isset( $row['fulfilled_order_id'] ) && null !== $row['fulfilled_order_id'] ? (int) $row['fulfilled_order_id'] : null,
			'expires_at'       => $row['expires_at'] ?? null,
			'created_at'       => $row['created_at'] ?? null,
			'updated_at'       => $row['updated_at'] ?? null,
		);
	}

	/** @return array<string, mixed> */
	private static function format_match( array $row ): array {
		return array(
			'merchant_id' => (int) $row['merchant_id'],
			'listing_id'  => (int) $row['listing_id'],
			'score'       => round( (float) ( $row['score'] ?? 0 ), 4 ),
			'notified_at' => $row['notified_at'] ?? null,
		);
	}

	private static function store( ?Store $store = null ): Store {
		return $store ?? Store::default();
	}
}
