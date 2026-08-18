<?php
declare( strict_types=1 );

namespace Trade\Listings;

use Trade\Catalog\Service as CatalogService;
use Trade\Core\Audit;
use Trade\Core\Error;
use Trade\Core\Events;
use Trade\Core\Jobs;
use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Identity\Session;
use Trade\Localization\Lang;
use Trade\Merchant\Service as MerchantService;
use WP_REST_Request;

/**
 * Listings module — merchant listings of catalog products (and variants),
 * per-listing inventory or service availability, the §B.6.1 state machine,
 * server-derived listing images (§B.9.4), and the §B.11.1 search_text column.
 */
final class Service {

	/** §B.6.1 — every row is [actors]. 'enter_active' fires LISTING_PUBLISHED. */
	private const TRANSITIONS = array(
		'DRAFT' => array(
			'PENDING_REVIEW' => array( 'actors' => array( 'merchant' ) ),
		),
		'PENDING_REVIEW' => array(
			'ACTIVE'   => array( 'actors' => array( 'admin' ), 'enter_active' => true ),
			'REJECTED' => array( 'actors' => array( 'admin' ) ),
		),
		'ACTIVE' => array(
			'PAUSED'       => array( 'actors' => array( 'merchant', 'admin', 'system' ) ),
			'OUT_OF_STOCK' => array( 'actors' => array( 'system' ) ),
			'ARCHIVED'     => array( 'actors' => array( 'merchant', 'admin' ) ),
		),
		'PAUSED' => array(
			'ACTIVE'   => array( 'actors' => array( 'merchant', 'admin' ), 'enter_active' => true ),
			'ARCHIVED' => array( 'actors' => array( 'merchant', 'admin' ) ),
		),
		'OUT_OF_STOCK' => array(
			'ACTIVE'   => array( 'actors' => array( 'system' ), 'enter_active' => true ),
			'ARCHIVED' => array( 'actors' => array( 'merchant', 'admin' ) ),
		),
		// REJECTED, ARCHIVED: terminal (no outgoing edges).
	);

	/** Any user who can view the merchant can view active listings — no session needed. */
	private const PUBLIC_STATES = array( 'ACTIVE', 'PAUSED', 'OUT_OF_STOCK' );

	/** States that count toward the merchant's active_listings entitlement. */
	private const COUNTED_STATES = array( 'PENDING_REVIEW', 'ACTIVE' );

	/** §B.7.4 — service-type availability states. */
	private const AVAILABILITY_STATES = array( 'AVAILABLE_TODAY', 'AVAILABLE_THIS_WEEK', 'BOOKED', 'UNAVAILABLE' );

	public static function routes(): void {
		Rest::register( 'listings', 'POST', 'tb_manage_own_listings', array( self::class, 'listing_create' ) );
		Rest::register( 'listings', 'GET', '', array( self::class, 'listings' ) );
		Rest::register( 'listings/(?P<id>[0-9]+)', 'GET', '', array( self::class, 'listing_read' ) );
		Rest::register( 'listings/(?P<id>[0-9]+)', 'PATCH', 'tb_manage_own_listings', array( self::class, 'listing_update' ) );
		Rest::register( 'listings/(?P<id>[0-9]+)/status', 'POST', 'tb_manage_own_listings', array( self::class, 'status_transition' ) );
		Rest::register( 'listings/(?P<id>[0-9]+)/images', 'POST', 'tb_manage_own_listings', array( self::class, 'image_upload' ) );
		Rest::register( 'listings/(?P<id>[0-9]+)/images', 'GET', '', array( self::class, 'images_list' ) );
		Rest::register( 'listings/(?P<id>[0-9]+)/images/(?P<image_id>[0-9]+)', 'DELETE', 'tb_manage_own_listings', array( self::class, 'image_delete' ) );
		Rest::register( 'inventory/(?P<listing_id>[0-9]+)', 'PATCH', 'tb_manage_own_listings', array( self::class, 'inventory_update' ) );
		Rest::register( 'availability/(?P<listing_id>[0-9]+)', 'PATCH', 'tb_manage_own_listings', array( self::class, 'availability_update' ) );
	}

	// ── Service API (consumed by orders/search/requests) ──────────────────────

	public static function find_listing( int $listing_id, ?Store $store = null ): ?array {
		$row = self::find_listing_row( $listing_id, $store );
		return null === $row ? null : self::format_listing( $row, $store );
	}

	public static function find_listing_row( int $listing_id, ?Store $store = null ): ?array {
		return self::store( $store )->get_row( 'tb_listings', 'id = %d', array( $listing_id ) );
	}

	public static function listing_is_active( int $listing_id, ?Store $store = null ): bool {
		$row = self::find_listing_row( $listing_id, $store );
		return null !== $row && 'ACTIVE' === (string) ( $row['status'] ?? '' );
	}

	public static function require_active_listing( int $listing_id, ?Store $store = null ): void {
		if ( ! self::listing_is_active( $listing_id, $store ) ) {
			Error::throw_( 'LISTING_NOT_AVAILABLE', 'listings', Error::text( 'LISTING_NOT_AVAILABLE' ), array( 'listing_id' => $listing_id ) );
		}
	}

	public static function merchant_owns_listing( int $listing_id, int $merchant_id, ?Store $store = null ): bool {
		$row = self::store( $store )->get_row(
			'tb_listings',
			'id = %d AND merchant_id = %d',
			array( $listing_id, $merchant_id )
		);
		return null !== $row;
	}

