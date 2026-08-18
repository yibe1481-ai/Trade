<?php
declare( strict_types=1 );

namespace Trade\Merchant;

use Trade\Catalog\Service as CatalogService;
use Trade\Core\Audit;
use Trade\Core\Error;
use Trade\Core\Rest;
use Trade\Core\Store;
use WP_REST_Request;

/**
 * Merchant module — owner profile, public profile read, and entitlement lookup.
 */
final class Service {

	public static function routes(): void {
		Rest::register( 'merchants', 'POST', 'tb_manage_own_merchant_profile', array( self::class, 'merchant_create' ) );
		Rest::register( 'merchants/(?P<id>[0-9]+)', 'PATCH', 'tb_manage_own_merchant_profile', array( self::class, 'merchant_update' ) );
		Rest::register( 'merchants/(?P<id>[0-9]+)', 'GET', '', array( self::class, 'merchant_read' ) );
	}

	public static function merchant_read( WP_REST_Request $request ): array {
		$merchant_id = (int) $request->get_param( 'id' );
		$merchant    = self::find_merchant( $merchant_id );
		if ( null === $merchant ) {
			Error::throw_( 'MERCHANT_NOT_FOUND', 'merchant', Error::text( 'MERCHANT_NOT_FOUND' ), array( 'merchant_id' => $merchant_id ) );
		}

		return array( 'data' => $merchant );
	}

	public static function merchant_create( WP_REST_Request $request ): array {
		$payload = $request->get_json_params() ?: array();
		return array( 'data' => self::create_profile( is_array( $payload ) ? $payload : array(), get_current_user_id() ) );
	}

