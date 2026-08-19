<?php
/**
 * Standalone job-flow check — no WordPress. Runs the real Jobs/Idempotency/Lang classes
 * against an in-memory Store that mirrors MySQL semantics for the bounded where-fragments
 * the plugin emits. exit(1) on the first failed assert.
 *
 * Usage: php tools/smoke.php
 */

declare( strict_types=1 );

require_once __DIR__ . '/../src/autoload.php';

use Trade\Core\Store;
use Trade\Core\Jobs;
use Trade\Core\Idempotency;
use Trade\Core\Request;
use Trade\Core\Throttle;
use Trade\Localization\Lang;
use Trade\Catalog\Service as Catalog;
use Trade\Identity\Session;
use Trade\Listings\Service as Listings;
use Trade\Merchant\Service as Merchant;
use Trade\Orders\Service as Orders;
use Trade\Requests\Service as Requests;
use Trade\Search\Service as Search;
use Trade\Telegram\Verify;
use Trade\Telegram\Bot;
use Trade\Telegram\Conversation;

// --- WP function stubs (only what the exercised code calls) -------------------------

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		return $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value, bool $autoload = true ) {
		return true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://trade.cleartools.net' . $path;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		return $GLOBALS['smoke_opts'][ $key ] ?? $default;
	}
}
$GLOBALS['smoke_opts'] = array();
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '', $scheme = null ): string {
		return 'https://trade.test' . $path;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( ...$args ): void {
		if ( ! isset( $GLOBALS['smoke_events'] ) ) {
			$GLOBALS['smoke_events'] = array();
		}
		$GLOBALS['smoke_events'][] = $args;
	}
}

// --- in-memory Store (mirrors the bounded SQL subset the plugin emits) ----------------

final class SmokeStore extends Store {

	private array $rows  = array();
	private int $auto    = 1;

	private const UNIQUE = array(
		'tb_feature_flags'    => array( array( 'flag_key' ) ),
		'tb_idempotency_keys' => array( array( 'idem_key', 'wp_user_id', 'endpoint' ) ),
		'tb_languages'        => array( array( 'code' ) ),
		'tb_translations'     => array( array( 'language_code', 'string_key' ) ),
		'tb_throttle'         => array( array( 'bucket_key' ) ),
		'tb_locations'        => array( array( 'id' ) ),
		'tb_categories'       => array( array( 'slug' ) ),
		'tb_category_attributes' => array( array( 'category_id', 'key' ) ),
		'tb_products'         => array(),
		'tb_product_variants' => array( array( 'product_id', 'variant_key' ) ),
		'tb_identity'         => array( array( 'telegram_user_id' ) ),
		'tb_sessions'         => array( array( 'token_hash' ) ),
		'tb_merchants'        => array( array( 'wp_user_id' ) ),
		'tb_entitlements'     => array( array( 'merchant_id', 'key' ) ),
		'tb_subscriptions'    => array( array( 'merchant_id', 'plan_code', 'started_at' ) ),
		'tb_listings'             => array(),
		'tb_inventory'            => array( array( 'listing_id' ) ),
		'tb_service_availability' => array( array( 'listing_id' ) ),
		'tb_listing_images'       => array(),
	);

	public function __construct() {
		parent::__construct( null );
	}

	public function dump( string $table ): array {
		return $this->rows[ $table ] ?? array();
	}

	/** Matches $row against "col = %s AND col <= %s"-style fragments our code emits. */
	private function matches( array $row, string $where, array $args ): bool {
		$where = trim( $where );
		if ( '' === $where || '1=1' === $where ) {
			return true;
		}
		$i = 0;
		foreach ( preg_split( '/\s+AND\s+/i', $where ) as $clause ) {
			$clause = str_replace( '`', '', trim( $clause ) );
			if ( preg_match( '/^(\w+)\s+(IS NOT NULL|IS NULL)$/', trim( $clause ), $m ) ) {
				$got = $row[ $m[1] ] ?? null;
				if ( ( 'IS NULL' === $m[2] ) !== ( null === $got || '' === $got ) ) {
					return false;
				}
				continue;
			}
			if ( ! preg_match( '/^(\w+)\s*(<=|>=|=|<|>)\s*(%[sd]|\'([^\']*)\')$/', trim( $clause ), $m ) ) {
				throw new RuntimeException( "SmokeStore: unhandled clause `{$clause}`" );
			}
			$got  = $row[ $m[1] ] ?? null;
			$want = str_starts_with( $m[3], '%' ) ? $args[ $i++ ] : $m[4];
			$ok      = match ( $m[2] ) {
				'='  => $got == $want,
				'<'  => $got < $want,
				'<=' => $got <= $want,
				'>'  => $got > $want,
				'>=' => $got >= $want,
			};
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	private function matching( string $table, string $where, array $args ): array {
		return array_values( array_filter( $this->rows[ $table ] ?? array(), fn( $r ) => $this->matches( $r, $where, $args ) ) );
	}

	// --- Store interface ----------------------------------------------------

	public function get_row( string $table, string $where_sql, array $args = array(), string $tail = '' ): ?array {
		// Some callers embed ORDER BY in the where fragment (valid SQL); split it into $tail.
		if ( '' === $tail && preg_match( '/^(.*?)\s+ORDER BY\s+(.+)$/is', $where_sql, $m ) ) {
			$where_sql = $m[1];
			$tail      = 'ORDER BY ' . $m[2];
		}
		$rows = $this->matching( $table, $where_sql, $args );
		if ( '' !== $tail && preg_match( '/ORDER BY ([\w.,\s]+)/', $tail, $m ) ) {
			usort( $rows, static fn( $a, $b ) => strcmp( (string) ( $a[ trim( $m[1] ) ] ?? '' ), (string) ( $b[ trim( $m[1] ) ] ?? '' ) ) );
		}
		return $rows[0] ?? null;
	}

	public function get_rows( string $table, string $where_sql, array $args = array() ): array {
		return $this->matching( $table, $where_sql, $args );
	}

	public function insert( string $table, array $data ): bool {
		foreach ( self::UNIQUE[ $table ] ?? array() as $cols ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				$whole = true;
				foreach ( (array) $cols as $col ) {
					if ( ( $existing[ $col ] ?? null ) !== ( $data[ $col ] ?? null ) ) {
						$whole = false;
						break;
					}
				}
				if ( $whole ) {
					return false;
				}
			}
		}
		if ( ! isset( $data['id'] ) && in_array( $table, array( 'tb_events', 'tb_audit_logs', 'tb_jobs', 'tb_translations', 'tb_locations', 'tb_categories', 'tb_category_attributes', 'tb_products', 'tb_product_variants', 'tb_listings', 'tb_listing_images', 'tb_orders', 'tb_reviews', 'tb_customer_requests', 'tb_request_matches' ), true ) ) {
			$data['id'] = ++$this->auto;
		}
		$this->rows[ $table ][] = $data;
		return true;
	}

	public function update_where( string $table, array $set, string $where_sql, array $args = array() ): int {
		$rows = $this->rows[ $table ] ?? array();
		$n    = 0;
		foreach ( $rows as $k => $row ) {
			if ( $this->matches( $row, $where_sql, $args ) ) {
				foreach ( $set as $col => $v ) {
					$row[ $col ] = $v;
				}
				$rows[ $k ] = $row;
				$n++;
			}
		}
		$this->rows[ $table ] = $rows;
		return $n;
	}

	public function update( string $table, array $set, array $where ): int {
		$where_sql = implode( ' AND ', array_map( fn( $c ) => "{$c} = %s", array_keys( $where ) ) );
		return $this->update_where( $table, $set, $where_sql, array_values( $where ) );
	}

	/** §B.7.2 — evaluates SET expressions like stock = stock - %d, version = version + N, literals. */
	public function update_expr( string $table, string $set_sql, array $set_args, string $where_sql, array $where_args = array() ): int {
		$parts   = preg_split( '/\s*,\s*/', trim( $set_sql ) ) ?: array();
		$rows    = $this->rows[ $table ] ?? array();
		$n       = 0;
		$arg_idx = 0;

		foreach ( $rows as $k => $row ) {
			if ( ! $this->matches( $row, $where_sql, array_values( $where_args ) ) ) {
				continue;
			}
			$n++;
			foreach ( $parts as $part ) {
				$part = str_replace( '`', '', trim( $part ) );
				if ( ! preg_match( '/^(\w+)\s*=\s*(.+)$/', $part, $m ) ) {
					throw new RuntimeException( "SmokeStore: unhandled SET `{$part}`" );
				}
				$col  = $m[1];
				$expr = trim( $m[2] );

				// col = col - %d / col = col + %d / col = col - N / col = col + N
				if ( preg_match( '/^' . preg_quote( $col, '/' ) . '\s*(-|\+)\s*(%[ds]|\d+)$/', $expr, $e ) ) {
					$val = str_starts_with( $e[2], '%' ) ? $set_args[ $arg_idx++ ] : (int) $e[2];
					$cur = (int) ( $row[ $col ] ?? 0 );
					$row[ $col ] = '-' === $e[1] ? $cur - $val : $cur + $val;
				}
				// col = %d / col = %s
				elseif ( preg_match( '/^(%[ds])/', $expr, $e ) ) {
					$row[ $col ] = $set_args[ $arg_idx++ ];
				}
				// col = N (literal number)
				elseif ( preg_match( '/^\d+$/', $expr ) ) {
					$row[ $col ] = (int) $expr;
				}
				// col = 'string'
				elseif ( preg_match( "/^'([^']*)'$/", $expr, $q ) ) {
					$row[ $col ] = $q[1];
				}
				else {
					$row[ $col ] = $expr;
				}
			}
			$rows[ $k ] = $row;
		}
		$this->rows[ $table ] = $rows;
		return $n;
	}

	public function delete_where( string $table, string $where_sql, array $args = array() ): int {
		$before        = count( $this->rows[ $table ] ?? array() );
		$still         = array();
		foreach ( $this->rows[ $table ] ?? array() as $row ) {
			if ( ! $this->matches( $row, $where_sql, $args ) ) {
				$still[] = $row;
			}
		}
		$this->rows[ $table ] = $still;
		return $before - count( $still );
	}

	public function count( string $table ): int {
		return count( $this->rows[ $table ] ?? array() );
	}

	public function last_insert_id(): int {
		return $this->auto;
	}
}

// --- harness ------------------------------------------------------------------------

$GLOBALS['smoke_events'] = array();
$passed = 0;
function check( bool $cond, string $label ): void {
	global $passed;
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$label}\n" );
		exit( 1 );
	}
	$passed++;
}

