<?php
declare( strict_types=1 );

namespace Trade\Admin;

use Trade\Core\Db;
use Trade\Core\Flags;

/**
 * WooCommerce-free admin dashboard (§B admin / Phase 6).
 *
 * One top-level "Trade" menu with read-only oversight of every module's tables,
 * feature-flag toggling, a live HTTP health check, and Telegram/settings config.
 * No external UI library — WordPress admin styles + a few cards/badges.
 * All datastore module activity is visible here even though the product surface is Telegram.
 */
final class Service {

	private const OPT_KEYS = array(
		'trade_telegram_bot_token'   => 'Telegram bot token',
		'trade_telegram_webhook_secret' => 'Telegram webhook secret',
		'trade_default_lang'         => 'Default language code',
		'trade_mini_app_url'         => 'Mini App URL',
		'trade_ai_provider'          => 'AI provider',
		'trade_ai_openrouter_key'    => 'OpenRouter API key',
		'trade_ai_openrouter_model'  => 'OpenRouter model',
		'trade_ai_groq_key'          => 'Groq API key',
		'trade_ai_groq_model'        => 'Groq model',
	);

	/** AI providers offered in Settings; value = OpenRouter-style default model. */
	private const AI_PROVIDERS = array(
		'openrouter' => array( 'label' => 'OpenRouter', 'default_model' => 'openai/gpt-4o-mini' ),
		'groq'       => array( 'label' => 'Groq',       'default_model' => 'llama-3.3-70b-versatile' ),
	);

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_head', array( self::class, 'admin_head' ) );
		add_action( 'admin_post_trade_flag_toggle', array( self::class, 'handle_flag_toggle' ) );
		add_action( 'admin_post_trade_settings', array( self::class, 'handle_settings' ) );
		add_action( 'admin_post_trade_merchant_review', array( self::class, 'handle_merchant_review' ) );
	}

	public static function menu(): void {
		add_menu_page( 'Trade', 'Trade', 'manage_options', 'trade', array( self::class, 'render_dashboard' ), 'dashicons-store', 3 );
		add_submenu_page( 'trade', 'Dashboard', 'Dashboard', 'manage_options', 'trade', array( self::class, 'render_dashboard' ) );
		add_submenu_page( 'trade', 'Feature Flags', 'Feature Flags', 'manage_options', 'trade-flags', array( self::class, 'render_flags' ) );
		add_submenu_page( 'trade', 'Orders', 'Orders', 'manage_options', 'trade-orders', array( self::class, 'render_orders' ) );
		add_submenu_page( 'trade', 'Listings', 'Listings', 'manage_options', 'trade-listings', array( self::class, 'render_listings' ) );
		add_submenu_page( 'trade', 'Merchants', 'Merchants', 'manage_options', 'trade-merchants', array( self::class, 'render_merchants' ) );
		add_submenu_page( 'trade', 'Seller Approvals', 'Seller Approvals', 'manage_options', 'trade-approvals', array( self::class, 'render_approvals' ) );
		add_submenu_page( 'trade', 'Quality', 'Quality', 'manage_options', 'trade-quality', array( self::class, 'render_quality' ) );
		add_submenu_page( 'trade', 'Logs', 'Logs', 'manage_options', 'trade-logs', array( self::class, 'render_logs' ) );
		add_submenu_page( 'trade', 'Settings', 'Settings', 'manage_options', 'trade-settings', array( self::class, 'render_settings' ) );
	}

	public static function admin_head(): void {
		$page = sanitize_key( $_GET['page'] ?? '' );
		$ours = array( 'trade', 'trade-flags', 'trade-orders', 'trade-listings', 'trade-merchants', 'trade-approvals', 'trade-quality', 'trade-logs', 'trade-settings' );
		if ( ! in_array( $page, $ours, true ) ) {
			return;
		}
		echo '<style>
			.trade-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin:16px 0;}
			.trade-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;}
			.trade-card .n{font-size:26px;font-weight:600;line-height:1;}
			.trade-card .l{color:#646970;font-size:12px;margin-top:6px;}
			.trade-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;}
			.trade-badge.on{background:#def;color:#0a4b78;}
			.trade-badge.off{background:#fde;color:#8a2f0d;}
			.trade-hint{color:#646970;font-size:12px;}
			.trade-ok{background:#f6fef9;border-left:4px solid #46b450;padding:10px 14px;margin:12px 0;}
			.trade-warn{background:#fff7e6;border-left:4px solid #f0b849;padding:10px 14px;margin:12px 0;}
			.trade-empty{color:#8c8f94;padding:18px;text-align:center;}
		</style>';
	}

	// ── Shared helpers ───────────────────────────────────────────────────────

	private static function allowed(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sorry, you are not allowed to do that.' );
		}
	}

	/** Bot wiring status for the dashboard. Token value is never echoed. */
	private static function bot_ready(): array {
		$token  = (string) get_option( 'trade_telegram_bot_token', '' );
		$info   = array(
			'token'    => '' !== $token,
			'secret'   => '' !== (string) get_option( 'trade_telegram_webhook_secret', '' ),
			'mini_app' => (string) \Trade\Telegram\Conversation::mini_app_url(),
			'webhook'  => array(),
		);
		if ( '' !== $token ) {
			$resp = wp_remote_get( 'https://api.telegram.org/bot' . $token . '/getWebhookInfo', array( 'timeout' => 10 ) );
			if ( is_wp_error( $resp ) ) {
				$info['webhook']['error'] = $resp->get_error_message();
			} else {
				$j = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
				if ( is_array( $j ) && ( $j['ok'] ?? false ) ) {
					$info['webhook'] = (array) ( $j['result'] ?? array() );
				} else {
					$info['webhook']['error'] = (string) ( $j['description'] ?? 'unreachable' );
				}
			}
		}
		return $info;
	}

	/** AI provider readiness for the Settings page (never echoes the key). */
	private static function ai_status(): array {
		if ( ! class_exists( '\Trade\AI\Service' ) || ! method_exists( '\Trade\AI\Service', 'config' ) ) {
			return array( 'ready' => false, 'label' => 'AI module not loaded' );
		}
		$cfg = \Trade\AI\Service::config();
		if ( ! ( $cfg['configured'] ?? false ) ) {
			$label = '' !== ( $cfg['provider'] ?? '' ) ? ( $cfg['provider'] . ' — no key set' ) : 'No AI provider selected';
			return array( 'ready' => false, 'label' => $label );
		}
		return array( 'ready' => true, 'label' => $cfg['provider'] . ' · ' . $cfg['model'] );
	}

	private static function count( string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // literal tb_ table names only.
	}

	private static function rows( string $sql ): array {
		global $wpdb;
		$r = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $r ) ? $r : array();
	}

	private static function cards( array $items ): void {
		echo '<div class="trade-cards">';
		foreach ( $items as $label => $n ) {
			echo '<div class="trade-card"><div class="n">' . (int) $n . '</div><div class="l">' . esc_html( $label ) . '</div></div>';
		}
		echo '</div>';
	}

	private static function table( array $headers, array $rows, array $map = array() ): void {
		echo '<table class="widefat striped" style="width:100%"><thead><tr>';
		foreach ( $headers as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="' . count( $headers ) . '" class="trade-empty">Nothing here yet.</td></tr>';
		} else {
			foreach ( $rows as $i => $row ) {
				echo '<tr>';
				foreach ( $headers as $j => $h ) {
					$v = $map[ $j ] ?? static fn( $r, $i ) => (string) ( $r[ $h ] ?? '' );
					echo '<td>' . $v( $row, $i ) . '</td>';
				}
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
	}

	private static function header( string $title, string $hint = '' ): void {
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
		if ( $hint ) {
			echo '<p class="trade-hint">' . esc_html( $hint ) . '</p>';
		}
	}

	// ── Dashboard ────────────────────────────────────────────────────────────

	public static function render_dashboard(): void {
		self::allowed();
		self::header( 'Trade Dashboard', 'Backend for the Telegram commerce bot. Data below is live from the WordPress database.' );

		$stored = (string) get_option( Db::OPTION, '' );
		if ( $stored === Db::VERSION ) {
			echo '<div class="trade-ok">Schema up to date — v' . esc_html( Db::VERSION ) . '.</div>';
		} else {
			echo '<div class="trade-warn">Schema version mismatch: stored <b>' . esc_html( $stored ) . '</b>, code expects <b>' . esc_html( Db::VERSION ) . '</b>. Visit a page so <code>maybe_upgrade</code> runs, then refresh.</div>';
		}

		$missing = array();
		foreach ( array_keys( Db::tables() ) as $t ) {
			global $wpdb;
			if ( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
				$missing[] = $t;
			}
		}
		if ( $missing ) {
			echo '<div class="trade-warn">Missing tables: ' . esc_html( implode( ', ', $missing ) ) . '</div>';
		}

		echo '<h2>Bot readiness</h2>';
		$bot = self::bot_ready();
		$row = array(
			'Bot token configured' => $bot['token'] ? '✅ yes' : '⛔ NO — set it in Settings',
			'Webhook secret'       => $bot['secret'] ? '✅ yes' : '⛔ no',
			'Mini App URL'         => $bot['mini_app'],
		);
		if ( $bot['webhook'] ) {
			$row['Telegram webhook url'] = (string) ( $bot['webhook']['url'] ?? '(none registered)' );
			if ( ! empty( $bot['webhook']['last_error_message'] ) ) {
				$row['Last webhook error'] = (string) $bot['webhook']['last_error_message'];
			}
			$row['Pending updates'] = (int) ( $bot['webhook']['pending_update_count'] ?? 0 ) . ' queued';
		} elseif ( $bot['token'] && isset( $bot['webhook']['error'] ) ) {
			$row['Webhook check'] = '⚠️ ' . $bot['webhook']['error'];
		}
		$row2d = array();
		foreach ( $row as $k => $v ) {
			$row2d[] = array( $k, $v );
		}
		self::table( array( 'setting', 'value' ), $row2d, array(
			0 => static fn( $r ) => '<strong>' . esc_html( (string) $r[0] ) . '</strong>',
			1 => static fn( $r ) => esc_html( (string) $r[1] ),
		) );

		self::cards( array(
			'Merchants'   => self::count( 'tb_merchants' ),
			'Listings'    => self::count( 'tb_listings' ),
			'Orders'      => self::count( 'tb_orders' ),
			'Reviews'     => self::count( 'tb_reviews' ),
			'Requests'    => self::count( 'tb_customer_requests' ),
			'Reports'     => self::count( 'tb_reports' ),
		) );
		self::cards( array(
			'Sessions'    => self::count( 'tb_sessions' ),
			'Identities'  => self::count( 'tb_identity' ),
			'Jobs'        => self::count( 'tb_jobs' ),
			'Events'      => self::count( 'tb_events' ),
			'Subscriptions' => self::count( 'tb_subscriptions' ),
			'Feature Flags' => self::count( 'tb_feature_flags' ),
		) );

		// Health check via the plugin's own status route (proves REST round-trip).
		$health = wp_remote_get( rest_url( 'trade/v1/system/status' ), array( 'timeout' => 10 ) );
		if ( is_wp_error( $health ) ) {
			echo '<div class="trade-warn">REST health check failed: ' . esc_html( $health->get_error_message() ) . '</div>';
		} else {
			$code = (int) wp_remote_retrieve_response_code( $health );
			echo $code === 200
				? '<div class="trade-ok">REST API responding — HTTP 200.</div>'
				: '<div class="trade-ok">REST API reachable — HTTP ' . $code . ' (icon requires an authenticated session, as expected).</div>';
		}

		echo '<h2>Recent events</h2>';
		self::table(
			array( 'event_name', 'created_at' ),
			self::rows( 'SELECT event_name, created_at FROM tb_events ORDER BY id DESC LIMIT 10' ),
			array(
				0 => static fn( $r ) => '<code>' . esc_html( (string) $r['event_name'] ) . '</code>',
			)
		);

		echo '<h2>Recent audit</h2>';
		self::table(
			array( 'actor_id', 'action', 'entity', 'entity_id', 'created_at' ),
			self::rows( 'SELECT actor_id, action, entity, entity_id, created_at FROM tb_audit_logs ORDER BY id DESC LIMIT 10' ),
			array(
				2 => static fn( $r ) => '<code>' . esc_html( (string) $r['action'] ) . '</code>',
			)
		);
		echo '</div>';
	}

	// ── Feature Flags ────────────────────────────────────────────────────────

	public static function render_flags(): void {
		self::allowed();
		self::header( 'Feature Flags', 'Toggle module features. Also surface in the /system/status endpoint.' );
		$flags = Flags::all();
		$rows  = array();
		foreach ( $flags as $key => $on ) {
			$rows[] = array(
				'key'  => $key,
				'on'   => $on,
				'ctrl' => sprintf(
					'<form method="post" action="%s" style="display:inline">%s<input type="hidden" name="flag_key" value="%s"><button class="button button-small">%s</button></form>',
					esc_url( admin_url( 'admin-post.php' ) ),
					wp_nonce_field( 'trade_flag_toggle', '_wpnonce', true, false ),
					esc_attr( $key ),
					$on ? 'Turn off' : 'Turn on'
				),
			);
		}
		self::table(
			array( 'key', 'ctrl' ),
			$rows,
			array(
				0 => static fn( $r ) => '<code>' . esc_html( $r['key'] ) . '</code> &nbsp; ' .
					( $r['on'] ? '<span class="trade-badge on">on</span>' : '<span class="trade-badge off">off</span>' ),
				1 => static fn( $r ) => $r['ctrl'],
			)
		);
		echo '</div>';
	}

	// ── Orders ───────────────────────────────────────────────────────────────

	public static function render_orders(): void {
		self::allowed();
		self::header( 'Orders', 'Order lifecycle across REQUESTED → … → COMPLETED / CANCELLED.' );
		self::table(
			array( 'id', 'customer_id', 'merchant_id', 'listing_id', 'amount', 'status', 'created_at', 'updated_at' ),
			self::rows( 'SELECT * FROM tb_orders ORDER BY id DESC LIMIT 50' ),
			array(
				4 => static fn( $r ) => esc_html( (string) $r['price'] ) . ' ' . esc_html( (string) ( $r['currency'] ?? '' ) ) .
					( ( (int) $r['qty'] ?? 1 ) > 1 ? ' ×' . (int) $r['qty'] : '' ),
			)
		);
		echo '</div>';
	}

	// ── Listings ─────────────────────────────────────────────────────────────

	public static function render_listings(): void {
		self::allowed();
		self::header( 'Listings', 'Inventory listings with their status.' );
		self::table(
			array( 'id', 'merchant_id', 'product', 'amount', 'status', 'published_at', 'updated_at' ),
			self::rows( 'SELECT * FROM tb_listings ORDER BY id DESC LIMIT 50' ),
			array(
				2 => static fn( $r ) => '#' . (int) $r['product_id'] . ( isset( $r['variant_id'] ) && $r['variant_id'] ? ' / v' . (int) $r['variant_id'] : '' ),
				3 => static fn( $r ) => esc_html( (string) $r['price'] ) . ' ' . esc_html( (string) ( $r['currency'] ?? '' ) ),
				5 => static fn( $r ) => $r['published_at'] ? esc_html( (string) $r['published_at'] ) : '<span class="trade-hint">draft</span>',
			)
		);
		echo '</div>';
	}

	// ── Merchants ────────────────────────────────────────────────────────────

	public static function render_merchants(): void {
		self::allowed();
		self::header( 'Merchants', 'Seller accounts and verification state.' );
		global $wpdb;
		self::table(
			array( 'seller', 'business_name', 'merchant_type', 'verification_status', 'verified_at' ),
			self::rows( "SELECT m.*, u.display_name FROM tb_merchants m LEFT JOIN {$wpdb->users} u ON u.ID = m.wp_user_id ORDER BY m.verification_status, m.wp_user_id DESC LIMIT 50" ),
			array(
				0 => static fn( $r ) => ( $r['display_name'] ? esc_html( (string) $r['display_name'] ) : '#' . (int) $r['wp_user_id'] ) . ' <span class="trade-hint">(user ' . (int) $r['wp_user_id'] . ')</span>',
				3 => static fn( $r ) => '<span class="trade-badge ' . ( 'verified' === $r['verification_status'] ? 'on' : 'off' ) . '">' . esc_html( (string) $r['verification_status'] ) . '</span>',
			)
		);
		echo '</div>';
	}

	// ── Seller Approvals (in-chat registrations) ─────────────────────────────

	public static function render_approvals(): void {
		self::allowed();
		self::header( 'Seller Approvals', 'Approve or reject seller registrations submitted in the Telegram chat. Approving verifies their documents and raises their level.' );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="trade-ok">Review saved.</div>';
		}

		echo '<h2>Pending approval</h2>';
		self::table(
			array( 'business', 'type', 'location', 'documents', 'level', 'decision' ),
			self::rows( "SELECT * FROM tb_merchants WHERE verification_status = 'pending' ORDER BY wp_user_id DESC" ),
			array(
				0 => static fn( $r ) => '<strong>' . esc_html( (string) $r['business_name'] ) . '</strong>',
				1 => static fn( $r ) => esc_html( (string) $r['merchant_type'] ),
				2 => static fn( $r ) => esc_html( self::location_name( (int) $r['location_id'] ) ),
				3 => static fn( $r ) => esc_html( self::merchant_docs( (int) $r['wp_user_id'] ) ),
				4 => static fn( $r ) => '<span class="trade-badge off">' . esc_html( \Trade\Verification\Service::level_for( (int) $r['wp_user_id'] ) ) . '</span>',
				5 => static fn( $r ) => self::review_controls( (int) $r['wp_user_id'] ),
			)
		);

		echo '<h2>Verified sellers</h2>';
		self::table(
			array( 'business', 'type', 'level', 'verified_at' ),
			self::rows( "SELECT * FROM tb_merchants WHERE verification_status = 'verified' ORDER BY wp_user_id DESC" ),
			array(
				0 => static fn( $r ) => '<strong>' . esc_html( (string) $r['business_name'] ) . '</strong>',
				1 => static fn( $r ) => esc_html( (string) $r['merchant_type'] ),
				2 => static fn( $r ) => '<span class="trade-badge on">' . esc_html( \Trade\Verification\Service::level_for( (int) $r['wp_user_id'] ) ) . '</span>',
				3 => static fn( $r ) => esc_html( (string) ( $r['verified_at'] ?? '' ) ),
			)
		);
		echo '</div>';
	}

	private static function location_name( int $location_id ): string {
		$rows = self::rows( 'SELECT name_key FROM tb_locations WHERE id = ' . (int) $location_id . ' LIMIT 1' );
		return $rows ? (string) $rows[0]['name_key'] : '—';
	}

	private static function merchant_docs( int $merchant_id ): string {
		$out = array();
		foreach ( self::rows( 'SELECT document_type, status FROM tb_verification_documents WHERE merchant_id = ' . (int) $merchant_id . ' ORDER BY id' ) as $d ) {
			$out[] = (string) $d['document_type'] . ':' . (string) $d['status'];
		}
		return $out ? implode( ', ', $out ) : '—';
	}

	private static function review_controls( int $merchant_id ): string {
		$approve = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
			. wp_nonce_field( 'trade_merchant_review', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="trade_merchant_review">'
			. '<input type="hidden" name="merchant_id" value="' . (int) $merchant_id . '">'
			. '<input type="hidden" name="decision" value="approve">'
			. '<button class="button button-primary button-small">Approve</button></form> ';
		$reject = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">'
			. wp_nonce_field( 'trade_merchant_review', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="trade_merchant_review">'
			. '<input type="hidden" name="merchant_id" value="' . (int) $merchant_id . '">'
			. '<input type="hidden" name="decision" value="reject">'
			. '<input type="text" name="reason" placeholder="reason (required)" class="regular-text" style="max-width:160px" required>'
			. '<button class="button button-small">Reject</button></form>';
		return $approve . $reject;
	}

	// ── Quality (reports + reviews) ──────────────────────────────────────────

	public static function render_quality(): void {
		self::allowed();
		self::header( 'Quality', 'Trust & safety reports and customer reviews.' );

		echo '<h2>Reports</h2>';
		self::table(
			array( 'id', 'entity', 'reason', 'status', 'created_at' ),
			self::rows( 'SELECT * FROM tb_reports ORDER BY id DESC LIMIT 25' ),
			array(
				1 => static fn( $r ) => esc_html( (string) $r['entity_type'] ) . ' #' . (int) $r['entity_id'],
				2 => static fn( $r ) => '<span title="' . esc_attr( (string) $r['reason'] ) . '">' . esc_html( wp_trim_words( (string) $r['reason'], 12 ) ) . '</span>',
				3 => static fn( $r ) => '<span class="trade-badge off">' . esc_html( (string) $r['status'] ) . '</span>',
			)
		);

		echo '<h2>Reviews</h2>';
		self::table(
			array( 'order_id', 'rating', 'comment', 'created_at' ),
			self::rows( 'SELECT * FROM tb_reviews ORDER BY id DESC LIMIT 25' ),
			array(
				1 => static fn( $r ) => str_repeat( '★', (int) $r['rating'] ) . '<span class="trade-hint">' . (int) $r['rating'] . '/5</span>',
				2 => static fn( $r ) => '<span title="' . esc_attr( (string) $r['comment'] ) . '">' . esc_html( wp_trim_words( (string) $r['comment'], 12 ) ) . '</span>',
			)
		);
		echo '</div>';
	}

	// ── Logs (events + audit) ────────────────────────────────────────────────

	public static function render_logs(): void {
		self::allowed();
		self::header( 'Logs', 'Domain events and the audit trail. This is the plugin’s paper trail.' );

		echo '<h2>Events</h2>';
		self::table(
			array( 'id', 'event_name', 'payload', 'created_at' ),
			self::rows( 'SELECT * FROM tb_events ORDER BY id DESC LIMIT 50' ),
			array(
				2 => static fn( $r ) => '<code>' . esc_html( wp_json_encode( json_decode( (string) $r['payload_json'], true ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</code>',
			)
		);

		echo '<h2>Audit trail</h2>';
		self::table(
			array( 'actor', 'action', 'entity', 'entity_id', 'source', 'created_at' ),
			self::rows( 'SELECT * FROM tb_audit_logs ORDER BY id DESC LIMIT 50' ),
			array(
				0 => static fn( $r ) => esc_html( (string) $r['actor_type'] ) . ':' . esc_html( (string) $r['actor_id'] ),
				1 => static fn( $r ) => '<code>' . esc_html( (string) $r['action'] ) . '</code>',
			)
		);
		echo '</div>';
	}

	// ── Settings ─────────────────────────────────────────────────────────────

	public static function render_settings(): void {
		self::allowed();
		self::header( 'Settings', 'Bot wiring. Save values, then the Telegram webhook can be registered.' );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="trade-ok">Settings saved.</div>';
		}

		$webhook = rest_url( 'trade/v1/webhook/telegram' );
		echo '<p>Webhook URL to register with Telegram:</p>';
		echo '<p><code style="word-break:break-all">' . esc_html( $webhook ) . '</code></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'trade_settings', '_wpnonce' );
		echo '<input type="hidden" name="action" value="trade_settings">';
		echo '<table class="form-table" role="presentation">';
		foreach ( self::OPT_KEYS as $key => $label ) {
			$val = (string) get_option( $key, '' );
			echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'trade_ai_provider' === $key ) {
				echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">';
				echo '<option value=""' . selected( $val, '', false ) . '>' . esc_html( '— AI off —' ) . '</option>';
				foreach ( self::AI_PROVIDERS as $code => $meta ) {
					echo '<option value="' . esc_attr( $code ) . '"' . selected( $val, $code, false ) . '>' . esc_html( $meta['label'] ) . ' (default: ' . esc_html( $meta['default_model'] ) . ')</option>';
				}
				echo '</select>';
			} elseif ( false !== strpos( $key, '_key' ) || false !== strpos( $key, 'secret' ) || false !== strpos( $key, 'token' ) ) {
				echo '<input type="password" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '" autocomplete="off">';
			} else {
				echo '<input type="text" class="regular-text" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '">';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		submit_button( 'Save settings' );
		echo '</form>';

		$ai = self::ai_status();
		echo '<h2>AI sell-agent</h2>';
		echo '<p class="trade-hint">Pick a provider above and add its API key. The Telegram bot then answers buyers as a conversational sell-agent and hands off to the Mini App with a button.</p>';
		echo $ai['ready']
			? '<div class="trade-ok">AI ready — ' . esc_html( $ai['label'] ) . '.</div>'
			: '<div class="trade-warn">' . esc_html( $ai['label'] ) . ' — open the Mini App button still shows; configure a key to enable the sell-agent.</div>';
		echo '<p class="trade-hint">Keys: <a href="https://openrouter.ai/settings/keys" target="_blank" rel="noopener">OpenRouter</a> · <a href="https://console.groq.com/keys" target="_blank" rel="noopener">Groq</a>. Models are OpenAI-compatible IDs, e.g. <code>openai/gpt-4o-mini</code> (OpenRouter) or <code>llama-3.3-70b-versatile</code> (Groq).</p>';
		echo '<p class="trade-hint">Register the webhook from a shell: <code>curl -F "url=WEBHOOK_URL" -F "secret_token=YOUR_SECRET" https://api.telegram.org/botTOKEN/setWebhook</code></p>';
		echo '<p class="trade-hint">Make the bot’s Menu button open the Mini App: <code>curl "https://api.telegram.org/botTOKEN/setChatMenuButton" -H "Content-Type: application/json" -d \'{"menu_button":{"type":"web_app","text":"Open Mini App","web_app":{"url":"MINI_APP_URL"}}}\'</code></p>';
		echo '</div>';
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	public static function handle_flag_toggle(): void {
		self::allowed();
		check_admin_referer( 'trade_flag_toggle' );
		$key = sanitize_key( $_POST['flag_key'] ?? '' );
		if ( '' === $key ) {
			wp_die( 'Missing flag.' );
		}
		$flags = Flags::all();
		Flags::set( $key, ! (bool) ( $flags[ $key ] ?? false ), get_current_user_id() );
		wp_safe_redirect( admin_url( 'admin.php?page=trade-flags' ) );
		exit;
	}

	public static function handle_settings(): void {
		self::allowed();
		check_admin_referer( 'trade_settings' );
		foreach ( array_keys( self::OPT_KEYS ) as $key ) {
			$val = isset( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
			update_option( $key, $val );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=trade-settings&saved=1' ) );
		exit;
	}

	// ── Seller review (approve / reject) ────────────────────────────────────

	public static function handle_merchant_review(): void {
		self::allowed();
		check_admin_referer( 'trade_merchant_review' );
		$merchant_id = (int) ( $_POST['merchant_id'] ?? 0 );
		$decision    = sanitize_key( $_POST['decision'] ?? '' );
		$reason      = trim( (string) wp_unslash( $_POST['reason'] ?? '' ) );
		if ( $merchant_id <= 0 || ! in_array( $decision, array( 'approve', 'reject' ), true ) ) {
			wp_die( 'Invalid review request.' );
		}
		try {
			if ( 'approve' === $decision ) {
				$out   = \Trade\Verification\Service::approve_documents( $merchant_id );
				$level = is_array( $out ) ? (string) ( $out['level'] ?? 'L0' ) : 'L0';
				self::notify_seller( $merchant_id, "🎉 Congratulations! Your seller account is verified — {$level} seller. You can now publish listings." );
			} else {
				if ( '' === $reason ) {
					wp_die( 'A reason is required to reject.' );
				}
				$row = \Trade\Verification\Service::merchant_row( $merchant_id );
				\Trade\Verification\Service::apply_transition( $row, 'rejected', 'admin', array( 'reason' => $reason ) );
				self::notify_seller( $merchant_id, "Your seller registration was not approved: {$reason}\n\nUpdate your details and we can review again." );
			}
		} catch ( \Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=trade-approvals&saved=1' ) );
		exit;
	}

	/** Best-effort Telegram notice to the seller; never blocks the admin action. */
	private static function notify_seller( int $merchant_id, string $text ): void {
		try {
			$rows = self::rows( 'SELECT telegram_user_id FROM tb_identity WHERE wp_user_id = ' . (int) $merchant_id . ' LIMIT 1' );
			if ( ! $rows ) {
				return;
			}
			$bot = new \Trade\Telegram\Bot();
			if ( $bot->token_set() ) {
				$bot->sendMessage( (int) $rows[0]['telegram_user_id'], $text );
			}
		} catch ( \Throwable $e ) {
			// notice is best-effort
		}
	}
}