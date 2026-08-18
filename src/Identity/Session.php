<?php
declare( strict_types=1 );

namespace Trade\Identity;

use Trade\Core\Store;
use Trade\Core\Error;

/**
 * Session lifecycle (§B.3.2). Bearer tokens persist as SHA-256 hashes only; the plaintext
 * is returned to the client once at issue. Absolute TTL 24h, idle TTL 2h (clock = last_seen_at).
 */
final class Session {

	public const IDLE_SECONDS = 7200;   // §B.3.2 idle TTL 2h, clock = last_seen_at.
	public const TTL_SECONDS  = 86400;  // §B.3.2 absolute TTL 24h.

	/**
	 * Pure state decision — smoke-tested directly.
	 *
	 * @return string 'active' | 'idle_expired' | 'abs_expired' | 'revoked'
	 */
	public static function status( int $last_seen_ts, int $expires_ts, ?int $revoked_ts, int $now ): string {
		if ( null !== $revoked_ts && $revoked_ts <= $now ) {
			return 'revoked';
		}
		if ( $expires_ts <= $now ) {
			return 'abs_expired';
		}
		if ( $last_seen_ts + self::IDLE_SECONDS <= $now ) {
			return 'idle_expired';
		}
		return 'active';
	}

	/**
	 * Resolve an Authorization header ("Bearer <token>") to a live session.
	 * No header → {user_id:0, error:null} (pass-through for public/admin paths).
	 *
	 * @return array{user_id:int, error:?string}
	 */
	public static function resolve( string $authorization ): array {
		if ( ! preg_match( '/^Bearer\s+([0-9a-fA-F]{64})$/', trim( $authorization ), $m ) ) {
			return array( 'user_id' => 0, 'error' => null );
		}
		$hash = hash( 'sha256', $m[1] );
		$row  = Store::default()->get_row( 'tb_sessions', 'token_hash = %s', array( $hash ) );
		if ( ! $row ) {
			return array( 'user_id' => 0, 'error' => 'AUTH_SESSION_EXPIRED' );
		}

		$state = self::status(
			(int) strtotime( (string) $row['last_seen_at'] ),
			(int) strtotime( (string) $row['expires_at'] ),
			$row['revoked_at'] ? (int) strtotime( (string) $row['revoked_at'] ) : null,
			time()
		);
		if ( 'active' !== $state ) {
			return array( 'user_id' => 0, 'error' => 'AUTH_SESSION_EXPIRED' );
		}

		Store::default()->update( 'tb_sessions', array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'token_hash' => $hash ) );
		return array( 'user_id' => (int) $row['wp_user_id'], 'error' => null );
	}

	/**
	 * Issue a session. Returns the plaintext token — the ONLY time it exists outside
	 * the client. Only hash('sha256', $token) is persisted (§B.3.2).
	 *
	 * @return array{token:string, expires_at:int}
	 */
	public static function issue( int $wp_user_id, ?int $now = null ): array {
		$now        = $now ?? time();
		$token      = bin2hex( random_bytes( 32 ) );
		$expires_at = $now + self::TTL_SECONDS;
		Store::default()->insert( 'tb_sessions', array(
			'token_hash'    => hash( 'sha256', $token ),
			'wp_user_id'    => $wp_user_id,
			'issued_at'     => gmdate( 'Y-m-d H:i:s', $now ),
			'last_seen_at'  => gmdate( 'Y-m-d H:i:s', $now ),
			'expires_at'    => gmdate( 'Y-m-d H:i:s', $expires_at ),
			'revoked_at'    => null,
		) );
		return array( 'token' => $token, 'expires_at' => $expires_at );
	}

	/** Revoke all live sessions for a user (§B.3.2: suspend, logout, role change). */
	public static function revoke_user( int $user_id ): void {
		Store::default()->update_where(
			'tb_sessions',
			array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ),
			'wp_user_id = %d AND revoked_at IS NULL',
			array( $user_id )
		);
	}

	/** Rotation: new token on privilege change (§B.3.2) — issue() + revoke the old one. */
	public static function rotate( int $user_id, string $old_token ): array {
		$new = self::issue( $user_id );
		Store::default()->update_where(
			'tb_sessions',
			array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ),
			'token_hash = %s',
			array( hash( 'sha256', $old_token ) )
		);
		return $new;
	}

	/**
	 * user_has_cap filter (§B.3.4): identity grants `tb_session` and merchant self-service
	 * caps to any logged-in user.
	 * Named capability for /me — spec names none for it; this is the extension point for
	 * later phases' caps (tb_manage_own_listings, tb_worker, …).
	 *
	 * @param array<string,bool> $allcaps
	 * @param string[]           $requested
	 * @param int[]              $args      [requested_cap, user_id, ...]
	 * @return array<string,bool>
	 */
	public static function grant_trade_caps( array $allcaps, array $requested, array $args ): array {
		if ( (int) ( $args[1] ?? 0 ) > 0 ) { // WP_User::has_cap passes the acting user id at $args[1].
			$allcaps['tb_session'] = true;
			$allcaps['tb_manage_own_merchant_profile'] = true;
			$allcaps['tb_manage_own_listings'] = true;
			$allcaps['tb_manage_own_orders'] = true;
			$allcaps['tb_manage_own_requests'] = true;
			$allcaps['tb_manage_own_merchant_profile'] = true;
			$allcaps['tb_manage_own_trust_safety'] = true;
		}
		return $allcaps;
	}
}