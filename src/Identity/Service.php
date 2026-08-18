<?php
declare( strict_types=1 );

namespace Trade\Identity;

use Trade\Catalog\Service as CatalogService;
use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Core\Error;
use Trade\Core\Audit;
use Trade\Core\Events;
use Trade\Core\Throttle;
use Trade\Telegram\Verify;
use WP_REST_Request;

/**
 * Identity module — login, /me (§B.3.1, §B.5). Capability enforcement lives in Rest's
 * guard; `/me` is self-scoped by construction.
 */
final class Service {

	public static function routes(): void {
		Rest::register( 'auth/session', 'POST', '', array( self::class, 'login' ) );
		Rest::register( 'me', 'GET', 'tb_session', array( self::class, 'me_read' ) );
		Rest::register( 'me', 'PATCH', 'tb_session', array( self::class, 'me_update' ) );
	}

	/** §B.3.1: verify initData → replay/rate throttle → resolve or create identity → session. */
	public static function login( WP_REST_Request $request ): array {
		$params    = $request->get_json_params() ?: array();
		$init_data = isset( $params['init_data'] ) ? $params['init_data'] : '';
		if ( ! is_string( $init_data ) || '' === trim( $init_data ) || mb_strlen( $init_data ) > 32768 ) {
			throw Error::validation( array( 'init_data' ), 'identity' );
		}

		$tg = Verify::verify( $init_data, (string) get_option( 'trade_telegram_bot_token', '' ) );

		// §B.3.1 step 5: same signed payload within 300s → replay.
		$replay = Throttle::hit( 'replay:' . hash( 'sha256', $init_data ), 300, 1 );
		if ( ! $replay['allowed'] ) {
			Error::throw_( 'AUTH_REPLAY_DETECTED', 'identity', Error::text( 'AUTH_REPLAY_DETECTED' ), array( 'reason' => 'initdata_reuse' ) );
		}

		// §B.3.5: 10 auth session creations / min / Telegram ID.
		$rate = Throttle::hit( 'auth:' . $tg['user_id'], 60, 10 );
		if ( ! $rate['allowed'] ) {
			Error::throw_( 'RATE_LIMITED', 'identity', Error::text( 'RATE_LIMITED' ), array( 'retry_after' => $rate['retry_after'] ) );
		}

		$wp_user_id = self::find_identity( $tg['user_id'] );

		$session = Session::issue( $wp_user_id );
		Audit::write( 'user.login', 'user', (string) $wp_user_id, array(), array( 'session_issued' => true ), array(), 'telegram', (string) $wp_user_id );

		return array(
			'data' => array(
				'session_token'    => $session['token'],
				'expires_at'       => gmdate( 'c', $session['expires_at'] ),
				// # missing: real onboarding_state/role are set by the modules that own them
				// (onboarding flow, merchant); provisional constants until then.
				'onboarding_state' => 'none',
				'role'             => 'customer',
			),
		);
	}