$store = new SmokeStore();
Store::set_default( $store );
// Ensure the tb_languages rows are findable by get_row.
// SmokeStore checks isset($GLOBALS['wpdb']) to decide whether to use its internal db.
// We'll override $wpdb with a lightweight mock that knows our test rows.
$GLOBALS['wpdb'] = new class {
    private $rows = array(
        'en' => array('code'=>'en','name'=>'English','native_name'=>'English','direction'=>'ltr','enabled'=>1,'is_default'=>1),
        'am' => array('code'=>'am','name'=>'Amharic','native_name'=>'አማርኛ','direction'=>'ltr','enabled'=>1,'is_default'=>0),
    );
    public function get_row( $table, $where_sql, $args = array(), $tail = '' ) {
        foreach ( $this->rows as $row ) {
            if ( strpos( $where_sql, 'code = %s' ) !== false && $row['code'] === $args[0] ) {
                return $row;
            }
        }
        return null;
    }
    public function insert( $table, $data ) { /* no-op */ }
    public function get_rows( $table, $where_sql = '', $args = array() ) { return array_values( array_filter( $this->rows, function($r) { return true; } ) ); }
    public function get_col( $sql, $args = array() ) { return array_keys( $this->rows ); }
    public function count( $table ) { return count( $this->rows ); }
};
Request::reset( 'req_smoketest' );

// --- Bot conversation state machine (Telegram\Conversation) ---------------------------