	public static function merchant_update( WP_REST_Request $request ): array {
		$merchant_id = (int) $request->get_param( 'id' );
		$actor_id    = get_current_user_id();
		if ( $merchant_id !== $actor_id ) {
			Error::throw_( 'FORBIDDEN_NOT_OWNER', 'core', Error::text( 'FORBIDDEN_NOT_OWNER' ), array( 'merchant_id' => $merchant_id, 'wp_user_id' => $actor_id ) );
		}

		$payload = $request->get_json_params() ?: array();
		return array( 'data' => self::update_profile( $merchant_id, is_array( $payload ) ? $payload : array() ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function create_profile( array $payload, int $wp_user_id, ?Store $store = null ): array {
		$store = self::store( $store );
		$errors = array();

		$normalized = self::normalize_input( $payload, true, $errors );
		if ( $errors ) {
			throw Error::validation( array_values( array_unique( $errors ) ), 'merchant' );
		}

		$existing = self::find_merchant_row( $wp_user_id, $store );
		if ( $existing ) {
			return self::format_merchant( $existing );
		}

		$row = array(
			'wp_user_id'           => $wp_user_id,
			'business_name'        => $normalized['business_name'],
			'merchant_type'        => $normalized['merchant_type'],
			'location_id'          => $normalized['location_id'],
			'verification_status'  => 'none',
			'verified_at'          => null,
			'suspended_at'         => null,
		);
		$store->insert( 'tb_merchants', $row );

		Audit::write(
			'merchant.create',
			'merchant',
			(string) $wp_user_id,
			array(),
			self::format_merchant( $row ),
			array(),
			'user',
			(string) $wp_user_id,
			'rest'
		);

		return self::format_merchant( $row );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function update_profile( int $wp_user_id, array $payload, ?Store $store = null ): array {
		$store = self::store( $store );
		$current = self::find_merchant_row( $wp_user_id, $store );
		if ( null === $current ) {
			Error::throw_( 'MERCHANT_NOT_FOUND', 'merchant', Error::text( 'MERCHANT_NOT_FOUND' ), array( 'merchant_id' => $wp_user_id ) );
		}

		$errors = array();
		$set    = self::normalize_input( $payload, false, $errors );
		if ( $errors ) {
			throw Error::validation( array_values( array_unique( $errors ) ), 'merchant' );
		}
		if ( ! $set ) {
			throw Error::validation( array( 'body' ), 'merchant' );
		}

		$before = self::format_merchant( $current );
		foreach ( $set as $column => $value ) {
			$current[ $column ] = $value;
		}

		$store->update( 'tb_merchants', $set, array( 'wp_user_id' => $wp_user_id ) );
		$after = self::format_merchant( $current );

		Audit::write(
			'merchant.update',
			'merchant',
			(string) $wp_user_id,
			$before,
			$after,
			array( 'fields' => array_keys( $set ) ),
			'user',
			(string) $wp_user_id,
			'rest'
		);

		return $after;
	}

	public static function find_merchant( int $merchant_id, ?Store $store = null ): ?array {
		$row = self::find_merchant_row( $merchant_id, $store );
		return $row ? self::format_merchant( $row ) : null;
	}

	public static function merchant_is_verified( int $merchant_id, ?Store $store = null ): bool {
		$row = self::find_merchant_row( $merchant_id, $store );
		return $row && 'verified' === (string) ( $row['verification_status'] ?? '' );
	}

	public static function require_verified_merchant( int $merchant_id, ?Store $store = null ): void {
		if ( ! self::merchant_is_verified( $merchant_id, $store ) ) {
			Error::throw_( 'MERCHANT_NOT_VERIFIED', 'merchant', Error::text( 'MERCHANT_NOT_VERIFIED' ), array( 'merchant_id' => $merchant_id ) );
		}
	}

	public static function entitlement_value( int $merchant_id, string $key, ?Store $store = null ): ?string {
		$row = self::store( $store )->get_row( 'tb_entitlements', 'merchant_id = %d AND `key` = %s', array( $merchant_id, $key ) );
		if ( ! $row ) {
			return null;
		}
		return (string) $row['value'];
	}

	public static function entitlement_int( int $merchant_id, string $key, int $default = 0, ?Store $store = null ): int {
		$value = self::entitlement_value( $merchant_id, $key, $store );
		return null === $value ? $default : (int) $value;
	}

	public static function has_entitlement( int $merchant_id, string $key, ?Store $store = null ): bool {
		return null !== self::entitlement_value( $merchant_id, $key, $store );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<int, string>   $errors
	 * @return array<string, mixed>
	 */
	private static function normalize_input( array $payload, bool $creating, array &$errors ): array {
		$allowed = array( 'business_name', 'merchant_type', 'location_id' );
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( (string) $key, $allowed, true ) ) {
				$errors[] = (string) $key;
			}
		}

		$out = array();

		if ( $creating || array_key_exists( 'business_name', $payload ) ) {
			if ( ! array_key_exists( 'business_name', $payload ) ) {
				$errors[] = 'business_name';
			} else {
				$value = trim( (string) $payload['business_name'] );
				if ( '' === $value || mb_strlen( $value ) > 255 ) {
					$errors[] = 'business_name';
				} else {
					$out['business_name'] = $value;
				}
			}
		}

		if ( $creating || array_key_exists( 'merchant_type', $payload ) ) {
			if ( ! array_key_exists( 'merchant_type', $payload ) ) {
				$errors[] = 'merchant_type';
			} else {
				$value = trim( (string) $payload['merchant_type'] );
				if ( '' === $value || mb_strlen( $value ) > 50 ) {
					$errors[] = 'merchant_type';
				} else {
					$out['merchant_type'] = $value;
				}
			}
		}

		if ( $creating || array_key_exists( 'location_id', $payload ) ) {
			if ( ! array_key_exists( 'location_id', $payload ) ) {
				$errors[] = 'location_id';
			} else {
				$location_id = (int) $payload['location_id'];
				if ( $location_id <= 0 ) {
					$errors[] = 'location_id';
				} elseif ( ! CatalogService::location_exists( $location_id ) ) {
					Error::throw_( 'LOCATION_NOT_FOUND', 'catalog', Error::text( 'LOCATION_NOT_FOUND' ), array( 'location_id' => $location_id ) );
				} else {
					$out['location_id'] = $location_id;
				}
			}
		}

		return $out;
	}

	private static function format_merchant( array $row ): array {
		return array(
			'id'                  => (int) ( $row['wp_user_id'] ?? 0 ),
			'business_name'       => (string) ( $row['business_name'] ?? '' ),
			'merchant_type'       => (string) ( $row['merchant_type'] ?? '' ),
			'location_id'         => isset( $row['location_id'] ) && null !== $row['location_id'] && '' !== (string) $row['location_id'] ? (int) $row['location_id'] : null,
			'verification_status' => (string) ( $row['verification_status'] ?? 'none' ),
			'verified_at'         => $row['verified_at'] ?? null,
			'suspended_at'        => $row['suspended_at'] ?? null,
		);
	}

	private static function find_merchant_row( int $merchant_id, ?Store $store = null ): ?array {
		return self::store( $store )->get_row( 'tb_merchants', 'wp_user_id = %d', array( $merchant_id ) );
	}

	private static function store( ?Store $store = null ): Store {
		return $store ?? Store::default();
	}
}