	/** Find the wp_user + tb_identity row for a Telegram user, creating both on first login. */
	private static function find_identity( int $telegram_user_id ): int {
		$row = Store::default()->get_row( 'tb_identity', 'telegram_user_id = %s', array( (string) $telegram_user_id ) );
		if ( $row ) {
			return (int) $row['wp_user_id'];
		}

		// # missing: WP user bootstrap (login/email/password) is not in the spec; placeholder
		// login 'tgu_{id}' + a .invalid address WP requires but never mails. Role subscriber —
		// identity caps come from Session::grant_trade_caps, not WP roles.
		$user_id = wp_insert_user( array(
			'user_login' => 'tgu_' . $telegram_user_id,
			'user_email' => 'tg' . $telegram_user_id . '@local.invalid',
			'user_pass'  => wp_generate_password( 64, false, false ),
			'role'       => 'subscriber',
		) );
		if ( is_object( $user_id ) && method_exists( $user_id, 'get_error_code' ) ) {
			Error::throw_( 'INTERNAL_ERROR', 'identity', Error::text( 'INTERNAL_ERROR' ), array( 'reason' => 'user_create_failed' ) );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		Store::default()->insert( 'tb_identity', array(
			'telegram_user_id' => (string) $telegram_user_id,
			'wp_user_id'       => (int) $user_id,
			'language'         => 'en',
			'created_at'       => $now,
		) );
		Store::default()->insert( 'tb_customer_profiles', array(
			'wp_user_id'   => (int) $user_id,
			'display_name' => '',
			'location_id'  => null,
			'created_at'   => $now,
		) );
		Events::emit( 'USER_REGISTERED', array(
			'wp_user_id'       => (int) $user_id,
			'telegram_user_id' => $telegram_user_id,
		) );
		return (int) $user_id;
	}

	/** GET /me — exactly language + display_name + location_id (§B.5). */
	public static function me_read( WP_REST_Request $request ): array {
		$uid      = get_current_user_id();
		$identity = Store::default()->get_row( 'tb_identity', 'wp_user_id = %d', array( $uid ) );
		$profile  = Store::default()->get_row( 'tb_customer_profiles', 'wp_user_id = %d', array( $uid ) );

		return array( 'data' => array(
			'language'     => $identity['language'] ?? 'en',
			'display_name' => $profile['display_name'] ?? '',
			'location_id'  => isset( $profile['location_id'] ) && null !== $profile['location_id'] ? (int) $profile['location_id'] : null,
		) );
	}

	/** PATCH /me — all three fields writeable; telegram_user_id is immutable. */
	public static function me_update( WP_REST_Request $request ): array {
		$uid    = get_current_user_id();
		$params = $request->get_json_params() ?: array();
		foreach ( array_keys( $params ) as $k ) {
			if ( ! in_array( $k, array( 'language', 'display_name', 'location_id' ), true ) ) {
				throw Error::validation( array( $k ), 'identity' );
			}
		}

		if ( array_key_exists( 'language', $params ) ) {
			$lang = (string) $params['language'];
			if ( ! Store::default()->get_row( 'tb_languages', 'code = %s AND enabled = 1', array( $lang ) ) ) {
				throw Error::validation( array( 'language' ), 'identity' );
			}
			$before = Store::default()->get_row( 'tb_identity', 'wp_user_id = %d', array( $uid ) );
			Store::default()->update( 'tb_identity', array( 'language' => $lang ), array( 'wp_user_id' => $uid ) );
			Audit::write( 'identity.language', 'identity', (string) $uid, array( 'language' => $before['language'] ?? null ), array( 'language' => $lang ) );
		}

		$profile = Store::default()->get_row( 'tb_customer_profiles', 'wp_user_id = %d', array( $uid ) );
		$set     = array();
		if ( array_key_exists( 'display_name', $params ) ) {
			$name = trim( (string) $params['display_name'] );
			if ( mb_strlen( $name ) > 100 ) {
				throw Error::validation( array( 'display_name' ), 'identity' );
			}
			$set['display_name'] = $name;
		}
		if ( array_key_exists( 'location_id', $params ) ) {
			if ( null !== $params['location_id'] ) {
				$lid = (int) $params['location_id'];
				if ( $lid <= 0 ) {
					throw Error::validation( array( 'location_id' ), 'identity' );
				}
				if ( ! CatalogService::location_exists( $lid ) ) {
					Error::throw_( 'LOCATION_NOT_FOUND', 'catalog', Error::text( 'LOCATION_NOT_FOUND' ), array( 'location_id' => $lid ) );
				}
				$set['location_id'] = $lid;
			} else {
				$set['location_id'] = null;
			}
		}
		if ( $set ) {
			if ( $profile ) {
				Store::default()->update( 'tb_customer_profiles', $set, array( 'wp_user_id' => $uid ) );
			} else {
				Store::default()->insert( 'tb_customer_profiles', array_merge( array(
					'wp_user_id' => $uid,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				), $set ) );
			}
			Audit::write( 'profile.update', 'customer_profile', (string) $uid, array(), $set );
		}

		return self::me_read( $request );
	}
}