	/** §B.7.2 — atomic conditional stock decrement; returns true on success. */
	public static function decrement_stock( int $listing_id, int $qty, ?Store $store = null ): bool {
		if ( $qty <= 0 ) {
			return false;
		}
		$store = self::store( $store );
		$now   = gmdate( 'Y-m-d H:i:s' );

		$affected = $store->update_expr(
			'tb_inventory',
			'stock = stock - %d, version = version + 1, updated_at = %s',
			array( $qty, $now ),
			'listing_id = %d AND stock >= %d',
			array( $listing_id, $qty )
		);

		if ( $affected > 0 ) {
			// §B.6.1: ACTIVE → OUT_OF_STOCK when stock hits 0 (system transition).
			$inv = $store->get_row( 'tb_inventory', 'listing_id = %d', array( $listing_id ) );
			if ( $inv && 0 === (int) ( $inv['stock'] ?? 0 ) ) {
				$listing = self::find_listing_row( $listing_id, $store );
				if ( $listing && 'ACTIVE' === (string) ( $listing['status'] ?? '' ) ) {
					self::apply_transition( $listing, 'OUT_OF_STOCK', null, 'system', '', $store );
				}
			}
		}

		return $affected > 0;
	}

	/** §B.11.1 — rebuild search_text from product + merchant + category + attribute values. */
	public static function rebuild_search_text( int $listing_id, ?Store $store = null ): void {
		$store  = self::store( $store );
		$parts  = array();
		$listing = $store->get_row( 'tb_listings', 'id = %d', array( $listing_id ) );
		if ( ! $listing ) {
			return;
		}

		$product = $store->get_row( 'tb_products', 'id = %d', array( (int) $listing['product_id'] ) );
		if ( $product ) {
			$parts[] = (string) $product['canonical_name'];
			$parts[] = mb_strtolower( (string) $product['canonical_name'] );

			$attrs = self::json_decode_array( (string) ( $product['attributes_json'] ?? '' ) );
			foreach ( $attrs as $v ) {
				$parts[] = mb_strtolower( (string) $v );
			}

			$category = CatalogService::get_category( (int) $product['category_id'], $store );
			if ( $category ) {
				$parts[] = Lang::text( (string) $category['name_key'], 'en' );
				$parts[] = Lang::text( (string) $category['name_key'], 'am' );
			}
		}

		$merchant = MerchantService::find_merchant( (int) $listing['merchant_id'], $store );
		if ( $merchant ) {
			$parts[] = mb_strtolower( (string) $merchant['business_name'] );
		}

		self::store( $store )->update_where(
			'tb_listings',
			array( 'search_text' => implode( ' ', $parts ) ),
			'id = %d',
			array( $listing_id )
		);
	}

	// ── REST controllers ─────────────────────────────────────────────────────

	public static function listing_create( WP_REST_Request $request ): array {
		$payload = $request->get_json_params() ?: array();
		$merchant_id = get_current_user_id();
		return array( 'data' => self::create_listing( is_array( $payload ) ? $payload : array(), $merchant_id ) );
	}

	public static function listings( WP_REST_Request $request ): array {
		$filters = self::collection_filters( $request );
		if ( null !== $filters['merchant_id'] ) {
			$viewer_id = get_current_user_id();
			if ( (int) $filters['merchant_id'] !== (int) $viewer_id ) {
				Error::throw_( 'FORBIDDEN_NOT_OWNER', 'core', Error::text( 'FORBIDDEN_NOT_OWNER' ), array( 'merchant_id' => $filters['merchant_id'] ) );
			}
		}
		return self::paginated( self::list_listings_rows( $filters ), $filters['page'], $filters['per_page'] );
	}

