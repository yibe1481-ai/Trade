<?php
declare( strict_types=1 );

namespace Trade\Telegram;

use Trade\Core\Error;

/** Telegram Bot API outbound adapter. */
final class Bot {

	private string $token;
	/** @var callable(string,array):array Transport(api_method, params) → [body (string), ok(bool)]. */
	private $http;

	public const BASE = 'https://api.telegram.org';

	public function __construct( string $token = '', ?callable $http = null ) {
		$this->token = '' !== $token ? $token : (string) ( get_option( 'trade_telegram_bot_token', '' ) ?: '' );
		$this->http  = $http ?? array( self::class, 'transport' );
	}

	public function token_set(): bool {
		return '' !== $this->token;
	}

	public static function deep_link( string $start_payload ): string {
		$username = (string) get_option( 'trade_telegram_bot_username', '' );
		$path     = '' !== $username ? $username : 'trade_bot';
		return 'https://t.me/' . $path . '?start=' . rawurlencode( $start_payload );
	}

	public function sendMessage( int $chat_id, string $text, array $opts = array() ): array {
		return $this->call( 'sendMessage', array_merge( array( 'chat_id' => $chat_id, 'text' => $text ), $opts ) );
	}

	public function editMessageText( int $chat_id, int $message_id, string $text, array $opts = array() ): array {
		return $this->call( 'editMessageText', array_merge( array( 'chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $text ), $opts ) );
	}

	public function answerCallbackQuery( string $callback_query_id, ?string $text = null ): array {
		return $this->call( 'answerCallbackQuery', array_merge(
			array( 'callback_query_id' => $callback_query_id ),
			null !== $text ? array( 'text' => $text ) : array()
		) );
	}

	/**
	 * Safe admin diagnostics. Never returns the bot token.
	 * A sendMessage test is optional and only uses the supplied administrator chat ID.
	 */
	public function diagnostics( ?int $chat_id = null ): array {
		$result = array(
			'token_configured' => $this->token_set(),
			'api' => array(),
			'webhook' => array(),
			'send_test' => null,
		);

		if ( ! $this->token_set() ) {
			return $result;
		}

		foreach ( array( 'getMe' => 'api', 'getWebhookInfo' => 'webhook' ) as $method => $key ) {
			try {
				$response = $this->call( $method, array() );
				$result[ $key ] = array(
					'ok' => true,
					'http_status' => 200,
					'data' => $response['result'] ?? array(),
				);
			} catch ( \Throwable $e ) {
				$result[ $key ] = array(
					'ok' => false,
					'error' => $this->safe_exception( $e ),
				);
			}
		}

		if ( null !== $chat_id && $chat_id > 0 ) {
			try {
				$response = $this->sendMessage( $chat_id, 'Trade Telegram API diagnostic: outbound messaging is working.' );
				$result['send_test'] = array(
					'ok' => true,
					'http_status' => 200,
					'data' => $response['result'] ?? array(),
				);
			} catch ( \Throwable $e ) {
				$result['send_test'] = array(
					'ok' => false,
					'error' => $this->safe_exception( $e ),
				);
			}
		}

		return $result;
	}

	private function safe_exception( \Throwable $e ): array {
		$diagnostic = get_option( 'trade_telegram_last_api_error', array() );
		$out = array(
			'exception' => get_class( $e ),
			'message' => $e->getMessage(),
		);
		if ( is_array( $diagnostic ) ) {
			foreach ( array( 'method', 'http_status', 'code', 'message', 'telegram_description' ) as $key ) {
				if ( isset( $diagnostic[ $key ] ) && '' !== (string) $diagnostic[ $key ] ) {
					$out[ $key ] = (string) $diagnostic[ $key ];
				}
			}
		}
		return $out;
	}

	private function call( string $method, array $params ): array {
		if ( ! $this->token_set() ) {
			Error::throw_( 'TELEGRAM_UNAVAILABLE', 'telegram', 'Telegram bot token is not configured.', array( 'reason' => 'missing_token' ) );
		}

		[ $body, $ok ] = ( $this->http )( $method, $params );

		if ( ! $ok ) {
			$diagnostic = get_option( 'trade_telegram_last_api_error', array() );
			Error::throw_( 'TELEGRAM_UNAVAILABLE', 'telegram', 'Telegram API request failed.', array(
				'method' => $method,
				'transport_error' => is_array( $diagnostic ) ? (string) ( $diagnostic['message'] ?? '' ) : '',
			) );
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) || ! ( $json['ok'] ?? false ) ) {
			Error::throw_( 'TELEGRAM_UNAVAILABLE', 'telegram', 'Telegram API returned an error.', array(
				'method' => $method,
				'code'   => (string) ( $json['error_code'] ?? 'unknown' ),
				'desc'   => (string) ( $json['description'] ?? '' ),
			) );
		}
		update_option( 'trade_telegram_last_api_error', array(
			'method' => $method,
			'http_status' => 200,
			'code' => 'OK',
			'message' => 'Telegram API request succeeded.',
			'telegram_description' => '',
			'at' => gmdate( 'c' ),
		), false );
		return $json;
	}

	public static function transport( string $method, array $params ): array {
		$resp = wp_remote_post( self::BASE . '/bot' . (string) get_option( 'trade_telegram_bot_token', '' ) . '/' . $method, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
			'timeout' => 10,
		) );
		if ( is_wp_error( $resp ) ) {
			update_option( 'trade_telegram_last_api_error', array(
				'method' => $method,
				'http_status' => 0,
				'code' => $resp->get_error_code(),
				'message' => $resp->get_error_message(),
				'telegram_description' => '',
				'at' => gmdate( 'c' ),
			), false );
			return array( '', false );
		}

		$body = (string) wp_remote_retrieve_body( $resp );
		$status = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( $body, true );
		if ( is_array( $json ) && ! ( $json['ok'] ?? false ) ) {
			update_option( 'trade_telegram_last_api_error', array(
				'method' => $method,
				'http_status' => $status,
				'code' => (string) ( $json['error_code'] ?? 'unknown' ),
				'message' => 'Telegram API returned an error.',
				'telegram_description' => (string) ( $json['description'] ?? '' ),
				'at' => gmdate( 'c' ),
			), false );
		}
		return array( $body, true );
	}
}
