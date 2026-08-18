<?php
declare( strict_types=1 );

namespace Trade\Telegram;

use Trade\Core\Error;

/**
 * Telegram initData verification (§B.3.1 steps 2–6). Pure — no WP/DB state, smoke-tested.
 * # missing: the data_check_string construction (sort keys, drop hash, join "k=v" with \n)
 * is the official Telegram algorithm; the spec §B.3.1 references it without spelling it out.
 */
final class Verify {

	public const MAX_AGE_SECONDS = 300;

	/**
	 * Verify a raw initData string against the bot secret. Throws Trade\Core\Exception on
	 * AUTH_INVALID_SIGNATURE / AUTH_EXPIRED_INITDATA. Never includes the raw initData in
	 * error context (§B.12.3 — initData is a bearer credential).
	 *
	 * @return array{user_id:int, first_name:string, last_name:string, username:string, language_code:string, auth_date:int}
	 */
	public static function verify( string $init_data, string $bot_token, ?int $now = null ): array {
		$now = $now ?? time();

		parse_str( $init_data, $params );
		$supplied = (string) ( $params['hash'] ?? '' );
		unset( $params['hash'] );

		ksort( $params );
		$lines = array();
		foreach ( $params as $k => $v ) {
			if ( is_array( $v ) ) {
				continue; // Telegram never sends array fields; ignore rather than trust.
			}
			$lines[] = "{$k}={$v}";
		}
		$data_check_string = implode( "\n", $lines );

		// §B.3.1: secret_key = HMAC_SHA256(key="WebAppData", msg=bot_token), then hash the check string.
		$secret = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );
		$check  = bin2hex( hash_hmac( 'sha256', $data_check_string, $secret, true ) );

		if ( ! hash_equals( $check, strtolower( $supplied ) ) ) {
			Error::throw_( 'AUTH_INVALID_SIGNATURE', 'identity', Error::text( 'AUTH_INVALID_SIGNATURE' ), array( 'reason' => 'bad_signature' ) );
		}

		$auth_date = (int) ( $params['auth_date'] ?? 0 );
		if ( abs( $now - $auth_date ) > self::MAX_AGE_SECONDS ) {
			Error::throw_( 'AUTH_EXPIRED_INITDATA', 'identity', Error::text( 'AUTH_EXPIRED_INITDATA' ), array( 'reason' => 'auth_date_out_of_window' ) );
		}

		$user = json_decode( (string) ( $params['user'] ?? '' ), true );
		$uid  = is_array( $user ) ? (int) ( $user['id'] ?? 0 ) : 0;
		if ( $uid <= 0 ) {
			Error::throw_( 'AUTH_INVALID_SIGNATURE', 'identity', Error::text( 'AUTH_INVALID_SIGNATURE' ), array( 'reason' => 'bad_user' ) );
		}

		return array(
			'user_id'       => $uid,
			'first_name'    => is_array( $user ) ? (string) ( $user['first_name'] ?? '' ) : '',
			'last_name'     => is_array( $user ) ? (string) ( $user['last_name'] ?? '' ) : '',
			'username'      => is_array( $user ) ? (string) ( $user['username'] ?? '' ) : '',
			'language_code' => is_array( $user ) ? (string) ( $user['language_code'] ?? '' ) : '',
			'auth_date'     => $auth_date,
		);
	}
}