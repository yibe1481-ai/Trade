<?php
declare( strict_types=1 );

namespace Trade\Core;

/**
 * Feature flags (§B.2). Read via the core helper only; nothing else touches tb_feature_flags.
 * Static cache per request; set() goes through the same table for the admin Center (Phase 6).
 */
final class Flags {

	private static ?array $cache = null;

	public static function get( string $key, bool $default = false ): bool {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/** @return array<string,bool> */
	public static function all(): array {
		if ( null === self::$cache ) {
			$rows = Store::default()->get_rows( 'tb_feature_flags', '1=1' );
			$flags = array();
			foreach ( $rows as $row ) {
				$flags[ $row['flag_key'] ] = (bool) $row['enabled'];
			}
			self::$cache = $flags;
		}
		return self::$cache;
	}

	public static function set( string $key, bool $value, int $updated_by = 0 ): void {
		Store::default()->update(
			'tb_feature_flags',
			array( 'enabled' => (int) $value, 'updated_by' => $updated_by, 'updated_at' => current_time( 'mysql' ) ),
			array( 'flag_key' => $key )
		);
		self::$cache = null;
	}
}