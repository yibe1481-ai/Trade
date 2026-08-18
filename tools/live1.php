<?php
// Live leg: drive identity endpoints via rest_do_request. Run with: wp eval-file (from site root).
// Exercises §B.3.1 replay/expiry + §B.3.2 session + /me + §B.3.5 rate + webhook secret.

const BOT = 'cli-token-1';
const SEC = 'cli-secret-1';
$now = time();

// Self-contained: clear identity/throttle state from prior runs (dev table only).
global $wpdb;
foreach ( array( 'tb_throttle', 'tb_sessions', 'tb_identity', 'tb_customer_profiles', 'tb_audit_logs' ) as $tbl ) {
	$wpdb->query( "DELETE FROM {$tbl}" );
}
foreach ( get_users( array( 'search' => 'tgu_*', 'number' => 100 ) ) as $u ) {
	wp_delete_user( $u->ID );
}
$GLOBALS['out'] = array(); // $GLOBALS: wp eval-file evaluates in a scoped context, not the global scope.

/** Build signed initData the way Telegram's Mini App does. */
function tg_init( int $uid, int $auth_date, array $extra = array() ): string {
	$fields = array_merge( array(
		'auth_date' => (string) $auth_date,
		'query_id'  => 'AA' . str_pad( (string) $uid, 8, '0', STR_PAD_LEFT ),
		'user'      => json_encode( array( 'id' => $uid, 'first_name' => 'Live', 'last_name' => 'Test', 'username' => 'live' . $uid ), JSON_UNESCAPED_UNICODE ),
	), $extra );
	ksort( $fields );
	$dcs   = implode( "\n", array_map( static fn( $k, $v ) => "{$k}={$v}", array_keys( $fields ), $fields ) );
	$check = bin2hex( hash_hmac( 'sha256', $dcs, hash_hmac( 'sha256', BOT, 'WebAppData', true ), true ) );
	return http_build_query( array_merge( $fields, array( 'hash' => $check ) ) );
}

