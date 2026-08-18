<?php
declare( strict_types=1 );

namespace Trade\MiniApp;

use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Identity\Service as Identity;
use WP_REST_Request;

/** Contextual Telegram Mini App backend. */
final class Service {
	public const URL = 'mini_app_url';

	public static function routes(): void {
		Rest::register( 'mini_app/session', 'POST', '', array( self::class, 'session' ) );
		Rest::register( 'mini_app/onboard', 'GET', 'tb_session', array( self::class, 'onboard' ) );
	}

	public static function session( WP_REST_Request $request ): array {
		return Identity::login( $request );
	}

	/** Return the exact launch context collected by the Telegram conversation. */
	public static function onboard( WP_REST_Request $request ): array {
		$uid = (int) get_current_user_id();
		$store = Store::default();
		$identity = $store->get_row( 'tb_identity', 'wp_user_id = %d', array( $uid ) );
		$telegram_id = (string) ( $identity['telegram_user_id'] ?? '' );
		$chat = '' !== $telegram_id ? $store->get_row( 'tb_bot_chats', 'chat_id = %d', array( (int) $telegram_id ) ) : null;
		$data = $chat ? json_decode( (string) ( $chat['data'] ?? '' ), true ) : array();
		$data = is_array( $data ) ? $data : array();

		$role = (string) ( $data['role'] ?? 'customer' );
		$completed = ! empty( $data['completed'] ) && ! empty( $data['language'] ) && in_array( $role, array( 'merchant', 'customer' ), true );
		$merchant = $store->get_row( 'tb_merchants', 'wp_user_id = %d', array( $uid ) );
		$verification = $merchant ? (string) ( $merchant['verification_status'] ?? 'none' ) : 'none';

		if ( 'merchant' === $role ) {
			$screen = 'merchant_home';
			if ( 'verified' !== $verification ) {
				$screen = 'merchant_verification';
			}
		} else {
			$screen = 'customer_home';
		}

		return array( 'data' => array(
			'completed'           => $completed,
			'language'            => (string) ( $data['language'] ?? $identity['language'] ?? 'en' ),
			'role'                => $role,
			'onboarding_state'    => $completed ? 'complete' : 'incomplete',
			'launch_screen'       => $screen,
			'merchant_id'         => $merchant ? (int) $merchant['wp_user_id'] : null,
			'verification_status' => $verification,
			'context'             => $data,
		) );
	}
}
