<?php
declare( strict_types=1 );

namespace Trade\Telegram;

use Trade\Core\Rest;
use Trade\Core\Error;
use Trade\Core\Audit;
use Trade\Core\Events;
use WP_REST_Request;

/**
 * Bot webhook receiver (§B.3.3). Validates the secret, parses the Telegram Update and
 * drives the Conversation state machine. Replies are sent synchronously (# ponytail:
 * fine at MVP scale; move outbound to the jobs queue when volume demands it).
 */
final class Webhook {

	public static function routes(): void {
		Rest::register( 'webhook/telegram', 'POST', '', array( self::class, 'receive' ) );
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
			$chat_id       = (int) $update['message']['chat']['id'];
			$text          = (string) $update['message']['text'];
			$from_id       = (int) ( $update['message']['from']['id'] ?? 0 );
			$replies       = Conversation::step( $chat_id, $text, null, null, $from_id );
			$token_missing = '' === (string) get_option( 'trade_telegram_bot_token', '' );
			Audit::write(
				'telegram.message',
				'chat',
				(string) $chat_id,
				array(),
				array( 'replies' => count( $replies ), 'text_len' => strlen( $text ), 'token_missing' => $token_missing ),
				array(),
				'telegram',
				(string) $chat_id,
				'webhook'
			);
		} elseif ( isset( $update['callback_query'] ) ) {
			// MVP: ack callback buttons without a flow. Callback payloads are not
			// authority — every future flow must re-check the acting user (§B.12.3).
			$bot = new Bot();
			if ( $bot->token_set() ) {
				$bot->answerCallbackQuery( (string) ( $update['callback_query']['id'] ?? '' ), 'Coming soon.' );
			}
		}

		// No raw update persisted (§B.12.3 — never store unbounded Telegram PII).
		Events::emit( 'telegram.webhook', array() );
		return array( 'data' => array( 'received' => true ) );
	}
}