	public static function listing_read( WP_REST_Request $request ): array {
		$listing_id = (int) $request->get_param( 'id' );
		$listing    = self::find_listing( $listing_id );
		if ( null === $listing ) {
			Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id ) );
		}
		// Public visibility: non-owners only see publicly-visible statuses.
		if ( ! in_array( (string) $listing['status'], self::PUBLIC_STATES, true ) ) {
			$viewer_id = get_current_user_id();
			if ( (int) $listing['merchant_id'] !== (int) $viewer_id && ! current_user_can( 'manage_options' ) ) {
				Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id ) );
			}
		}
		return array( 'data' => $listing );
	}

	public static function listing_update( WP_REST_Request $request ): array {
		$listing_id = (int) $request->get_param( 'id' );
		$listing    = self::find_listing_row( $listing_id );
		if ( null === $listing ) {
			Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id ) );
		}
		self::require_ownership( $listing );
		$actor_id = get_current_user_id();

		$payload = $request->get_json_params() ?: array();
		$payload = is_array( $payload ) ? $payload : array();
		$set     = self::normalize_patch( $payload, $listing );
		$version = isset( $payload['version'] ) ? (int) $payload['version'] : 0;
		if ( $version !== (int) ( $listing['version'] ?? 0 ) ) {
			Error::throw_( 'CONFLICT_STALE_VERSION', 'core', Error::text( 'CONFLICT_STALE_VERSION' ), array( 'listing_id' => $listing_id ) );
		}

		$before = self::format_listing( $listing );

		// §B.7.3 — atomic update with version check in WHERE.
		$where_sql = 'id = %d AND version = %d';
		$where_args = array( $listing_id, $version );
		$affected = self::store()->update_where( 'tb_listings', $set, $where_sql, $where_args );
		if ( 0 === $affected ) {
			Error::throw_( 'CONFLICT_STALE_VERSION', 'core', Error::text( 'CONFLICT_STALE_VERSION' ), array( 'listing_id' => $listing_id ) );
		}

		$listing = array_merge( $listing, $set );
		self::rebuild_search_text( $listing_id );
		$after = self::format_listing( $listing );

		Audit::write( 'listing.update', 'listing', (string) $listing_id, $before, $after, array( 'fields' => array_keys( $set ) ), 'user', (string) $actor_id, 'rest' );

		return array( 'data' => $after );
	}

	public static function status_transition( WP_REST_Request $request ): array {
		$listing_id = (int) $request->get_param( 'id' );
		$payload    = $request->get_json_params() ?: array();
		$payload    = is_array( $payload ) ? $payload : array();

		$to      = isset( $payload['to'] ) ? (string) $payload['to'] : '';
		$note    = isset( $payload['note'] ) ? (string) $payload['note'] : '';
		$version = isset( $payload['version'] ) ? (int) $payload['version'] : 0;

		$listing = self::find_listing_row( $listing_id );
		if ( null === $listing ) {
			Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id ) );
		}
		self::require_ownership( $listing );

		$actor = current_user_can( 'manage_options' ) ? 'admin' : 'merchant';

		$transitions = self::apply_transition( $listing, $to, $version, $actor, $note, null );

		Audit::write( 'listing.status', 'listing', (string) $listing_id, array( 'status' => (string) $listing['status'] ), $transitions, array( 'actor' => $actor, 'note' => $note ), 'user', (string) get_current_user_id(), 'rest' );

		return array( 'data' => $transitions );
	}

	public static function image_upload( WP_REST_Request $request ): array {
		$listing_id  = (int) $request->get_param( 'id' );
		$image_id   = (int) $request->get_param( 'image_id' ); // not used — path param is id (listing)
		$listing     = self::find_listing_row( $listing_id );
		if ( null === $listing ) {
			Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id ) );
		}
		self::require_ownership( $listing );

		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! is_array( $file ) || (int) ( $file['error'] ?? 4 ) !== UPLOAD_ERR_OK ) {
			throw Error::validation( array( 'file' ), 'listings' );
		}

		$storage_key = self::assign_storage_key();
		// # ponytail: actual file persistence (wp_handle_upload) belongs in the
		// listing.image_process worker; the controller only stows the server key.
		$result = self::create_image( $listing_id, $storage_key, (int) $listing['merchant_id'] );

		return array( 'data' => $result );
	}

	public static function images_list( WP_REST_Request $request ): array {
		$listing_id = (int) $request->get_param( 'id' );
		$listing    = self::find_listing_row( $listing_id );
		if ( null === $listing ) {
			Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id ) );
		}
		// Public visibility gate (same as listing_read).
		if ( ! in_array( (string) $listing['status'], self::PUBLIC_STATES, true ) ) {
			$viewer_id = get_current_user_id();
			if ( (int) $listing['merchant_id'] !== (int) $viewer_id && ! current_user_can( 'manage_options' ) ) {
				Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id ) );
			}
		}
		$rows = self::store()->get_rows( 'tb_listing_images', 'listing_id = %d', array( $listing_id ) );
		usort( $rows, static fn( $a, $b ) => ( (int) ( $a['sort_order'] ?? 0 ) <=> (int) ( $b['sort_order'] ?? 0 ) ) ?: ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) ) );
		return array( 'data' => array_map( array( self::class, 'format_image' ), $rows ) );
	}

	public static function image_delete( WP_REST_Request $request ): array {
		$listing_id  = (int) $request->get_param( 'id' );
		$image_id    = (int) $request->get_param( 'image_id' );
		$listing     = self::find_listing_row( $listing_id );
		if ( null === $listing ) {
			Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id ) );
		}
		self::require_ownership( $listing );

		$image = self::store()->get_row( 'tb_listing_images', 'id = %d AND listing_id = %d', array( $image_id, $listing_id ) );
		if ( null === $image ) {
			Error::throw_( 'LISTING_IMAGE_NOT_FOUND', 'listings', Error::text( 'LISTING_IMAGE_NOT_FOUND' ), array( 'image_id' => $image_id, 'listing_id' => $listing_id ) );
		}

		$actor_id = get_current_user_id();
		self::store()->delete_where( 'tb_listing_images', 'id = %d', array( $image_id ) );
		// Re-sort remaining images to keep a contiguous sequence.
		self::resort_images( $listing_id );

		Audit::write( 'listing.image_delete', 'listing_image', (string) $image_id, $image, array(), array( 'listing_id' => $listing_id ), 'user', (string) $actor_id, 'rest' );

		return array( 'data' => array( 'deleted' => true ) );
	}

	public static function inventory_update( WP_REST_Request $request ): array {
		$listing_id = (int) $request->get_param( 'listing_id' );
		$listing    = self::find_listing_row( $listing_id );
		if ( null === $listing ) {
			Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id ) );
		}
		self::require_ownership( $listing );

		$payload = $request->get_json_params() ?: array();
		$payload = is_array( $payload ) ? $payload : array();
		$version = isset( $payload['version'] ) ? (int) $payload['version'] : 0;

		$inv = self::store()->get_row( 'tb_inventory', 'listing_id = %d', array( $listing_id ) );
		if ( null === $inv ) {
			Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id, 'reason' => 'inventory' ) );
		}
		if ( $version !== (int) ( $inv['version'] ?? 0 ) ) {
			Error::throw_( 'CONFLICT_STALE_VERSION', 'core', Error::text( 'CONFLICT_STALE_VERSION' ), array( 'listing_id' => $listing_id ) );
		}

		$errors = array();
		$set    = array( 'version' => (int) ( $inv['version'] ?? 0 ) + 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );

		if ( array_key_exists( 'stock', $payload ) ) {
			$stock = (int) $payload['stock'];
			if ( $stock < 0 ) {
				$errors[] = 'stock';
			} else {
				$set['stock'] = $stock;
			}
		}
		if ( array_key_exists( 'sku', $payload ) ) {
			$sku = trim( (string) $payload['sku'] );
			if ( mb_strlen( $sku ) > 191 ) {
				$errors[] = 'sku';
			} else {
				$set['sku'] = $sku;
			}
		}
		if ( $errors ) {
			throw Error::validation( $errors, 'listings' );
		}

		$affected = self::store()->update_where(
			'tb_inventory',
			$set,
			'listing_id = %d AND version = %d',
			array( $listing_id, $version )
		);
		if ( 0 === $affected ) {
			Error::throw_( 'CONFLICT_STALE_VERSION', 'core', Error::text( 'CONFLICT_STALE_VERSION' ), array( 'listing_id' => $listing_id ) );
		}

		// §B.6.1: system auto-restock / deplete transitions.
		$stock       = array_key_exists( 'stock', $set ) ? (int) $set['stock'] : (int) ( $inv['stock'] ?? 0 );
		$from_status = (string) ( $listing['status'] ?? '' );
		if ( 'ACTIVE' === $from_status && 0 === $stock ) {
			$listing = self::find_listing_row( $listing_id );
			self::apply_transition( $listing, 'OUT_OF_STOCK', null, 'system', '', null );
		} elseif ( 'OUT_OF_STOCK' === $from_status && $stock > 0 ) {
			$listing = self::find_listing_row( $listing_id );
			self::apply_transition( $listing, 'ACTIVE', null, 'system', '', null );
		}

		Audit::write(
			'inventory.update',
			'inventory',
			(string) $listing_id,
			array( 'stock' => (int) $inv['stock'], 'sku' => (string) $inv['sku'] ),
			array( 'stock' => $stock, 'sku' => (string) ( $set['sku'] ?? $inv['sku'] ) ),
			array( 'fields' => array_keys( $set ) ),
			'user',
			(string) get_current_user_id(),
			'rest'
		);

		return array( 'data' => array(
			'stock'   => $stock,
			'sku'     => (string) ( $set['sku'] ?? $inv['sku'] ),
			'version' => (int) ( $set['version'] ),
		) );
	}

	public static function availability_update( WP_REST_Request $request ): array {
		$listing_id = (int) $request->get_param( 'listing_id' );
		$listing    = self::find_listing_row( $listing_id );
		if ( null === $listing ) {
			Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id ) );
		}
		self::require_ownership( $listing );

		$payload = $request->get_json_params() ?: array();
		$payload = is_array( $payload ) ? $payload : array();
		$existing = self::store()->get_row( 'tb_service_availability', 'listing_id = %d', array( $listing_id ) );
		if ( null === $existing ) {
			Error::throw_( 'LISTING_NOT_FOUND', 'listings', Error::text( 'LISTING_NOT_FOUND' ), array( 'listing_id' => $listing_id, 'reason' => 'availability' ) );
		}

		$errors = array();
		$set    = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );

		if ( array_key_exists( 'availability_state', $payload ) ) {
			$state = (string) $payload['availability_state'];
			if ( ! in_array( $state, self::AVAILABILITY_STATES, true ) ) {
				$errors[] = 'availability_state';
			} else {
				$set['availability_state'] = $state;
			}
		}
		if ( array_key_exists( 'note', $payload ) ) {
			$set['note'] = (string) $payload['note'];
		}
		if ( $errors ) {
			throw Error::validation( $errors, 'listings' );
		}
		if ( ! array_key_exists( 'availability_state', $set ) ) {
			throw Error::validation( array( 'availability_state' ), 'listings' );
		}

		self::store()->update_where( 'tb_service_availability', $set, 'listing_id = %d', array( $listing_id ) );
		$after = self::store()->get_row( 'tb_service_availability', 'listing_id = %d', array( $listing_id ) );

		Audit::write(
			'availability.update',
			'availability',
			(string) $listing_id,
			$existing,
			$after,
			array(),
			'user',
			(string) get_current_user_id(),
			'rest'
		);

		return array( 'data' => array(
			'availability_state' => (string) ( $after['availability_state'] ?? '' ),
			'note'               => (string) ( $after['note'] ?? '' ),
		) );
	}

	// ── Event consumer: MERCHANT_VERIFICATION_REVOKED (§B.6.5) ────────────────

	/**
	 * Listed as the trade.MERCHANT_VERIFICATION_REVOKED listener in trade.php.
	 * Transitions all ACTIVE listings for the revoked merchant to PAUSED (system actor).
	 */
	public static function pause_on_revocation( array $payload ): void {
		$merchant_id = (int) ( $payload['merchant_id'] ?? 0 );
		if ( $merchant_id <= 0 ) {
			return;
		}
		$rows = self::store()->get_rows( 'tb_listings', 'merchant_id = %d AND status = %s', array( $merchant_id, 'ACTIVE' ) );
		foreach ( $rows as $row ) {
			self::apply_transition( $row, 'PAUSED', null, 'system', '', null );
		}
	}

	// ── Service-layer helpers ────────────────────────────────────────────────

	/** @return array{status:string, version:int, published_at:?string} */
	public static function apply_transition( array $row, string $to, ?int $version, string $actor, string $note, ?Store $store = null ): array {
		$store     = self::store( $store );
		$listing_id = (int) $row['id'];
		$from       = (string) $row['status'];

		// §B.7.3 optimistic locking (REST calls send version; system passes null to skip).
		if ( null !== $version && $version !== (int) ( $row['version'] ?? 0 ) ) {
			Error::throw_( 'CONFLICT_STALE_VERSION', 'core', Error::text( 'CONFLICT_STALE_VERSION' ), array( 'listing_id' => $listing_id ) );
		}

		$table = self::TRANSITIONS;
		if ( ! isset( $table[ $from ][ $to ] ) ) {
			Error::throw_( 'LISTING_INVALID_TRANSITION', 'listings', Error::text( 'LISTING_INVALID_TRANSITION' ), array( 'from' => $from, 'to' => $to ) );
		}
		$spec = $table[ $from ][ $to ];
		if ( ! in_array( $actor, $spec['actors'], true ) ) {
			if ( 'merchant' === $actor && in_array( 'admin', $spec['actors'], true ) ) {
				// Only admins can do this transition → ownership-style error for a merchant caller.
				Error::throw_( 'FORBIDDEN_NOT_OWNER', 'core', Error::text( 'FORBIDDEN_NOT_OWNER' ), array( 'listing_id' => $listing_id ) );
			}
			Error::throw_( 'LISTING_INVALID_TRANSITION', 'listings', Error::text( 'LISTING_INVALID_TRANSITION' ), array( 'from' => $from, 'to' => $to, 'actor' => $actor ) );
		}

		// Guards per transition edge.
		if ( 'DRAFT' === $from && 'PENDING_REVIEW' === $to ) {
			MerchantService::require_verified_merchant( (int) $row['merchant_id'], $store );
			self::check_required_attributes( (int) $row['product_id'], $store );
			self::check_active_listings_limit( (int) $row['merchant_id'], $store );
		}
		if ( 'PENDING_REVIEW' === $from && 'REJECTED' === $to ) {
			if ( '' === trim( $note ) ) {
				throw Error::validation( array( 'note' ), 'listings' );
			}
		}

		// Atomic apply — include version in WHERE for REST calls (§B.7.3).
		$new_version  = (int) ( $row['version'] ?? 0 ) + 1;
		$set          = array( 'status' => $to, 'version' => $new_version, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		$published_at = isset( $row['published_at'] ) && null !== $row['published_at'] && '' !== (string) $row['published_at'] ? (string) $row['published_at'] : null;
		$enter_active = 'ACTIVE' === $to;
		if ( $enter_active && null === $published_at ) {
			$published_at = gmdate( 'Y-m-d H:i:s' );
			$set['published_at'] = $published_at;
		}

		if ( null !== $version ) {
			$affected = $store->update_where( 'tb_listings', $set, 'id = %d AND version = %d', array( $listing_id, $version ) );
			if ( 0 === $affected ) {
				Error::throw_( 'CONFLICT_STALE_VERSION', 'core', Error::text( 'CONFLICT_STALE_VERSION' ), array( 'listing_id' => $listing_id ) );
			}
		} else {
			$store->update_where( 'tb_listings', $set, 'id = %d', array( $listing_id ) );
		}

		// Events.
		if ( $enter_active ) {
			Events::emit( 'LISTING_PUBLISHED', array(
				'listing_id'   => $listing_id,
				'merchant_id'  => (int) $row['merchant_id'],
				'product_id'   => (int) $row['product_id'],
				'category_id'  => (int) $row['category_id'],
				'published_at' => $published_at,
			) );
		}
		if ( 'REJECTED' === $to ) {
			Events::emit( 'LISTING_REJECTED', array(
				'listing_id'  => $listing_id,
				'merchant_id' => (int) $row['merchant_id'],
				'reviewed_by' => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				'note'        => $note,
			) );
		}
		if ( 'ARCHIVED' === $to ) {
			Events::emit( 'LISTING_ARCHIVED', array(
				'listing_id'  => $listing_id,
				'merchant_id' => (int) $row['merchant_id'],
			) );
			self::delete_images( $listing_id, $store );
		}

		return array(
			'status'       => $to,
			'version'      => $new_version,
			'published_at' => $published_at,
		);
	}

	public static function create_listing( array $payload, int $merchant_id, ?Store $store = null ): array {
		$store    = self::store( $store );
		$errors   = array();
		$product_id = isset( $payload['product_id'] ) ? (int) $payload['product_id'] : 0;
		$variant_id  = isset( $payload['variant_id'] ) ? (int) $payload['variant_id'] : 0;
		$price       = isset( $payload['price'] ) ? (int) $payload['price'] : 0;
		$currency    = isset( $payload['currency'] ) ? (string) $payload['currency'] : 'ETB';
		$location_id = isset( $payload['location_id'] ) ? (int) $payload['location_id'] : 0;

		if ( $product_id <= 0 ) {
			$errors[] = 'product_id';
		}
		if ( $price <= 0 ) {
			$errors[] = 'price';
		}
		if ( strlen( $currency ) !== 3 ) {
			$errors[] = 'currency';
		}
		if ( $location_id <= 0 || ! CatalogService::location_exists( $location_id, $store ) ) {
			$errors[] = 'location_id';
		}

		$product = $product_id > 0 ? $store->get_row( 'tb_products', 'id = %d', array( $product_id ) ) : null;
		if ( $product_id > 0 && ! $product ) {
			$errors[] = 'product_id';
		}
		if ( $product && $variant_id > 0 ) {
			$variants = CatalogService::get_product_variants( $product_id, $store );
			$found    = false;
			foreach ( $variants as $v ) {
				if ( (int) $v['id'] === $variant_id ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$errors[] = 'variant_id';
			}
		}

		if ( $errors ) {
			throw Error::validation( array_values( array_unique( $errors ) ), 'listings' );
		}

		$category_id = (int) $product['category_id'];
		$category = CatalogService::get_category( $category_id, $store );
		$now = gmdate( 'Y-m-d H:i:s' );

		$store->insert( 'tb_listings', array(
			'merchant_id'  => $merchant_id,
			'product_id'   => $product_id,
			'variant_id'   => $variant_id > 0 ? $variant_id : null,
			'category_id'  => $category_id,
			'price'        => $price,
			'currency'     => $currency,
			'location_id'  => $location_id,
			'status'       => 'DRAFT',
			'published_at' => null,
			'search_text'  => '',
			'version'      => 1,
			'created_at'   => $now,
			'updated_at'   => $now,
		) );
		$listing_id = $store->last_insert_id();

		// §B.6.2: product-type → inventory row, service-type → availability row.
		if ( 'product' === (string) ( $category['type'] ?? '' ) ) {
			$store->insert( 'tb_inventory', array(
				'listing_id' => $listing_id,
				'stock'      => 0,
				'sku'        => '',
				'updated_at' => $now,
				'version'    => 1,
			) );
		} else {
			$store->insert( 'tb_service_availability', array(
				'listing_id'           => $listing_id,
				'availability_state'   => 'unavailable',
				'note'                 => '',
				'updated_at'           => $now,
			) );
		}

		self::rebuild_search_text( $listing_id, $store );

		$listing = self::find_listing_row( $listing_id, $store );

		Audit::write(
			'listing.create',
			'listing',
			(string) $listing_id,
			array(),
			self::format_listing( $listing, $store ),
			array( 'variant_id' => $variant_id > 0 ? $variant_id : null ),
			'user',
			(string) $merchant_id,
			'rest'
		);

		Events::emit( 'LISTING_CREATED', array( 'listing_id' => $listing_id, 'merchant_id' => $merchant_id ) );

		return self::format_listing( $listing, $store );
	}

	/** @return array<string, mixed> */
	private static function normalize_patch( array $payload, array $row ): array {
		$set = array();
		if ( array_key_exists( 'price', $payload ) ) {
			$price = (int) $payload['price'];
			if ( $price <= 0 ) {
				throw Error::validation( array( 'price' ), 'listings' );
			}
			$set['price'] = $price;
		}
		if ( array_key_exists( 'currency', $payload ) ) {
			$currency = (string) $payload['currency'];
			if ( strlen( $currency ) !== 3 ) {
				throw Error::validation( array( 'currency' ), 'listings' );
			}
			$set['currency'] = $currency;
		}
		if ( array_key_exists( 'location_id', $payload ) ) {
			$location_id = (int) $payload['location_id'];
			if ( $location_id <= 0 || ! CatalogService::location_exists( $location_id ) ) {
				throw Error::validation( array( 'location_id' ), 'listings' );
			}
			$set['location_id'] = $location_id;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		$set['updated_at'] = $now;
		return $set;
	}

	/** @return array<string, mixed>|null */
	public static function create_image( int $listing_id, string $storage_key, int $merchant_id, ?Store $store = null ): array {
		$store  = self::store( $store );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$images = $store->get_rows( 'tb_listing_images', 'listing_id = %d', array( $listing_id ) );

		// Image cap entitlement (§B.6.4).
		$limit = MerchantService::entitlement_int( $merchant_id, 'images_per_listing', 5, $store );
		if ( count( $images ) >= $limit ) {
			Error::throw_( 'ENTITLEMENT_LIMIT_REACHED', 'billing', Error::text( 'ENTITLEMENT_LIMIT_REACHED' ), array( 'listing_id' => $listing_id, 'limit' => $limit, 'current' => count( $images ) ) );
		}

		$sort_order = empty( $images ) ? 0 : max( array_map( static fn( $i ) => (int) ( $i['sort_order'] ?? 0 ), $images ) ) + 1;

		$store->insert( 'tb_listing_images', array(
			'listing_id'  => $listing_id,
			'storage_key' => $storage_key,
			'thumb_key'   => null,
			'sort_order'  => $sort_order,
			'created_at'  => $now,
		) );
		$image_id = $store->last_insert_id();

		// §B.9.4: enqueue server-derived thumbnail processing.
		$jobs = new Jobs( $store );
		$jobs->enqueue( 'listing.image_process', array( 'listing_id' => $listing_id, 'image_id' => $image_id ), array(
			'idempotency_key' => 'listing.image_process:' . $listing_id . ':' . $image_id,
		) );

		Audit::write(
			'listing.image_add',
			'listing_image',
			(string) $image_id,
			array(),
			array( 'listing_id' => $listing_id, 'storage_key' => $storage_key, 'sort_order' => $sort_order ),
			array(),
			'user',
			(string) $merchant_id,
			'rest'
		);

		return array(
			'image_id'  => $image_id,
			'image_url' => self::media_url( $storage_key ),
		);
	}

	private static function assign_storage_key(): string {
		// # ponytail: crypto_random_keys — unique by construction, 32 hex chars.
		return bin2hex( random_bytes( 16 ) );
	}

	/** @return array<string, mixed> */
	private static function format_image( array $row ): array {
		return array(
			'image_id'   => (int) $row['id'],
			'image_url'  => self::media_url( (string) $row['storage_key'] ),
			'thumb_url'  => empty( $row['thumb_key'] ) ? null : self::media_url( (string) $row['thumb_key'] ),
			'sort_order' => (int) $row['sort_order'],
		);
	}

	private static function media_url( string $key ): string {
		return '/trade-media/' . $key;
	}

	private static function delete_images( int $listing_id, ?Store $store = null ): void {
		$store = self::store( $store );
		$store->delete_where( 'tb_listing_images', 'listing_id = %d', array( $listing_id ) );
	}

	private static function resort_images( int $listing_id ): void {
		$store = self::store();
		$images = $store->get_rows( 'tb_listing_images', 'listing_id = %d', array( $listing_id ) );
		usort( $images, static fn( $a, $b ) => ( (int) ( $a['sort_order'] ?? 0 ) <=> (int) ( $b['sort_order'] ?? 0 ) ) ?: ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) ) );
		foreach ( $images as $i => $img ) {
			if ( (int) ( $img['sort_order'] ?? 0 ) !== $i ) {
				$store->update_where( 'tb_listing_images', array( 'sort_order' => $i ), 'id = %d', array( (int) $img['id'] ) );
			}
		}
	}

	/** @return array<string, mixed> */
	private static function format_listing( array $row, ?Store $store = null ): array {
		$store = self::store( $store );

		$product    = null !== ( $row['product_id'] ?? null ) ? $store->get_row( 'tb_products', 'id = %d', array( (int) $row['product_id'] ) ) : null;
		$category   = $product ? CatalogService::get_category( (int) $product['category_id'], $store ) : null;
		$merchant   = $row['merchant_id'] ? MerchantService::find_merchant( (int) $row['merchant_id'], $store ) : null;
		$variant    = null;
		if ( isset( $row['variant_id'] ) && '' !== (string) $row['variant_id'] && 0 !== (int) $row['variant_id'] ) {
			$variants = CatalogService::get_product_variants( (int) $row['product_id'], $store );
			foreach ( $variants as $v ) {
				if ( (int) $v['id'] === (int) $row['variant_id'] ) {
					$variant = $v;
					break;
				}
			}
		}

		$inventory    = $store->get_row( 'tb_inventory', 'listing_id = %d', array( (int) $row['id'] ) );
		$availability = $store->get_row( 'tb_service_availability', 'listing_id = %d', array( (int) $row['id'] ) );
		$cover        = $store->get_row( 'tb_listing_images', 'listing_id = %d ORDER BY sort_order', array( (int) $row['id'] ) );

		return array(
			'id'           => (int) $row['id'],
			'merchant_id'  => (int) $row['merchant_id'],
			'product_id'   => (int) $row['product_id'],
			'variant_id'   => isset( $row['variant_id'] ) && '' !== (string) $row['variant_id'] && 0 !== (int) $row['variant_id'] ? (int) $row['variant_id'] : null,
			'category_id'  => (int) $row['category_id'],
			'price'        => (int) $row['price'],
			'currency'     => (string) $row['currency'],
			'location_id'  => (int) $row['location_id'],
			'status'       => (string) ( $row['status'] ?? 'draft' ),
			'published_at' => isset( $row['published_at'] ) && null !== $row['published_at'] && '' !== (string) $row['published_at'] ? $row['published_at'] : null,
			'version'      => (int) $row['version'],
			'created_at'   => $row['created_at'] ?? null,
			'updated_at'   => $row['updated_at'] ?? null,
			'product'      => $product ? array(
				'canonical_name'    => (string) $product['canonical_name'],
				'category_name_key' => isset( $category['name_key'] ) ? (string) $category['name_key'] : null,
				'category_name'     => isset( $category['name_key'] ) ? Lang::text( (string) $category['name_key'], 'en' ) : null,
				'attributes_json'   => self::json_decode_array( (string) ( $product['attributes_json'] ?? '' ) ),
			) : null,
			'variant'      => $variant ? array(
				'variant_key'     => (string) $variant['variant_key'],
				'attributes_json' => self::json_decode_array( (string) ( $variant['attributes_json'] ?? '' ) ),
			) : null,
			'merchant'     => $merchant,
			'inventory'    => $inventory ? array(
				'stock'   => (int) $inventory['stock'],
				'sku'     => (string) $inventory['sku'],
				'version' => (int) $inventory['version'],
			) : null,
			'availability' => $availability ? array(
				'availability_state' => (string) $availability['availability_state'],
				'note'               => (string) $availability['note'],
			) : null,
			'cover_image'  => $cover ? array(
				'image_id' => (int) $cover['id'],
				'thumb_url' => empty( $cover['thumb_key'] ) ? null : self::media_url( (string) $cover['thumb_key'] ),
			) : null,
		);
	}

	private static function require_ownership( array $row ): void {
		$actor_id = get_current_user_id();
		if ( (int) $row['merchant_id'] !== (int) $actor_id && ! current_user_can( 'manage_options' ) ) {
			Error::throw_( 'FORBIDDEN_NOT_OWNER', 'core', Error::text( 'FORBIDDEN_NOT_OWNER' ), array( 'listing_id' => (int) $row['id'] ) );
		}
	}

	private static function check_required_attributes( int $product_id, ?Store $store = null ): void {
		$store   = self::store( $store );
		$product = $store->get_row( 'tb_products', 'id = %d', array( $product_id ) );
		if ( ! $product ) {
			throw Error::validation( array( 'product_id' ), 'listings' );
		}
		$defs      = CatalogService::get_category_attributes( (int) $product['category_id'], $store );
		$product_attrs = self::json_decode_array( (string) ( $product['attributes_json'] ?? '' ) );
		foreach ( $defs as $def ) {
			if ( (int) ( $def['required'] ?? 0 ) === 1 && ! array_key_exists( (string) $def['key'], $product_attrs ) ) {
				throw Error::validation( array( 'product_id' ), 'listings' );
			}
		}
	}

	private static function check_active_listings_limit( int $merchant_id, ?Store $store = null ): void {
		$store = self::store( $store );
		$limit = MerchantService::entitlement_int( $merchant_id, 'active_listings', 3, $store );
		if ( $limit <= 0 ) {
			return;
		}
		$count = 0;
		foreach ( $store->get_rows( 'tb_listings', 'merchant_id = %d', array( $merchant_id ) ) as $r ) {
			if ( in_array( (string) $r['status'], self::COUNTED_STATES, true ) ) {
				$count++;
			}
		}
		if ( $count >= $limit ) {
			Error::throw_( 'ENTITLEMENT_LIMIT_REACHED', 'billing', Error::text( 'ENTITLEMENT_LIMIT_REACHED' ), array( 'merchant_id' => $merchant_id, 'limit' => $limit, 'current' => $count ) );
		}
	}

	// ── Listing list + pagination helpers ────────────────────────────────────

	/** @return array<int, array<string, mixed>> */
	private static function list_listings_rows( array $filters ): array {
		$rows = self::store()->get_rows( 'tb_listings', '1=1' );
		$rows = array_values( array_filter( $rows, static function ( array $row ) use ( $filters ): bool {
			$status = (string) ( $row['status'] ?? '' );

			// Visibility: merchant_id filter → show all of that merchant's statuses.
			// No filter → public states only.
			if ( null !== $filters['merchant_id'] ) {
				if ( (int) ( $row['merchant_id'] ?? 0 ) !== (int) $filters['merchant_id'] ) {
					return false;
				}
			} else {
				if ( ! in_array( $status, self::PUBLIC_STATES, true ) ) {
					return false;
				}
			}
			if ( null !== $filters['category_id'] && (int) ( $row['category_id'] ?? 0 ) !== (int) $filters['category_id'] ) {
				return false;
			}
			if ( null !== $filters['location_id'] && (int) ( $row['location_id'] ?? 0 ) !== (int) $filters['location_id'] ) {
				return false;
			}
			if ( null !== $filters['price_min'] && (int) ( $row['price'] ?? 0 ) < (int) $filters['price_min'] ) {
				return false;
			}
			if ( null !== $filters['price_max'] && (int) ( $row['price'] ?? 0 ) > (int) $filters['price_max'] ) {
				return false;
			}
			return true;
		} ) );

		// Sort: published_at DESC, then id ASC (drafts without published_at sink to the end).
		usort( $rows, static function ( array $a, array $b ): int {
			$pa = (string) ( $a['published_at'] ?? '' );
			$pb = (string) ( $b['published_at'] ?? '' );
			$cmp = strcmp( $pb, $pa );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
		} );

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = self::format_listing( $row );
		}
		return $out;
	}

	private static function paginated( array $rows, int $page, int $per_page ): array {
		$total  = count( $rows );
		$offset = max( 0, $page - 1 ) * $per_page;
		return Rest::paginated( array_values( array_slice( $rows, $offset, $per_page ) ), $total, $page, $per_page );
	}

	private static function collection_filters( WP_REST_Request $request ): array {
		return array(
			'page'        => self::positive_int( $request->get_param( 'page' ), 1 ),
			'per_page'    => min( 100, self::positive_int( $request->get_param( 'per_page' ), 20 ) ),
			'category_id' => self::nullable_int( $request->get_param( 'category_id' ) ),
			'location_id' => self::nullable_int( $request->get_param( 'location_id' ) ),
			'price_min'   => self::nullable_int( $request->get_param( 'price_min' ) ),
			'price_max'   => self::nullable_int( $request->get_param( 'price_max' ) ),
			'merchant_id' => self::nullable_int( $request->get_param( 'merchant_id' ) ),
		);
	}

	private static function store( ?Store $store = null ): Store {
		return $store ?? Store::default();
	}

	private static function positive_int( mixed $value, int $default ): int {
		$int = is_numeric( $value ) ? (int) $value : 0;
		return $int > 0 ? $int : $default;
	}

	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 ? $int : null;
	}

	/** @return array<int, mixed> */
	private static function json_decode_array( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