function send( string $method, string $route, array $body = array(), string $token = '' ): array {
	$req = new WP_REST_Request( $method, $route );
	$req->set_header( 'Content-Type', 'application/json' );
	if ( '' !== $token ) {
		$req->set_header( 'Authorization', 'Bearer ' . $token );
	}
	if ( $body ) {
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	$h   = $res->get_headers();
	return array(
		'status'      => $res->get_status(),
		'body'        => $res->get_data(),
		'retry_after' => $h['Retry-After'] ?? null,
	);
}

function report( string $label, bool $ok, array $r ): void {
	$GLOBALS['out'][] = $label . ': ' . ( $ok ? 'ok' : 'FAIL ' . var_export( $r, true ) );
}

// 1. login (new user → session issued + wp_user created)
$init = tg_init( 9001, $now - 5 );
$r    = send( 'POST', '/trade/v1/auth/session', array( 'init_data' => $init ) );
$ok   = 200 === $r['status'] && isset( $r['body']['data']['session_token'] ) && isset( $r['body']['data']['expires_at'] );
$token = (string) ( $r['body']['data']['session_token'] ?? '' );

// 2. /me GET
$r  = send( 'GET', '/trade/v1/me', array(), $token );
$ok = 200 === $r['status']
	&& array_key_exists( 'language', $r['body']['data'] )
	&& array_key_exists( 'display_name', $r['body']['data'] )
	&& array_key_exists( 'location_id', $r['body']['data'] );
report( 'me GET: three fields', $ok, $r );

// 3. PATCH /me valid
$r  = send( 'PATCH', '/trade/v1/me', array( 'display_name' => 'Live Tester', 'location_id' => 7 ), $token );
$ok = 200 === $r['status'] && 'Live Tester' === ( $r['body']['data']['display_name'] ?? null ) && 7 === ( $r['body']['data']['location_id'] ?? null );
report( 'me PATCH: display_name + location_id persist', $ok, $r );

// 4. PATCH /me invalid language → 400 VALIDATION_FAILED
$r  = send( 'PATCH', '/trade/v1/me', array( 'language' => 'xx' ), $token );
$ok = 400 === $r['status'] && 'VALIDATION_FAILED' === ( $r['body']['error']['code'] ?? null );
report( 'me PATCH: bad language rejected', $ok, $r );

// 5. Replay same init_data → 401 AUTH_REPLAY_DETECTED
$r  = send( 'POST', '/trade/v1/auth/session', array( 'init_data' => $init ) );
$ok = 401 === $r['status'] && 'AUTH_REPLAY_DETECTED' === ( $r['body']['error']['code'] ?? null );
report( 'replay: same init_data reused', $ok, $r );

// 6. Tampered signature → 401 AUTH_INVALID_SIGNATURE
$r  = send( 'POST', '/trade/v1/auth/session', array( 'init_data' => substr( tg_init( 8601, $now - 5 ), 0, -4 ) . 'abcd' ) );
$ok = 401 === $r['status'] && 'AUTH_INVALID_SIGNATURE' === ( $r['body']['error']['code'] ?? null );
report( 'tampered hash rejected', $ok, $r );

// 7. auth_date > 300s → 401 AUTH_EXPIRED_INITDATA
$r  = send( 'POST', '/trade/v1/auth/session', array( 'init_data' => tg_init( 8601, $now - 301 ) ) );
$ok = 401 === $r['status'] && 'AUTH_EXPIRED_INITDATA' === ( $r['body']['error']['code'] ?? null );
report( 'expired auth_date rejected', $ok, $r );

// 8. Pre-expired session row → /me 401 AUTH_SESSION_EXPIRED
$ex_user = get_user_by( 'login', 'exp_sess' );
if ( ! $ex_user ) {
	$ex_uid = wp_insert_user( array(
		'user_login' => 'exp_sess',
		'user_email' => 'exp@sess.invalid',
		'user_pass'  => wp_generate_password( 32 ),
		'role'       => 'subscriber',
	) );
	$ex_user = is_object( $ex_uid ) ? null : get_user_by( 'id', $ex_uid );
}
$ex_uid      = $ex_user ? (int) $ex_user->ID : 0;
$exp_token   = 'badc0ffe' . str_repeat( '0', 56 ); // 64 hex
global $wpdb;
$wpdb->insert( 'tb_sessions', array(
	'token_hash'   => hash( 'sha256', $exp_token ),
	'wp_user_id'   => $ex_uid,
	'issued_at'    => gmdate( 'Y-m-d H:i:s', $now - 90000 ),
	'last_seen_at' => gmdate( 'Y-m-d H:i:s', $now - 90000 ),
	'expires_at'   => gmdate( 'Y-m-d H:i:s', $now - 1 ),
) );
$r  = send( 'GET', '/trade/v1/me', array(), $exp_token );
$ok = 401 === $r['status'] && 'AUTH_SESSION_EXPIRED' === ( $r['body']['error']['code'] ?? null );
report( 'expired session row → 401', $ok, $r );

// 9. webhook: wrong secret 403, right secret 200
$req = new WP_REST_Request( 'POST', '/trade/v1/webhook/telegram' );
$req->set_header( 'X-Telegram-Bot-Api-Secret-Token', 'wrong' );
$r1   = rest_do_request( $req );
$req2 = new WP_REST_Request( 'POST', '/trade/v1/webhook/telegram' );
$req2->set_header( 'X-Telegram-Bot-Api-Secret-Token', SEC );
$r2 = rest_do_request( $req2 );
report( 'webhook: wrong secret 403', 403 === $r1->get_status(), array( 'status' => $r1->get_status(), 'body' => $r1->get_data() ) );
report( 'webhook: right secret received', 200 === $r2->get_status() && true === ( $r2->get_data()['data']['received'] ?? null ), array( 'status' => $r2->get_status(), 'body' => $r2->get_data() ) );
$ev = $wpdb->get_var( "SELECT COUNT(*) FROM tb_events WHERE event_name = 'telegram.webhook'" );
report( 'webhook: telegram.webhook event emitted', (int) $ev >= 1, array( 'events' => $ev ) );

// 10. only sha256 hash stored
$stored = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM tb_sessions WHERE token_hash = %s', hash( 'sha256', $token ) ) );
$plain  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM tb_sessions WHERE token_hash = %s', $token ) );
report( 'sessions: hash stored, plaintext never', $stored >= 1 && 0 === $plain, array( 'stored' => $stored, 'plain' => $plain ) );

// 11. 11th login in warm window → 429 RATE_LIMITED + Retry-After
$wpdb->delete( 'tb_throttle', array( 'bucket_key' => 'auth:9301' ) );
for ( $i = 1; $i <= 10; $i++ ) {
	send( 'POST', '/trade/v1/auth/session', array( 'init_data' => tg_init( 9301, $now - 2, array( 'start_param' => (string) $i ) ) ) );
}
$r  = send( 'POST', '/trade/v1/auth/session', array( 'init_data' => tg_init( 9301, $now - 2, array( 'start_param' => '929' ) ) ) );
$ok = 429 === $r['status'] && 'RATE_LIMITED' === ( $r['body']['error']['code'] ?? null ) && is_numeric( $r['retry_after'] );
report( 'rate: 11th login blocked 429 + Retry-After', $ok, $r );

echo implode( "\n", $GLOBALS['out'] ) . "\n";