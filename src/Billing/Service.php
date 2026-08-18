<?php
declare( strict_types=1 );

namespace Trade\Billing;

/**
 * Billing module — plans, subscriptions, and entitlements (§B.2.2, §B.14).
 *
 * Invariants:
 *   - Billing is schema-only at MVP; billing_enabled=false.
   - Entitlements are read from tb_entitlements; never written directly
     except via plan grants by admin.
   - Subscriptions track entitlement keys, not plan names.
   - Entitlement limits cap listing/images counts per merchant.
 */
final class Service {

	/** Billing is disabled at MVP. */
	public const ENABLED = false;

	/** Entitlement keys used across the marketplace. */
	public const KEY_IMAGES_PER_LISTING = 'images_per_listing';
	public const KEY_ACTIVE_LISTINGS = 'active_listings';
	public const KEY_ENTITLED = 'entitled';

	/** Public REST API */
	public static function routes(): void {
		// No RPC endpoints at MVP; billing is schema-only, consumed by other modules.
	}

	/** Check if a merchant has a given entitlement. */
	public static function has_entitlement( int $merchant_id, string $key, ?Store $store = null ): bool {
		if ( ! self::ENABLED ) {
			// MVP: entitlements are managed by admin via plan grants;
			// every merchant is treated as having core entitlements.
			return true;
		}
		$store = self::store( $store );
		$row = $store->get_row( 'tb_entitlements', 'merchant_id = %d AND `key` = %s', array( $merchant_id, $key ) );
		return null !== $row;
	}

	/** Get an entitlement value (integer, default). */
	public static function entitlement_int( int $merchant_id, string $key, int $default = 0, ?Store $store = null ): int {
		if ( ! self::ENABLED ) {
			// MVP: return generous defaults when billing is disabled.
			return self::default_int( $key );
		}
		$store = self::store( $store );
		$row = $store->get_row( 'tb_entitlements', 'merchant_id = %d AND `key` = %s', array( $merchant_id, $key ) );
		if ( null === $row ) {
			return self::default_int( $key );
		}
		return (int) ( $row[ 'value' ] ?? $default );
	}

	/** Get an entitlement string value. */
	public static function entitlement_value( int $merchant_id, string $key, ?Store $store = null ): ?string {
		if ( ! self::ENABLED ) {
			return self::default_str( $key );
		}
		$store = self::store( $store );
		$row = $store->get_row( 'tb_entitlements', 'merchant_id = %d AND `key` = %s', array( $merchant_id, $key ) );
		if ( null === $row ) {
			return self::default_str( $key );
		}
		return $row[ 'value' ] ?? null;
	}

	/** Default entitlement values when billing is disabled. */
	private static function default_int( string $key ): int {
		return match( $key ) {
			self::KEY_IMAGES_PER_LISTING => 5,
			self::KEY_ACTIVE_LISTINGS => 999, // generous cap
			default => 0,
		};
	}

	private static function default_str( string $key ): ?string {
		return match( $key ) {
			self::KEY_IMAGES_PER_LISTING => '5',
			self::KEY_ACTIVE_LISTINGS => 'unlimited',
			default => null,
		};
	}

	/** Store accessor. */
	private static function store( ?Store $store = null ): Store {
		return $store ?? Store::default();
	}
}