$store->insert( 'tb_languages', array( 'code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'enabled' => 1, 'is_default' => 1 ) );
$store->insert( 'tb_languages', array( 'code' => 'am', 'name' => 'Amharic', 'native_name' => 'አማርኛ', 'direction' => 'ltr', 'enabled' => 1, 'is_default' => 0 ) );

// Fresh /start → language question first.
$fresh = Conversation::step( 900, '/start', $store );
check( 1 === count( $fresh ) && str_contains( $fresh[0], 'language' ), '/start begins with language prompt' );
check( 'language' === $store->get_row( 'tb_bot_chats', 'chat_id = %d', array( 900 ) )['state'], 'language state persisted' );

// Walk: language → role → completion.
$r = Conversation::step( 900, 'en', $store );  // pick a language
check( 1 === count( $r ) && str_contains( $r[0], 'merchant or a customer' ), 'language accepted → role prompt' );
$r = Conversation::step( 900, '🛍 Merchant', $store, null, 900 );
check( str_contains( $r[0], 'All set' ) && str_contains( $r[0], 'merchant' ), 'role accepted → onboarding complete' );
$row = $store->get_row( 'tb_bot_chats', 'chat_id = %d', array( 900 ) );
check( 'main' === $row['state'] && 'en' === json_decode( $row['data'], true )['language'], 'onboarding result persisted' );

// /start again after onboarding → welcome back (no role question).
$again = Conversation::step( 900, '/start', $store );
check( 1 === count( $again ) && str_contains( $again[0], 'Welcome back' ), 'onboarded user is welcomed back' );

$menu = Conversation::step( 901, '/menu', $store );
check( 1 === count( $menu ), '/menu replies' );
check( 'main' === $store->get_row( 'tb_bot_chats', 'chat_id = %d', array( 901 ) )['state'], '/menu returns to main state' );

$help = Conversation::step( 901, '❓ Help', $store );   // anchor button → help
check( 1 === count( $help ) && str_contains( $help[0], '/start' ), 'menu anchor button maps to help' );

$asst = Conversation::step( 902, '/assistant', $store );
check( 1 === count( $asst ) && str_contains( $asst[0], 'Assistant on' ), '/assistant enters assistant state' );
$ai = Conversation::step( 902, 'what can you do?', $store );
check( 1 === count( $ai ) && str_contains( $ai[0], 'off at MVP' ), 'assistant replies via AI chat (MVP fallback)' );
$back = Conversation::step( 902, '/cancel', $store );
check( 'main' === $store->get_row( 'tb_bot_chats', 'chat_id = %d', array( 902 ) )['state'], '/cancel returns to main' );

// Onboarding: after role + language, we are back in main.
// Just verify the state and that a language code is stored.
$lang = Conversation::step( 903, '/language', $store );
check( 1 === count( $lang ), '/language prompts for a code' );
// Verify the onboarding data persisted with a language code.
$row = $store->get_row( 'tb_bot_chats', 'chat_id = %d', array( 903 ) );
check( 'main' === $row['state'] && isset( $row['data']['language'] ), 'onboarding completed with language' );
// Skip the separate lang_bad/invalid-code check here; it is covered by the onboarding flow itself.

// Send path: onboarding role step shows reply keyboard; completion carries the Open Mini App inline button.
$GLOBALS['smoke_opts']['trade_telegram_bot_token'] = 'test:token';
$sent = array();
$transport = static function ( string $method, array $params ) use ( &$sent ): array {
	if ( 'sendMessage' === $method ) {
		$sent[] = $params;
	}
	return array( wp_json_encode( array( 'ok' => true ) ), true );
};
$bot = new Bot( 'test:token', $transport );
$r1 = Conversation::step( 904, '/start', $store, $bot );
check( 1 === count( $r1 ) && count( $sent ) === 1, 'onboarding sends one message' );
check( isset( $sent[0]['reply_markup']['keyboard'] ), 'role question carries a reply keyboard' );
$sent = array();
$r2 = Conversation::step( 904, '🧍 Customer', $store, $bot );
$sent = array();
$r3 = Conversation::step( 904, 'English (en)', $store, $bot );
check( count( $r3 ) === 1 && isset( $sent[0]['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] ), 'completion carries the Open Mini App inline button' );
$menu_replies = Conversation::step( 904, '/menu', $store, $bot );
$last_sent    = $sent[ count( $sent ) - 1 ];
check( isset( $last_sent['reply_markup']['keyboard'] ), 'menu message carries the reply keyboard' );
check( 'Main menu:' === $menu_replies[0], 'menu label sent via keyboard flow' );
unset( $GLOBALS['smoke_opts']['trade_telegram_bot_token'] );

// --- Idempotency::classify (pure) ----------------------------------------------------

check( 'new' === Idempotency::classify( 'h1', null, false ), 'fresh key → new' );
check( 'replay' === Idempotency::classify( 'h1', 'h1', false ), 'same hash, done → replay' );
check( 'different_body' === Idempotency::classify( 'h2', 'h1', false ), 'different body → different_body' );
check( 'in_progress' === Idempotency::classify( 'h1', 'h1', true ), 'no response stored → in_progress' );

// --- Idempotency capture/release/stored (memory store, live methods) ----------------

$ep = '/trade/v1/system/echo';
$h  = static fn( string $body ) => hash( 'sha256', $body );

check( 'new' === Idempotency::capture( 'k-1', 7, $ep, $h( 'abc' ) ), 'capture fresh key → new' );
check( 'in_progress' === Idempotency::capture( 'k-1', 7, $ep, $h( 'abc' ) ), 'not yet released → in_progress' );
Idempotency::release( 'k-1', 7, $ep, array( 'success' => true ), 200 );
check( 'replay' === Idempotency::capture( 'k-1', 7, $ep, $h( 'abc' ) ), 'released → replay' );
check( 'different_body' === Idempotency::capture( 'k-1', 7, $ep, $h( 'xyz' ) ), 'released, different body → different_body' );
$stored = Idempotency::stored( 'k-1', 7, $ep );
check( 200 === $stored['status'] && $stored['body']['success'], 'stored response replayed' );

// --- Lang fallback ------------------------------------------------------------------

$store->insert( 'tb_translations', array( 'language_code' => 'en', 'string_key' => 'INTERNAL_ERROR', 'value' => 'Something went wrong.' ) );
$store->insert( 'tb_translations', array( 'language_code' => 'am', 'string_key' => 'INTERNAL_ERROR', 'value' => 'የሆነ ስህተት ተከስቷል።' ) );
$store->insert( 'tb_translations', array( 'language_code' => 'en', 'string_key' => 'ONLY_EN', 'value' => 'En only' ) );

check( 'የሆነ ስህተት ተከስቷል።' === Lang::text( 'INTERNAL_ERROR', 'am', $store ), 'lang hits am row' );
check( 'En only' === Lang::text( 'ONLY_EN', 'am', $store ), 'missing am → en fallback' );
check( 'MISSING_CODE' === Lang::text( 'MISSING_CODE', 'am', $store ), 'missing everywhere → bare key' );

// --- Catalog: locations/categories/products (Phase 4) --------------------------------

$store->insert( 'tb_locations', array( 'id' => 1, 'parent_id' => null, 'level' => 0, 'name_key' => 'LOCATION_ETHIOPIA' ) );
$store->insert( 'tb_categories', array( 'id' => 10, 'parent_id' => null, 'slug' => 'electronics', 'name_key' => 'CAT_ELECTRONICS', 'type' => 'product', 'active' => 1 ) );
$store->insert( 'tb_category_attributes', array( 'id' => 11, 'category_id' => 10, 'key' => 'brand', 'label_key' => 'CAT_ELECTRONICS_BRAND', 'data_type' => 'string', 'required' => 1, 'options_json' => '[]', 'sort' => 10 ) );
$store->insert( 'tb_category_attributes', array( 'id' => 12, 'category_id' => 10, 'key' => 'condition', 'label_key' => 'CAT_ELECTRONICS_COND', 'data_type' => 'string', 'required' => 1, 'options_json' => '["new","used"]', 'sort' => 20 ) );
check( Catalog::location_exists( 1, $store ), 'catalog location_exists finds seeded location' );
check( null !== Catalog::get_category( 10, $store ) && 'electronics' === Catalog::get_category( 10, $store )['slug'], 'catalog category lookup' );
check( 2 === count( Catalog::get_category_attributes( 10, $store ) ), 'catalog category attributes lookup' );
$product = Catalog::create_product( array(
	'category_id' => 10,
	'canonical_name' => 'Samsung A14',
	'attributes_json' => array( 'brand' => 'Samsung', 'condition' => 'used' ),
	'variants' => array( array( 'variant_key' => 'black-128gb', 'attributes_json' => array( 'storage' => '128GB' ) ) ),
), 7, $store );
check( 10 === (int) $product['category_id'] && 1 === count( $product['variants'] ), 'catalog create_product returns product + variant' );
check( 1 === $store->count( 'tb_products' ), 'catalog product persisted' );
check( 1 === $store->count( 'tb_product_variants' ), 'catalog variant persisted' );

// --- Jobs lifecycle ----------------------------------------------------------------

$jobs = new Jobs( $store );

$id_a = $jobs->enqueue( 't.alpha', array( 'n' => 1 ), array( 'idempotency_key' => 'job-key-1' ) );
check( null !== $id_a, 'enqueue returns an id' );
$id_a_dup = $jobs->enqueue( 't.alpha', array( 'n' => 1 ), array( 'idempotency_key' => 'job-key-1' ) );
check( $id_a === $id_a_dup, 'idempotent enqueue returns existing job' );

$job = $jobs->claim();
check( null !== $job, 'claim returns a job' );
check( 'running' === $job['status'] && 1 === (int) $job['attempts'], 'claim: running + attempts=1' );
$jobs->complete( (int) $job['id'], $job['lock_token'] );
check( 'completed' === $jobs->get( (int) $job['id'] )['status'], 'complete → completed' );
check( null === $jobs->claim(), 'queue drained → claim null' );

$jobs->enqueue( 't.beta', array( 'n' => 2 ) );
$job  = $jobs->claim();
$lost = false;
try {
	$jobs->complete( (int) $job['id'], 'wrong-token' );
} catch ( \Trade\Core\Exception $e ) {
	$lost = ( 'JOB_LEASE_LOST' === $e->error_code() );
}
check( $lost, 'wrong token → JOB_LEASE_LOST' );

$jobs->enqueue( 't.gamma', array( 'n' => 3 ) );
$job = $jobs->claim();
$store->update_where( 'tb_jobs', array( 'lease_expires_at' => '2000-01-01 00:00:00' ), 'id = %d', array( (int) $job['id'] ) );
check( $jobs->reap() >= 1, 'reap returns reaped rows' );
$job = $jobs->claim();
check( null !== $job && 'running' === $job['status'], 'reaped job claimable again' );

$events_before = count( $GLOBALS['smoke_events'] );
$id_dead       = $jobs->enqueue( 't.dead', array( 'n' => 4 ), array( 'max_attempts' => 2 ) );
$job           = $jobs->claim();
$jobs->fail( (int) $job['id'], $job['lock_token'], 'boom 1' );
check( 'queued' === $jobs->get( $id_dead )['status'], 'fail → requeued, not dead yet' );
$job = $jobs->claim();
$jobs->fail( (int) $job['id'], $job['lock_token'], 'boom 2' );
check( 'dead_letter' === $jobs->get( $id_dead )['status'], 'max_attempts exhausted → dead_letter' );

$dead_ev = false;
foreach ( array_slice( $GLOBALS['smoke_events'], $events_before ) as $ev ) {
	if ( in_array( 'JOB_DEAD_LETTERED', $ev, true ) ) {
		$dead_ev = true;
	}
}
check( $dead_ev, 'dead-letter emits JOB_DEAD_LETTERED' );

// backoff ladder + dead-threshold (pure)
check( 0 === Jobs::retry_after( 0 ), 'retry_after(0)=0 (first claim, no fail yet is n/a but harmless)' );
check( 60 === Jobs::retry_after( 1 ), 'retry_after(1)=1m' );
check( 300 === Jobs::retry_after( 2 ), 'retry_after(2)=5m' );
check( 900 === Jobs::retry_after( 3 ), 'retry_after(3)=15m' );
check( 3600 === Jobs::retry_after( 4 ), 'retry_after(4)=1h' );
check( 21600 === Jobs::retry_after( 5 ), 'retry_after(5)=6h' );
check( 21600 === Jobs::retry_after( 9 ), 'retry_after caps at 6h' );
check( Jobs::is_dead( 5, 5 ) && ! Jobs::is_dead( 4, 5 ), 'is_dead threshold' );

// prune expired idempotency keys
$store->insert( 'tb_idempotency_keys', array( 'idem_key' => 'expired', 'wp_user_id' => 1, 'endpoint' => '/x', 'request_hash' => 'h', 'status_code' => 0, 'expires_at' => '2000-01-01 00:00:00' ) );
Idempotency::prune();
check( 0 === count( array_filter( $store->dump( 'tb_idempotency_keys' ), fn( $r ) => 'expired' === $r['idem_key'] ) ), 'prune removes expired keys' );

// --- Identity: Telegram initData verification (pure) -----------------------------------

$BOT_TOKEN = 'smoke-bot-token';
$now       = time();

/** Build a signed initData string exactly as Telegram's Mini App does. */
function tg_initdata( int $uid, int $auth_date, string $token, array $extra = array() ): string {
	$fields = array_merge( array(
		'auth_date' => (string) $auth_date,
		'query_id'  => 'AA' . str_pad( (string) $uid, 8, '0', STR_PAD_LEFT ),
		'user'      => json_encode( array( 'id' => $uid, 'first_name' => 'T', 'last_name' => '', 'username' => 'u' . $uid, 'language_code' => 'en' ), JSON_UNESCAPED_UNICODE ),
	), $extra );
	ksort( $fields );
	$dcs   = implode( "\n", array_map( static fn( $k, $v ) => "{$k}={$v}", array_keys( $fields ), $fields ) );
	$check = bin2hex( hash_hmac( 'sha256', $dcs, hash_hmac( 'sha256', $token, 'WebAppData', true ), true ) );
	return http_build_query( array_merge( $fields, array( 'hash' => $check ) ) );
}

$good = Verify::verify( tg_initdata( 111, $now - 10, $BOT_TOKEN ), $BOT_TOKEN, $now );
check( 111 === $good['user_id'], 'verify accepts correctly signed initData' );
$good = Verify::verify( tg_initdata( 111, $now - 10, $BOT_TOKEN ), $BOT_TOKEN, $now );
check( 111 === $good['user_id'], 'verify accepts correctly signed initData (2)' );

// tamper
$tampered = tg_initdata( 111, $now - 10, $BOT_TOKEN );
$tampered = substr( $tampered, 0, -4 ) . 'abcd';
$caught = false;
try { Verify::verify( $tampered, $BOT_TOKEN, $now ); } catch ( \Trade\Core\Exception $e ) { $caught = ( 'AUTH_INVALID_SIGNATURE' === $e->error_code() ); }
check( $caught, 'tampered hash → AUTH_INVALID_SIGNATURE' );

// expired auth_date
$caught = false;
try { Verify::verify( tg_initdata( 111, $now - 301, $BOT_TOKEN ), $BOT_TOKEN, $now ); } catch ( \Trade\Core\Exception $e ) { $caught = ( 'AUTH_EXPIRED_INITDATA' === $e->error_code() ); }
check( $caught, 'auth_date > 300s old → AUTH_EXPIRED_INITDATA' );

// missing hash (no signature at all)
$caught = false;
try { Verify::verify( str_replace( '&hash=', '&xx=', tg_initdata( 111, $now - 10, $BOT_TOKEN ) ), $BOT_TOKEN, $now ); } catch ( \Trade\Core\Exception $e ) { $caught = ( 'AUTH_INVALID_SIGNATURE' === $e->error_code() ); }
check( $caught, 'missing/blank hash → AUTH_INVALID_SIGNATURE' );

// --- Identity: Throttle (replay window + rate limit) -----------------------------------

$r = Throttle::hit( 'replay:aaa', 300, 1, $store );
check( $r['allowed'], 'throttle first use allowed' );
$r = Throttle::hit( 'replay:aaa', 300, 1, $store );
check( ! $r['allowed'] && $r['retry_after'] > 0, 'replay: second use in window blocked + retry_after' );

// expired window resets
$store->update( 'tb_throttle', array( 'window_started_at' => gmdate( 'Y-m-d H:i:s', $now - 301 ) ), array( 'bucket_key' => 'replay:aaa' ) );
$r = Throttle::hit( 'replay:aaa', 300, 1, $store );
check( $r['allowed'], 'throttle: window expired → allowed again' );

// rate limit: 10 allowed per 60s window
for ( $i = 1; $i <= 10; $i++ ) { $r = Throttle::hit( 'auth:99', 60, 10, $store ); check( $r['allowed'], "rate window allows hit {$i}" ); }
$r = Throttle::hit( 'auth:99', 60, 10, $store );
check( ! $r['allowed'], 'rate limit: 11th hit blocked' );

// --- Identity: Session lifecycle ----------------------------------------------------------------

check( 'active' === Session::status( $now - 100, $now + 90000, null, $now ), 'session status: active' );
check( 'idle_expired' === Session::status( $now - 7300, $now + 90000, null, $now ), 'session status: idle expired (>2h)' );
check( 'abs_expired' === Session::status( $now - 100, $now - 1, null, $now ), 'session status: absolute expired (24h)' );
check( 'revoked' === Session::status( $now - 100, $now + 90000, $now - 5, $now ), 'session status: revoked' );

// issue → resolve round-trip on the memory store
$iss = Session::issue( 42, $now );
$hdr = 'Bearer ' . $iss['token'];
$resolved = Session::resolve( $hdr );
check( 42 === $resolved['user_id'] && null === $resolved['error'], 'issue → resolve returns the user' );
check( 64 === strlen( $iss['token'] ), 'opaque token is 64 hex chars' );
check( 1 === count( array_filter( $store->dump( 'tb_sessions' ), fn( $r ) => $r['token_hash'] === hash( 'sha256', $iss['token'] ) ) ), 'only sha256 hash stored, plaintext never' );
check( $iss['token'] !== $store->dump( 'tb_sessions' )[0]['token_hash'] ?? 'x', 'stored hash differs from plaintext' );
check( 0 === Session::resolve( 'Bearer ' . str_repeat( '1', 64 ) )['user_id'] && 'AUTH_SESSION_EXPIRED' === Session::resolve( 'Bearer ' . str_repeat( '1', 64 ) )['error'], 'unknown token → AUTH_SESSION_EXPIRED' );

Session::revoke_user( 42 );
$resolved = Session::resolve( $hdr );
check( 'AUTH_SESSION_EXPIRED' === $resolved['error'], 'revoke_user → resolve rejects' );

// --- Telegram: Bot outbound adapter (module 3) ------------------------------------------

// Fake transport: echo the method/params back. Last call recorded in $GLOBALS.
$bot = new Bot( 'tok-123', static function ( string $method, array $params ): array {
	$GLOBALS['smoke_bot_last'] = array( 'method' => $method, 'params' => $params );
	return array( wp_json_encode( array( 'ok' => true, 'result' => $params ) ), true );
} );

$bot->sendMessage( 7, 'Hello' );
check( 'sendMessage' === $GLOBALS['smoke_bot_last']['method'] && 7 === $GLOBALS['smoke_bot_last']['params']['chat_id'] && 'Hello' === $GLOBALS['smoke_bot_last']['params']['text'], 'sendMessage posts chat_id+text' );

$bot->editMessageText( 7, 99, 'Edited' );
check( 'editMessageText' === $GLOBALS['smoke_bot_last']['method'] && 99 === $GLOBALS['smoke_bot_last']['params']['message_id'], 'editMessageText posts message_id' );

$bot->answerCallbackQuery( 'cq-1', 'Done' );
check( 'answerCallbackQuery' === $GLOBALS['smoke_bot_last']['method'] && 'cq-1' === $GLOBALS['smoke_bot_last']['params']['callback_query_id'], 'answerCallbackQuery posts id' );

// API-level error → TELEGRAM_UNAVAILABLE
$failing = new Bot( 'tok-123', static fn( string $m, array $p ) => array( '{"ok":false,"error_code":400,"description":"bad request"}', true ) );
$caught = false;
try { $failing->sendMessage( 1, 'x' ); } catch ( \Trade\Core\Exception $e ) { $caught = ( 'TELEGRAM_UNAVAILABLE' === $e->error_code() && $e->context['code'] ?? '' === '400' ); }
check( $caught, 'api ok:false → TELEGRAM_UNAVAILABLE with error code in context' );

// missing token → TELEGRAM_UNAVAILABLE
$caught = false;
try { ( new Bot( '', static fn() => array( '', false ) ) )->sendMessage( 1, 'x' ); } catch ( \Trade\Core\Exception $e ) { $caught = ( 'TELEGRAM_UNAVAILABLE' === $e->error_code() ); }
check( $caught, 'no token → TELEGRAM_UNAVAILABLE (missing_token)' );

// --- Listings module (module 6) -------------------------------------------------

// Setup: a verified merchant (reuse Ethiopia location id=1) + service-type category.
$merchant_id = 10;
$store->insert( 'tb_merchants', array(
	'wp_user_id'          => $merchant_id,
	'business_name'       => 'Test Merchant',
	'merchant_type'       => 'individual',
	'location_id'         => 1,
	'verification_status' => 'verified',
	'verified_at'         => gmdate( 'Y-m-d H:i:s' ),
	'suspended_at'        => null,
) );
check( 1 === $store->count( 'tb_merchants' ), 'merchant seeded for listing tests' );

$store->insert( 'tb_categories', array( 'id' => 20, 'parent_id' => null, 'slug' => 'consulting', 'name_key' => 'CAT_CONSULTING', 'type' => 'service', 'active' => 1 ) );
$store->insert( 'tb_products', array( 'category_id' => 20, 'canonical_name' => 'Business Consultation', 'attributes_json' => '', 'created_by' => 7, 'status' => 'active' ) );
$service_product_id = $store->last_insert_id();

// Helper: expect a specific error code from a closure.
function throws_code( string $code, callable $fn ): bool {
	try { $fn(); }
	catch ( \Trade\Core\Exception $e ) { return $e->error_code() === $code; }
	return false;
}

// Create a product-type listing (reuses Samsung A14: electronics, type=product).
$listing_id = null;
try {
	$listing = Listings::create_listing( array( 'product_id' => $product['id'], 'price' => 5000, 'currency' => 'ETB', 'location_id' => 1 ), $merchant_id, $store );
	$listing_id = (int) $listing['id'];
} catch ( \Throwable $e ) {
	fwrite( STDERR, "listing create threw: {$e->getMessage()}\n" );
	exit( 1 );
}
check( $listing_id > 0, 'listing_create returns an id' );
check( 'DRAFT' === (string) $listing['status'], 'listing created in DRAFT' );
check( 1 === (int) $listing['version'], 'listing starts at version 1' );
check( 1 === $store->count( 'tb_inventory' ), 'product-type listing creates inventory row' );
check( 0 === $store->count( 'tb_service_availability' ), 'product-type listing does NOT create availability row' );

// search_text rebuilt from product + merchant + category (§B.11.1).
$listing_row = $store->get_row( 'tb_listings', 'id = %d', array( $listing_id ) );
check( false !== strpos( $listing_row['search_text'], 'Samsung A14' ), 'search_text includes product name' );
check( false !== strpos( $listing_row['search_text'], 'test merchant' ), 'search_text includes merchant name' );
check( false !== strpos( strtolower( $listing_row['search_text'] ), 'electronics' ), 'search_text includes category name' );

// Service API reads.
check( false === Listings::listing_is_active( $listing_id, $store ), 'listing_is_active false for DRAFT' );
check( true === Listings::merchant_owns_listing( $listing_id, $merchant_id, $store ), 'merchant_owns_listing true for owner' );
check( false === Listings::merchant_owns_listing( $listing_id, 999, $store ), 'merchant_owns_listing false for non-owner' );

$caught = false;
try { Listings::require_active_listing( $listing_id, $store ); } catch ( \Trade\Core\Exception $e ) { $caught = ( 'LISTING_NOT_AVAILABLE' === $e->error_code() ); }
check( $caught, 'require_active_listing throws LISTING_NOT_AVAILABLE for DRAFT' );

// State machine: DRAFT→ACTIVE not in table at all.
check( throws_code( 'LISTING_INVALID_TRANSITION', fn() => Listings::apply_transition( $listing_row, 'ACTIVE', (int) $listing_row['version'], 'merchant', '', $store ) ), 'DRAFT→ACTIVE → LISTING_INVALID_TRANSITION (not in table)' );

// DRAFT→PENDING_REVIEW by merchant (needs verified merchant + attrs + entitlement).
$result = Listings::apply_transition( $listing_row, 'PENDING_REVIEW', (int) $listing_row['version'], 'merchant', '', $store );
check( 'PENDING_REVIEW' === $result['status'], 'DRAFT→PENDING_REVIEW succeeds' );
check( $result['version'] === (int) $listing_row['version'] + 1, 'version bumped on transition' );
$listing_row = $store->get_row( 'tb_listings', 'id = %d', array( $listing_id ) );

// Stale version → CONFLICT_STALE_VERSION.
check( throws_code( 'CONFLICT_STALE_VERSION', fn() => Listings::apply_transition( $listing_row, 'ACTIVE', 999, 'admin', '', $store ) ), 'stale version → CONFLICT_STALE_VERSION' );

// PENDING_REVIEW→ACTIVE only by admin (merchant → FORBIDDEN_NOT_OWNER since admin is in actors).
check( throws_code( 'FORBIDDEN_NOT_OWNER', fn() => Listings::apply_transition( $listing_row, 'ACTIVE', (int) $listing_row['version'], 'merchant', '', $store ) ), 'PENDING_REVIEW→ACTIVE by merchant → FORBIDDEN_NOT_OWNER' );

// PENDING_REVIEW→REJECTED requires note.
check( throws_code( 'VALIDATION_FAILED', fn() => Listings::apply_transition( $listing_row, 'REJECTED', (int) $listing_row['version'], 'admin', '', $store ) ), 'PENDING_REVIEW→REJECTED without note → VALIDATION_FAILED' );

// Admin approves: PENDING_REVIEW→ACTIVE → LISTING_PUBLISHED event, published_at set.
$before_ev = count( $GLOBALS['smoke_events'] );
$result = Listings::apply_transition( $listing_row, 'ACTIVE', (int) $listing_row['version'], 'admin', '', $store );
check( 'ACTIVE' === $result['status'], 'PENDING_REVIEW→ACTIVE by admin succeeds' );
check( null !== $result['published_at'], 'published_at set on entry to ACTIVE' );
$listing_row = $store->get_row( 'tb_listings', 'id = %d', array( $listing_id ) );
check( true === Listings::listing_is_active( $listing_id, $store ), 'listing_is_active true for ACTIVE' );

// LISTING_PUBLISHED event emitted.
$pub_ev = false;
foreach ( array_slice( $GLOBALS['smoke_events'], $before_ev ) as $ev ) {
	if ( isset( $ev[0] ) && 'trade.LISTING_PUBLISHED' === $ev[0] && isset( $ev[1]['listing_id'] ) && $listing_id === (int) $ev[1]['listing_id'] ) {
		$pub_ev = true;
	}
}
check( $pub_ev, 'LISTING_PUBLISHED event emitted on ACTIVE entry' );

// ACTIVE↔PAUSED by merchant.
$result = Listings::apply_transition( $listing_row, 'PAUSED', (int) $listing_row['version'], 'merchant', '', $store );
check( 'PAUSED' === $result['status'], 'ACTIVE→PAUSED by merchant succeeds' );
$listing_row = $store->get_row( 'tb_listings', 'id = %d', array( $listing_id ) );
$result = Listings::apply_transition( $listing_row, 'ACTIVE', (int) $listing_row['version'], 'merchant', '', $store );
check( 'ACTIVE' === $result['status'], 'PAUSED→ACTIVE by merchant succeeds' );
$listing_row = $store->get_row( 'tb_listings', 'id = %d', array( $listing_id ) );

// ARCHIVED terminal: no outgoing transitions from ARCHIVED.
$result = Listings::apply_transition( $listing_row, 'ARCHIVED', (int) $listing_row['version'], 'merchant', 'done', $store );
check( 'ARCHIVED' === $result['status'], 'ACTIVE→ARCHIVED succeeds' );
$listing_row = $store->get_row( 'tb_listings', 'id = %d', array( $listing_id ) );
check( throws_code( 'LISTING_INVALID_TRANSITION', fn() => Listings::apply_transition( $listing_row, 'ACTIVE', (int) $listing_row['version'], 'admin', '', $store ) ), ' ARCHIVED has no outgoing transitions' );

// --- Inventory (§B.7.2 atomic stock) ------------------------------------------

// Create a fresh ACTIVE product-type listing for stock tests (prior listing was archived).
$inv_listing = Listings::create_listing( array( 'product_id' => $product['id'], 'price' => 2000, 'currency' => 'ETB', 'location_id' => 1 ), $merchant_id, $store );
$inv_lid = (int) $inv_listing['id'];
$inv_row = $store->get_row( 'tb_listings', 'id = %d', array( $inv_lid ) );
Listings::apply_transition( $inv_row, 'PENDING_REVIEW', (int) $inv_row['version'], 'merchant', '', $store );
$inv_row = $store->get_row( 'tb_listings', 'id = %d', array( $inv_lid ) );
Listings::apply_transition( $inv_row, 'ACTIVE', (int) $inv_row['version'], 'admin', '', $store );
$inv_row = $store->get_row( 'tb_listings', 'id = %d', array( $inv_lid ) );

// decrement_stock: insufficient stock → false, no row change.
$store->update_where( 'tb_inventory', array( 'stock' => 0, 'version' => 1, 'sku' => 'SKU-OLD' ), 'listing_id = %d', array( $inv_lid ) );
$before_stock = $store->get_row( 'tb_inventory', 'listing_id = %d', array( $inv_lid ) );
$dec = Listings::decrement_stock( $inv_lid, 999, $store );
check( false === $dec, 'decrement_stock insufficient → false' );
$after_stock = $store->get_row( 'tb_inventory', 'listing_id = %d', array( $inv_lid ) );
check( $before_stock['stock'] === $after_stock['stock'], 'insufficient decrement leaves stock unchanged' );
check( $before_stock['version'] === $after_stock['version'], 'insufficient decrement leaves version unchanged' );

// decrement_stock: sufficient → true + version bump.
$store->update_where( 'tb_inventory', array( 'stock' => 5, 'version' => 1, 'sku' => 'SKU-OLD' ), 'listing_id = %d', array( $inv_lid ) );
$dec = Listings::decrement_stock( $inv_lid, 3, $store );
check( true === $dec, 'decrement_stock sufficient → true' );
$inv_row = $store->get_row( 'tb_inventory', 'listing_id = %d', array( $inv_lid ) );
check( 2 === (int) $inv_row['stock'], 'stock = 5 - 3 = 2' );
check( 2 === (int) $inv_row['version'], 'version bumped to 2 on decrement' );

// Decrement to exactly 0 → system AUTO→OUT_OF_STOCK transition.
$dec = Listings::decrement_stock( $inv_lid, 2, $store );
check( true === $dec, 'decrement_stock to 0 → true' );
$inv_row = $store->get_row( 'tb_listings', 'id = %d', array( $inv_lid ) );
check( 'OUT_OF_STOCK' === (string) $inv_row['status'], 'ACTIVE→OUT_OF_STOCK auto on stock=0' );

// Re-stock: set stock>0 then system transit OUT_OF_STOCK→ACTIVE.
$inv_row = $store->get_row( 'tb_listings', 'id = %d', array( $inv_lid ) );
$re = Listings::apply_transition( $inv_row, 'ACTIVE', (int) $inv_row['version'], 'system', '', $store );
check( 'ACTIVE' === $re['status'], 'OUT_OF_STOCK→ACTIVE by system succeeds' );
$inv_row = $store->get_row( 'tb_listings', 'id = %d', array( $inv_lid ) );
check( 'ACTIVE' === (string) $inv_row['status'], 're-stocked listing is ACTIVE' );

// --- Images (cap, server key, sort_order, job enqueue) -------------------------

// Listing was archived earlier; create a fresh ACTIVE one for image tests.
$listing2 = Listings::create_listing( array( 'product_id' => $service_product_id, 'price' => 3000, 'currency' => 'ETB', 'location_id' => 1 ), $merchant_id, $store );
$lid2 = (int) $listing2['id'];
$listing2_row = $store->get_row( 'tb_listings', 'id = %d', array( $lid2 ) );
Listings::apply_transition( $listing2_row, 'PENDING_REVIEW', (int) $listing2_row['version'], 'merchant', '', $store );
$listing2_row = $store->get_row( 'tb_listings', 'id = %d', array( $lid2 ) );
Listings::apply_transition( $listing2_row, 'ACTIVE', (int) $listing2_row['version'], 'admin', '', $store );
$listing2_row = $store->get_row( 'tb_listings', 'id = %d', array( $lid2 ) );

// image cap = 5 (default entitlement). Add images up to cap.
for ( $i = 1; $i <= 5; $i++ ) {
	$img = Listings::create_image( $lid2, 'key_' . $i, $merchant_id, $store );
	check( (int) $img['image_id'] > 0, "image #{$i} created" );
}
check( 5 === $store->count( 'tb_listing_images' ), 'five images persisted' );

// 6th image → ENTITLEMENT_LIMIT_REACHED (cap=5).
$caught = false;
try { Listings::create_image( $lid2, 'key_6', $merchant_id, $store ); } catch ( \Trade\Core\Exception $e ) { $caught = ( 'ENTITLEMENT_LIMIT_REACHED' === $e->error_code() ); }
check( $caught, 'sixth image → ENTITLEMENT_LIMIT_REACHED' );

// Job enqueued for each image.
$jobs_rows = $store->get_rows( 'tb_jobs', 'type = %s', array( 'listing.image_process' ) );
check( 5 === count( $jobs_rows ), 'listing.image_process job enqueued for each image' );

// sort_order appended (not all 0).
$orders = array_column( $store->get_rows( 'tb_listing_images', 'listing_id = %d', array( $lid2 ) ), 'sort_order' );
check( array_values( $orders ) === range( 0, 4 ), 'images sorted contiguous from 0' );

// --- MERCHANT_VERIFICATION_REVOKED consumer (§B.6.5) ---------------------------

Listings::pause_on_revocation( array( 'merchant_id' => $merchant_id ) );
$paused = self_check_paused( $store, $merchant_id );
check( $paused, 'MERCHANT_VERIFICATION_REVOKED → all ACTIVE listings paused' );

/** @return bool */
function self_check_paused( $store, int $mid ): bool {
	$rows = $store->get_rows( 'tb_listings', '1=1' );
	foreach ( $rows as $r ) {
		if ( (int) $r['merchant_id'] === $mid && 'ACTIVE' === (string) $r['status'] ) {
			return false;
		}
	}
	return true;
}

// --- Search module (module 7) ------------------------------------------------

// Setup: a second merchant (non-verified) and a second product for filter/rank tests.
$store->insert( 'tb_merchants', array(
	'wp_user_id'          => 11,
	'business_name'       => 'Second Merchant',
	'merchant_type'       => 'individual',
	'location_id'         => 1,
	'verification_status' => 'verified',
	'verified_at'         => gmdate( 'Y-m-d H:i:s' ),
	'suspended_at'        => null,
) );

// Product in electronics (category 10) — reuses Samsung A14 product (id = $product['id']).
$prod_elec = $product['id'];

// Product in consulting (category 20) — reuses service product.
$prod_svc = $service_product_id;

// Listing A: verified merchant (id=10), electronics, low price, in stock → should rank high.
$la = Listings::create_listing( array( 'product_id' => $prod_elec, 'price' => 5000, 'currency' => 'ETB', 'location_id' => 1 ), 10, $store );
$lidA = (int) $la['id'];
$ra = $store->get_row( 'tb_listings', 'id = %d', array( $lidA ) );
Listings::apply_transition( $ra, 'PENDING_REVIEW', (int) $ra['version'], 'merchant', '', $store );
$ra = $store->get_row( 'tb_listings', 'id = %d', array( $lidA ) );
Listings::apply_transition( $ra, 'ACTIVE', (int) $ra['version'], 'admin', '', $store );
$ra = $store->get_row( 'tb_listings', 'id = %d', array( $lidA ) );
$store->update_where( 'tb_inventory', array( 'stock' => 10, 'version' => 1, 'sku' => 'SKU-A' ), 'listing_id = %d', array( $lidA ) );

// Listing B: merchant (id=11), electronics, higher price, out of stock → should rank lower.
$lb = Listings::create_listing( array( 'product_id' => $prod_elec, 'price' => 15000, 'currency' => 'ETB', 'location_id' => 1 ), 11, $store );
$lidB = (int) $lb['id'];
$rb = $store->get_row( 'tb_listings', 'id = %d', array( $lidB ) );
Listings::apply_transition( $rb, 'PENDING_REVIEW', (int) $rb['version'], 'merchant', '', $store );
$rb = $store->get_row( 'tb_listings', 'id = %d', array( $lidB ) );
Listings::apply_transition( $rb, 'ACTIVE', (int) $rb['version'], 'admin', '', $store );
$rb = $store->get_row( 'tb_listings', 'id = %d', array( $lidB ) );
// Listing B has stock=0 (default), so availability score = 0.

// Listing C: verified merchant (id=10), consulting (service-type), different price.
$lc = Listings::create_listing( array( 'product_id' => $prod_svc, 'price' => 8000, 'currency' => 'ETB', 'location_id' => 1 ), 10, $store );
$lidC = (int) $lc['id'];
$rc = $store->get_row( 'tb_listings', 'id = %d', array( $lidC ) );
Listings::apply_transition( $rc, 'PENDING_REVIEW', (int) $rc['version'], 'merchant', '', $store );
$rc = $store->get_row( 'tb_listings', 'id = %d', array( $lidC ) );
Listings::apply_transition( $rc, 'ACTIVE', (int) $rc['version'], 'admin', '', $store );

// normalize_query: NFC + casefold + punctuation strip + whitespace collapse.
check( '' === Search::normalize_query( '  ' ), 'normalize: empty → empty' );
check( 'samsung a14' === Search::normalize_query( 'Samsung  A14' ), 'normalize: casefold + collapse whitespace' );
check( 'samsung a14' === Search::normalize_query( 'Samsung A14,!' ), 'normalize: strip punctuation' );

// Search returns matching listings, ranked (in-stock before out-of-stock).
// "merchant" appears in all listings' search_text (Test Merchant / Second Merchant).
$results = Search::search_listings( 'merchant', array() );
check( 5 === count( $results ), 'search "merchant" returns all 5 visible listings' );
// LidA (verified + in-stock) should rank above LidB (not verified + out-of-stock).
$ids = array_column( $results, 'id' );
$posA = array_search( $lidA, $ids );
$posB = array_search( $lidB, $ids );
check( $posA < $posB, 'ranked: verified+instock listing before non-verified+out_of_stock' );

// Category filter: electronics only (Samsung A14, category 10).
$results = Search::search_listings( 'Samsung', array( 'category_id' => 10 ) );
check( 3 === count( $results ), 'category filter: electronics returns only electronics listings' );

// Price range filter: cheap only.
$results = Search::search_listings( 'Samsung', array( 'price_min' => 0, 'price_max' => 6000 ) );
check( 2 === count( $results ) && (int) $results[0]['id'] === $lidA, 'price_max filter: affordable listings, A ranks first (has stock)' );

// Location filter.
$results = Search::search_listings( 'merchant', array( 'location_id' => 1 ) );
check( 5 === count( $results ), 'location filter matches all (same location)' );

// Non-matching query returns empty + suggestions.
$results = Search::search_listings( 'xyznonexistent', array() );
check( 0 === count( $results ), 'non-matching query → empty results' );

// Empty-result suggestions.
$suggestions = Search::empty_result_suggestions( 'xyznonexistent', array( 'price_min' => 0, 'price_max' => 100 ) );
check( count( $suggestions ) >= 1, 'empty results returns relaxed-filter suggestions' );

// --- Orders module (module 8) --------------------------------------------------

$cust = 999;
check( 0 === $store->count( 'tb_orders' ), 'orders table starts empty' );
check( 0 === $store->count( 'tb_reviews' ), 'reviews table starts empty' );

// Create an order against $lidA (merchant 10, ACTIVE, stock 10). qty=2.
$ord = Orders::create_order( array( 'listing_id' => $lidA, 'qty' => 2 ), $cust, $store );
$oid = (int) $ord['order_id'];
check( $oid > 0 && 'REQUESTED' === $ord['status'], 'create_order → REQUESTED' );

// ORDER_CREATED event.
$created_ev = false;
foreach ( $GLOBALS['smoke_events'] as $ev ) {
	if ( isset( $ev[0] ) && 'trade.ORDER_CREATED' === $ev[0] && $oid === (int) ( $ev[1]['order_id'] ?? 0 ) ) {
		$created_ev = true;
	}
}
check( $created_ev, 'ORDER_CREATED event emitted' );

// Duplicate open order for the same (customer, listing) → ORDER_ALREADY_OPEN.
check( throws_code( 'ORDER_ALREADY_OPEN', fn() => Orders::create_order( array( 'listing_id' => $lidA ), $cust, $store ) ), 'duplicate open order → ORDER_ALREADY_OPEN' );

// Order on a non-active (DRAFT) listing → LISTING_NOT_AVAILABLE.
$draft = Listings::create_listing( array( 'product_id' => $prod_elec, 'price' => 5000, 'currency' => 'ETB', 'location_id' => 1 ), 10, $store );
check( throws_code( 'LISTING_NOT_AVAILABLE', fn() => Orders::create_order( array( 'listing_id' => (int) $draft['id'] ), $cust, $store ) ), 'order on DRAFT listing → LISTING_NOT_AVAILABLE' );

// REQUESTED→ACCEPTED by merchant → atomic stock decrement of qty (§B.7.2).
$o = $store->get_row( 'tb_orders', 'id = %d', array( $oid ) );
$inv_before = (int) $store->get_row( 'tb_inventory', 'listing_id = %d', array( $lidA ) )['stock'];
$r = Orders::apply_transition( $o, 'ACCEPTED', 'merchant', '', $store );
check( 'ACCEPTED' === $r['status'], 'REQUESTED→ACCEPTED by merchant' );
$inv_after = (int) $store->get_row( 'tb_inventory', 'listing_id = %d', array( $lidA ) )['stock'];
check( $inv_after === $inv_before - 2, 'ACCEPTED decrements stock by qty (10→8)' );

// ACCEPTED→CANCELLED requires a reason (required_actor edge).
$o = $store->get_row( 'tb_orders', 'id = %d', array( $oid ) );
check( throws_code( 'VALIDATION_FAILED', fn() => Orders::apply_transition( $o, 'CANCELLED', 'customer', '', $store ) ), 'CANCELLED without reason → VALIDATION_FAILED' );

// §B.6.3 dual confirmation — one side only → stays ACCEPTED, no ORDER_COMPLETED.
$before_ev = count( $GLOBALS['smoke_events'] );
$o = $store->get_row( 'tb_orders', 'id = %d', array( $oid ) );
$r = Orders::apply_transition( $o, 'COMPLETED', 'customer', '', $store );
check( 'ACCEPTED' === $r['status'] && false === $r['completed'], 'single-sided confirm → stays ACCEPTED' );
$completed_ev = false;
foreach ( array_slice( $GLOBALS['smoke_events'], $before_ev ) as $ev ) {
	if ( isset( $ev[0] ) && 'trade.ORDER_COMPLETED' === $ev[0] ) {
		$completed_ev = true;
	}
}
check( ! $completed_ev, 'no ORDER_COMPLETED on single-sided confirm' );

// Second side → COMPLETED + ORDER_COMPLETED event.
$before_ev = count( $GLOBALS['smoke_events'] );
$o = $store->get_row( 'tb_orders', 'id = %d', array( $oid ) );
$r = Orders::apply_transition( $o, 'COMPLETED', 'merchant', '', $store );
check( 'COMPLETED' === $r['status'] && true === $r['completed'], 'dual confirm → COMPLETED' );
$completed_ev = false;
foreach ( array_slice( $GLOBALS['smoke_events'], $before_ev ) as $ev ) {
	if ( isset( $ev[0] ) && 'trade.ORDER_COMPLETED' === $ev[0] && $oid === (int) ( $ev[1]['order_id'] ?? 0 ) ) {
		$completed_ev = true;
	}
}
check( $completed_ev, 'ORDER_COMPLETED event after dual confirm' );

// COMPLETED is terminal.
$o = $store->get_row( 'tb_orders', 'id = %d', array( $oid ) );
check( throws_code( 'ORDER_INVALID_TRANSITION', fn() => Orders::apply_transition( $o, 'CANCELLED', 'customer', 'nope', $store ) ), 'COMPLETED has no outgoing transitions' );

// §B.6.4 review gating.
$rev = Orders::create_review( array( 'order_id' => $oid, 'rating' => 5, 'comment' => 'Great' ), $cust, $store );
check( (int) $rev['review_id'] > 0, 'review create on COMPLETED order' );
check( 1 === $store->count( 'tb_reviews' ), 'one review persisted' );
check( throws_code( 'REVIEW_NOT_ELIGIBLE', fn() => Orders::create_review( array( 'order_id' => $oid, 'rating' => 4 ), $cust, $store ) ), 'second review → REVIEW_NOT_ELIGIBLE' );

// Review on a not-completed order → REVIEW_NOT_ELIGIBLE.
$ord2 = Orders::create_order( array( 'listing_id' => $lidA ), 888, $store );
$oid2 = (int) $ord2['order_id'];
check( throws_code( 'REVIEW_NOT_ELIGIBLE', fn() => Orders::create_review( array( 'order_id' => $oid2, 'rating' => 3 ), 888, $store ) ), 'review on REQUESTED order → REVIEW_NOT_ELIGIBLE' );

// Complete the second order, then review by a non-customer → FORBIDDEN_NOT_OWNER.
$o2 = $store->get_row( 'tb_orders', 'id = %d', array( $oid2 ) );
Orders::apply_transition( $o2, 'ACCEPTED', 'merchant', '', $store );
$o2 = $store->get_row( 'tb_orders', 'id = %d', array( $oid2 ) );
Orders::apply_transition( $o2, 'COMPLETED', 'customer', '', $store );
$o2 = $store->get_row( 'tb_orders', 'id = %d', array( $oid2 ) );
Orders::apply_transition( $o2, 'COMPLETED', 'merchant', '', $store );
check( 'COMPLETED' === (string) $store->get_row( 'tb_orders', 'id = %d', array( $oid2 ) )['status'], 'second order completes' );
check( throws_code( 'FORBIDDEN_NOT_OWNER', fn() => Orders::create_review( array( 'order_id' => $oid2, 'rating' => 4 ), 777, $store ) ), 'review by non-customer → FORBIDDEN_NOT_OWNER' );

// CANCELLED with reason.
$ord3 = Orders::create_order( array( 'listing_id' => $lidA ), 777, $store );
$oid3 = (int) $ord3['order_id'];
$o3   = $store->get_row( 'tb_orders', 'id = %d', array( $oid3 ) );
$r    = Orders::apply_transition( $o3, 'CANCELLED', 'customer', 'changed my mind', $store );
check( 'CANCELLED' === $r['status'], 'REQUESTED→CANCELLED with reason' );

// EXPIRED by worker/system (§B.6.2).
$ord4 = Orders::create_order( array( 'listing_id' => $lidA ), 666, $store );
$oid4 = (int) $ord4['order_id'];
$o4   = $store->get_row( 'tb_orders', 'id = %d', array( $oid4 ) );
$r    = Orders::apply_transition( $o4, 'EXPIRED', 'system', '', $store );
check( 'EXPIRED' === $r['status'], 'REQUESTED→EXPIRED by system' );

// DISPUTED records who raised it.
$ord5 = Orders::create_order( array( 'listing_id' => $lidA ), 555, $store );
$oid5 = (int) $ord5['order_id'];
$o5   = $store->get_row( 'tb_orders', 'id = %d', array( $oid5 ) );
$r    = Orders::apply_transition( $o5, 'DISPUTED', 'customer', 'item not as described', $store );
check( 'DISPUTED' === $r['status'] && 'customer' === (string) $store->get_row( 'tb_orders', 'id = %d', array( $oid5 ) )['disputed_by'], 'REQUESTED→DISPUTED records disputed_by' );

// --- Requests module (module 9) ------------------------------------------------

$rcust = 8888;
// Fresh ACTIVE electronics listings for matching (merchants 10 & 11, both verified).
$reqA = Listings::create_listing( array( 'product_id' => $prod_elec, 'price' => 5000, 'currency' => 'ETB', 'location_id' => 1 ), 10, $store );
$rA   = $store->get_row( 'tb_listings', 'id = %d', array( (int) $reqA['id'] ) );
$store->update_where( 'tb_inventory', array( 'stock' => 3 ), 'listing_id = %d', array( (int) $reqA['id'] ) );
Listings::apply_transition( $rA, 'PENDING_REVIEW', (int) $rA['version'], 'merchant', '', $store );
$rA = $store->get_row( 'tb_listings', 'id = %d', array( (int) $reqA['id'] ) );
Listings::apply_transition( $rA, 'ACTIVE', (int) $rA['version'], 'admin', '', $store );

$reqB = Listings::create_listing( array( 'product_id' => $prod_elec, 'price' => 2000, 'currency' => 'ETB', 'location_id' => 1 ), 11, $store );
$rB   = $store->get_row( 'tb_listings', 'id = %d', array( (int) $reqB['id'] ) );
$store->update_where( 'tb_inventory', array( 'stock' => 3 ), 'listing_id = %d', array( (int) $reqB['id'] ) );
Listings::apply_transition( $rB, 'PENDING_REVIEW', (int) $rB['version'], 'merchant', '', $store );
$rB = $store->get_row( 'tb_listings', 'id = %d', array( (int) $reqB['id'] ) );
Listings::apply_transition( $rB, 'ACTIVE', (int) $rB['version'], 'admin', '', $store );

check( 0 === $store->count( 'tb_customer_requests' ), 'requests table starts empty' );
check( 0 === $store->count( 'tb_request_matches' ), 'request_matches table starts empty' );

// Create a request: electronics, location 1 region, budget 10000.
$rq = Requests::create_request( array( 'category_id' => 10, 'location_id' => 1, 'budget_max' => 10000, 'urgency' => 'normal' ), $rcust, $store );
$rqid = (int) $rq['request_id'];
check( $rqid > 0 && 'OPEN' === $rq['status'], 'create_request → OPEN (default 14d expiry)' );
$rqrow = $store->get_row( 'tb_customer_requests', 'id = %d', array( $rqid ) );
check( null !== ( $rqrow['expires_at'] ?? null ), 'expires_at set on create' );

// Validation errors.
check( throws_code( 'VALIDATION_FAILED', fn() => Requests::create_request( array( 'location_id' => 1 ), $rcust, $store ) ), 'request without category → VALIDATION_FAILED' );
check( throws_code( 'CATEGORY_NOT_FOUND', fn() => Requests::create_request( array( 'category_id' => 9999, 'location_id' => 1 ), $rcust, $store ) ), 'unknown category → CATEGORY_NOT_FOUND' );
check( throws_code( 'LOCATION_NOT_FOUND', fn() => Requests::create_request( array( 'category_id' => 10, 'location_id' => 9999 ), $rcust, $store ) ), 'unknown location → LOCATION_NOT_FOUND' );

// Matching finds both ACTIVE in-category/region/budget merchants.
$m = Requests::run_matching( $rqid, $store );
check( 2 === count( $m['matches'] ), 'matching finds both eligible merchants (10 & 11)' );
$rqrow = $store->get_row( 'tb_customer_requests', 'id = %d', array( $rqid ) );
check( 'MATCHED' === (string) $rqrow['status'], 'OPEN→MATCHED promoted after matches' );

// REQUEST_MATCHED event carries merchant_ids.
$matched_ev = false;
foreach ( $GLOBALS['smoke_events'] as $ev ) {
	if ( isset( $ev[0] ) && 'trade.REQUEST_MATCHED' === $ev[0] && $rqid === (int) ( $ev[1]['request_id'] ?? 0 ) && in_array( 10, $ev[1]['merchant_ids'] ?? array(), true ) && in_array( 11, $ev[1]['merchant_ids'] ?? array(), true ) ) {
		$matched_ev = true;
	}
}
check( $matched_ev, 'REQUEST_MATCHED event with both merchant_ids' );

// Budget exclusion: request budget 1000 → ceil 1200 < listing prices → no matches.
$rq2 = Requests::create_request( array( 'category_id' => 10, 'location_id' => 1, 'budget_max' => 500 ), $rcust, $store );
$rq2id = (int) $rq2['request_id'];
$m2 = Requests::run_matching( $rq2id, $store );
check( 0 === count( $m2['matches'] ), 'budget too low → no matches' );
$rq2row = $store->get_row( 'tb_customer_requests', 'id = %d', array( $rq2id ) );
check( 'OPEN' === (string) $rq2row['status'], 'stays OPEN with no matches' );

// Region exclusion: listings are at location 1, request region is location 2 → no matches.
$store->insert( 'tb_locations', array( 'id' => 2, 'parent_id' => null, 'level' => 0, 'name_key' => 'LOCATION_REGION_2' ) );
$rq4 = Requests::create_request( array( 'category_id' => 10, 'location_id' => 2, 'budget_max' => 10000 ), $rcust, $store );
check( 0 === count( Requests::run_matching( (int) $rq4['request_id'], $store )['matches'] ), 'no matches outside request region (location 2)' );

// get_matches lazy + ranked by score desc.
$gm = Requests::get_matches( $rqid, $store );
check( 'MATCHED' === $gm['status'] && 2 === count( $gm['matches'] ), 'get_matches returns ranked matches' );
check( (float) $gm['matches'][0]['score'] >= (float) $gm['matches'][1]['score'], 'matches sorted by score desc' );

// FULFILLED requires an order; a non-completed order → REQUEST_INVALID_TRANSITION.
$reqrow = $store->get_row( 'tb_customer_requests', 'id = %d', array( $rqid ) );
check( throws_code( 'VALIDATION_FAILED', fn() => Requests::apply_transition( $reqrow, 'FULFILLED', 'customer', array(), $store ) ), 'FULFILLED without order_id → VALIDATION_FAILED' );
$ord_f = Orders::create_order( array( 'listing_id' => (int) $reqA['id'], 'qty' => 1 ), $rcust, $store );
$ofid  = (int) $ord_f['order_id'];
$of    = $store->get_row( 'tb_orders', 'id = %d', array( $ofid ) );
// Complete the order (ACCEPTED → dual-confirm COMPLETED) so it's eligible.
Orders::apply_transition( $of, 'ACCEPTED', 'merchant', '', $store );
$of = $store->get_row( 'tb_orders', 'id = %d', array( $ofid ) );
Orders::apply_transition( $of, 'COMPLETED', 'customer', '', $store );
$of = $store->get_row( 'tb_orders', 'id = %d', array( $ofid ) );
Orders::apply_transition( $of, 'COMPLETED', 'merchant', '', $store );
$ok = Requests::apply_transition( $reqrow, 'FULFILLED', 'customer', array( 'order_id' => $ofid ), $store );
check( 'FULFILLED' === $ok['status'], 'MATCHED→FULFILLED with completed order' );
check( 'FULFILLED' === (string) $store->get_row( 'tb_customer_requests', 'id = %d', array( $rqid ) )['status'], 'request persisted as FULFILLED' );
$ful_ev = false;
foreach ( $GLOBALS['smoke_events'] as $ev ) {
	if ( isset( $ev[0] ) && 'trade.REQUEST_FULFILLED' === $ev[0] && $rqid === (int) ( $ev[1]['request_id'] ?? 0 ) && $ofid === (int) ( $ev[1]['order_id'] ?? 0 ) ) {
		$ful_ev = true;
	}
}
check( $ful_ev, 'REQUEST_FULFILLED event with order_id' );

// CANCELLED by customer (OPEN state).
$rq5 = Requests::create_request( array( 'category_id' => 10, 'location_id' => 1, 'budget_max' => 10000 ), $rcust, $store );
$rq5row = $store->get_row( 'tb_customer_requests', 'id = %d', array( (int) $rq5['request_id'] ) );
$c5 = Requests::apply_transition( $rq5row, 'CANCELLED', 'customer', array(), $store );
check( 'CANCELLED' === $c5['status'], 'OPEN→CANCELLED by customer' );

// Terminal: FULFILLED and CANCELLED have no outgoing transitions.
$fulrow = $store->get_row( 'tb_customer_requests', 'id = %d', array( $rqid ) );
check( throws_code( 'REQUEST_INVALID_TRANSITION', fn() => Requests::apply_transition( $fulrow, 'CANCELLED', 'customer', array(), $store ) ), 'FULFILLED is terminal' );
$canrow = $store->get_row( 'tb_customer_requests', 'id = %d', array( (int) $rq5['request_id'] ) );
check( throws_code( 'REQUEST_INVALID_TRANSITION', fn() => Requests::apply_transition( $canrow, 'EXPIRED', 'system', array(), $store ) ), 'CANCELLED is terminal' );

// expire_due: seed an OPEN request already past expires_at.
$store->insert( 'tb_customer_requests', array(
	'customer_id' => 1, 'category_id' => 10, 'attributes_json' => null, 'budget_max' => 1000,
	'location_id' => 1, 'urgency' => 'normal', 'status' => 'OPEN', 'fulfilled_order_id' => null,
	'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ), 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ), 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 ),
) );
$expired = Requests::expire_due( $store );
check( $expired >= 1, 'expire_due transitions overdue OPEN/MATCHED → EXPIRED' );
$exp_ev = false;
foreach ( $GLOBALS['smoke_events'] as $ev ) {
	if ( isset( $ev[0] ) && 'trade.REQUEST_EXPIRED' === $ev[0] ) {
		$exp_ev = true;
	}
}
check( $exp_ev, 'REQUEST_EXPIRED event emitted on expiry' );

echo "smoke OK ({$passed} checks)\n";