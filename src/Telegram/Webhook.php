<?php
declare( strict_types=1 );

namespace Trade\Telegram;

use Trade\Core\Rest;
use Trade\Core\Error;
use Trade\Core\Audit;
use Trade\Core\Events;
use WP_REST_Request;

/** Telegram webhook receiver and webhook registration helper. */
final class Webhook {

	public static function routes(): void {
		Rest::register( 'webhook/telegram', 'POST', '', array( self::class, 'receive' ) );
	}

	/**
	 * Register/update the Telegram webhook from the current WordPress settings.
	 * Returns a safe diagnostic array; secrets and bot tokens are never returned.
	 */
	public static function register(): array {
		$token = (string) get_option( 'trade_telegram_bot_token', '' );
		if ( '' === $token ) {
			return array( 'ok' => false, 'code' => 'TELEGRAM_UNAVAILABLE', 'message' => 'Telegram bot token is not configured.' );
		}

		$secret = (string) get_option( 'trade_telegram_webhook_secret', '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 40, false, false );
			update_option( 'trade_telegram_webhook_secret', $secret, false );
		}

		$url = rest_url( 'trade/v1/webhook/telegram' );
		$response = wp_remote_post( 'https://api.telegram.org/bot' . $token . '/setWebhook', array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'url'             => $url,
				'secret_token'    => $secret,
				'allowed_updates' => array( 'message', 'callback_query' ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'code' => 'TELEGRAM_UNAVAILABLE', 'message' => $response->get_error_message() );
		}

		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || ! ( $json['ok'] ?? false ) ) {
			return array(
				'ok'      => false,
				'code'    => 'TELEGRAM_UNAVAILABLE',
				'message' => (string) ( $json['description'] ?? 'Telegram rejected the webhook.' ),
			);
		}

		return array( 'ok' => true, 'url' => $url );
	}

	public static function receive( WP_REST_Request $request ): array {
		$secret   = (string) $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' );
		$expected = (string) get_option( 'trade_telegram_webhook_secret', '' );

		if ( '' === $expected || ! hash_equals( $expected, $secret ) ) {
			Audit::write( 'security.webhook_secret', 'webhook', 'telegram', array(), array( 'rejected' => true ), array(), 'system', '0', 'webhook' );
			Error::throw_( 'FORBIDDEN_CAPABILITY', 'telegram', Error::text( 'FORBIDDEN_CAPABILITY' ), array( 'reason' => 'bad_webhook_secret' ) );
		}

		$update = $request->get_json_params();
		$update = is_array( $update ) ? $update : array();

		if ( isset( $update['message']['chat']['id'], $update['message']['text'] ) ) {
			$chat_id = (int) $update['message']['chat']['id'];
			$text    = (string) $update['message']['text'];
			$from_id = (int) ( $update['message']['from']['id'] ?? 0 );

			// Conversation is deliberately allowed to throw into the REST error envelope,
			// but Telegram still receives HTTP 500 when a real application failure occurs.
			// That makes failures visible in getWebhookInfo instead of being silently lost.
			$replies = Conversation::step( $chat_id, $text, null, null, $from_id );
			Audit::write(
				'telegram.message',
				'chat',
				(string) $chat_id,
				array(),
				array( 'replies' => count( $replies ), 'text_len' => strlen( $text ) ),
				array(),
				'telegram',
				(string) $chat_id,
				'webhook'
			);
		} elseif ( isset( $update['callback_query'] ) ) {
			$bot = new Bot();
			if ( $bot->token_set() ) {
				$bot->answerCallbackQuery( (string) ( $update['callback_query']['id'] ?? '' ), 'Coming soon.' );
			}
		}

		Events::emit( 'telegram.webhook', array() );
		return array( 'data' => array( 'received' => true ) );
	}
}
