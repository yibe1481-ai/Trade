<?php
declare( strict_types=1 );

namespace Trade\MiniApp;

use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Identity\Service as Identity;
use WP_REST_Request;

/**
 * Telegram Mini App backend (§B.3 Mini App, interfaces/mini_app.md).
 *
 * The Mini App (trade/mini-app/index.html) logs in via POST /auth/session with Telegram
 * initData, then drives the rest of the marketplace REST API with the Bearer session token.
 * These two thin routes satisfy the mini_app contract without duplicating identity logic.
 */
final class Service {

	public const URL = 'mini_app_url';

	public static function routes(): void {
		Rest::register( 'mini_app/session', 'POST', '', array( self::class, 'session' ) );
		Rest::register( 'mini_app/onboard', 'GET', 'tb_session', array( self::class, 'onboard' ) );
	}

	/** POST /mini_app/session — {init_data} → validated + session token (delegates to identity). */
	public static function session( WP_REST_Request $request ): array {
		$auth = Identity::login( $request ); // same payload {init_data}; verifies + issues session.
		return array( 'data' => array_merge( array( 'validated' => true ), $auth['data'] ) );
	}

	/** GET /mini_app/onboard?step= — contextual open state for the authenticated Telegram user. */
	public static function onboard( WP_REST_Request $request ): array {
		$uid      = (int) get_current_user_id();
		$identity = Store::default()->get_row( 'tb_identity', 'wp_user_id = %d', array( $uid ) );
		return array(
			'data' => array(
				'step'             => (string) ( $request->get_param( 'step' ) ?: 'home' ),
				'user_id'          => $uid,
				'language'         => (string) ( $identity['language'] ?? 'en' ),
				// # missing: real onboarding_state/role are owned by their modules; provisional.
				'onboarding_state' => 'none',
				'role'             => 'customer',
			),
		);
	}
}