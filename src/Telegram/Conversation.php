<?php
declare( strict_types=1 );

namespace Trade\Telegram;

/**
 * Bot conversation state machine (§82, §121 — conversational gateway).
 *
 * One row per chat in tb_bot_chats holds {state, data}. Every inbound text is fed
 * through step(), which computes one or more replies (text + optional keyboard) and
 * returns them. Sending is synchronous here (# ponytail: fine at MVP scale; move
 * outbound to the jobs queue when volume or webhook latency demands it).
 *
 * Flow: /start → language → role (merchant/customer) → main, with an "Open Mini App"
 * button shown after onboarding. main → assistant | language (both return to main).
 *
 * Onboarding data stored per chat in tb_bot_chats: { state, data } where data holds
 * { role, language }. The language choice also updates tb_identity.language for the
 * associated Telegram user.
 */
final class Conversation {

	public const TABLE = 'tb_bot_chats';

	private const DEFAULT_STATE = 'main';

	/** Main-menu buttons: [label, command]. Tapping a button returns its label; we map it. */
	private const ANCHORS = array(
		'🤖 AI Assistant' => 'assistant',
		'❓ Help'         => 'help',
		'ℹ️ Status'       => 'status',
		'🌐 Language'     => 'language',
		'🏠 Home'         => 'menu',
	);

	private const COMMANDS = array( 'start', 'menu', 'help', 'assistant', 'status', 'cancel', 'language', 'app' );

	/** Mini App URL — override via trade_mini_app_url option; default is the bundled page. */
	public static function mini_app_url(): string {
		$opt = (string) get_option( 'trade_mini_app_url', '' );
		return '' !== $opt ? $opt : home_url( '/wp-content/plugins/Trade/mini-app/' );
	}

	/**
	 * Handle one inbound message and return the reply strings that were sent.
	 *
	 * @param int|null $from_id Telegram user id (message.from.id; == chat.id in private chats) —
	 *                          used to persist the chosen language into tb_identity on onboarding.
	 * @return string[] the body text of each message sent (empty when token not set/bot silent)
	 */
	public static function step( int $chat_id, string $text, ?Store $store = null, ?Bot $bot = null, ?int $from_id = null ): array {
		$store = $store ?? Store::default();
		$bot   = $bot ?? new Bot();
		$from_id ??= $chat_id;

		[ $state, $data ] = self::load( $store, $chat_id );
		$input   = trim( $text );
		$actions = self::dispatch( $store, $state, $data, $input, $from_id );

		$state = $actions['state'];
		$data  = $actions['data'];
		self::save( $store, $chat_id, $state, $data, (bool) $actions['replies'] );

		// Send replies: Open Mini App (inline) goes on the FIRST reply, the reply-menu keyboard
		// on the LAST — a single step may carry both (e.g. completion → [Open button, menu]).
		$sent    = array();
		$sends   = $bot->token_set();
		$n       = count( $actions['replies'] );
		$app_idx = $n > 0 && ! empty( $actions['app_button'] ) ? 0 : null;
		$menu_idx = $n > 0 && ! empty( $actions['buttons'] ) ? $n - 1 : null;
		foreach ( $actions['replies'] as $i => $reply ) {
			$reply_markup = null;
			if ( $sends && $i === $app_idx ) {
				$reply_markup = self::app_markup();
			} elseif ( $sends && $i === $menu_idx ) {
				$reply_markup = self::markup( $actions['buttons'] );
			}
			if ( $sends ) {
				$bot->sendMessage( $chat_id, $reply, null !== $reply_markup ? array( 'reply_markup' => $reply_markup ) : array() );
			}
			$sent[] = $reply;
		}
		return $sent;
	}

	/** Pure transition: (state, data, input, from_id) → next(state, data, replies, buttons, app_button). */
	private static function dispatch( Store $store, string $state, array $data, string $input, int $from_id ): array {
		if ( '' !== $input && '/' === $input[0] ) {
			return self::command( $store, strtolower( substr( $input, 1 ) ), $data, $from_id );
		}

		if ( 'language' === $state ) {
			return self::set_language( $store, $data, strtolower( $input ), $from_id );
		}

		if ( 'role' === $state ) {
			return self::set_role( $store, $data, strtolower( $input ), $from_id );
		}

		if ( 'onboarding' === $state ) {
			return self::onboarding_done( $store, $data, $from_id );
		}

		// main: recognise an anchor button text, else fall through to help/menu.
		foreach ( self::ANCHORS as $label => $cmd ) {
			if ( 0 === strcasecmp( $label, $input ) ) {
				return self::command( $store, $cmd, $data, $from_id );
			}
		}
		return self::command( $store, 'help', $data, $from_id );
	}

	private static function command( Store $store, string $cmd, array $data, int $from_id ): array {
		if ( ! in_array( $cmd, self::COMMANDS, true ) ) {
			$cmd = 'help';
		}
		switch ( $cmd ) {
			case 'start':
				$done = (bool) ( $data['role'] ?? false ) && (bool) ( $data['language'] ?? false );
				if ( $done ) {
					return array(
						'state'      => 'main',
						'data'       => $data,
						'replies'    => array(
							"👋 Welcome back!\n\nTap the button to open the Mini App, or use /menu.",
						),
						'app_button' => true,
					);
				}
				// language first, then role — the onboarding flow.
				return array(
					'state'   => 'language',
					'data'    => array(),
					'replies' => array( "Send a language code — currently supported: en, am." ),
				);
			case 'app':
				return array(
					'state'      => 'main',
					'data'       => $data,
					'replies'    => array( 'Open the Mini App:' ),
					'app_button' => true,
				);
			case 'menu':
				return array(
					'state'   => 'main',
					'data'    => $data,
					'replies' => array( 'Main menu:' ),
					'buttons' => array_keys( self::ANCHORS ),
				);
			case 'help':
				return array(
					'state'   => 'main',
					'data'    => $data,
					'replies' => array(
						"Commands:\n" .
						"/start — welcome & onboarding\n/menu — show the main menu\n/app — open the Mini App\n" .
						"/assistant — talk to the AI assistant\n/language — change language\n" .
						"/status — system health\n/cancel — go back to the menu",
					),
				);
			case 'assistant':
				return array(
					'state'   => 'assistant',
					'data'    => $data,
					'replies' => array( "🤖 Assistant on. Ask me anything, or /cancel to go back to the menu." ),
				);
			case 'status':
				return array(
					'state'   => 'main',
					'data'    => $data,
					'replies' => array(
						"System status\n" .
						"plugin: trade\nschema: " . Db::VERSION . "\n" .
						"chats persisted: " . Store::default()->count( self::TABLE ) . "\n" .
						"ai assistant: " . ( AI::ENABLED ? 'on' : 'off (MVP)' ),
					),
				);
			case 'language':
				return array(
					'state'   => 'language',
					'data'    => $data,
					'replies' => array( "Send a language code — currently supported: en, am." ),
				);
			case 'role':
				return array(
					'state'   => 'role',
					'data'    => $data,
					'replies' => array( "Are you a merchant or a customer?" ),
					'buttons' => array( '🛍 Merchant', '🧍 Customer' ),
				);
			case 'cancel':
			default:
				return array(
					'state'   => 'main',
					'data'    => array(),
					'replies' => array( 'Back to the menu.' ),
					'buttons' => array_keys( self::ANCHORS ),
				);
		}
	}

	/** Role step: picks merchant or customer, then moves to language. */
	private static function set_role( Store $store, array $data, string $low, int $from_id ): array {
		$role = null;
		if ( str_contains( $low, 'merchant' ) || $low === 'm' ) {
			$role = 'merchant';
		} elseif ( str_contains( $low, 'customer' ) || $low === 'c' ) {
			$role = 'customer';
		}
		if ( null === $role ) {
			return array(
				'state'   => 'role',
				'data'    => $data,
				'replies' => array( "Please pick one: are you a 🛍 merchant or a 🧍 customer?" ),
				'buttons' => array( '🛍 Merchant', '🧍 Customer' ),
			);
		}
		return array(
			'state'   => 'onboarding',
			'data'    => array( 'role' => $role, 'step' => 'language' ),
			'replies' => array( "Great — {$role}! Which language should I use?\nEnglish (en) or አማርኛ (am)?" ),
			'buttons' => array( 'English (en)', 'አማርኛ (am)' ),
		);
	}

	/** Language step: validates the code, stores it, transitions to onboarding.done → main. */
	private static function set_language( Store $store, array $data, string $code, int $from_id ): array {
		$lang = $store->get_row( 'tb_languages', 'code = %s', array( $code ) );
		if ( null === $lang ) {
			return array(
				'state'   => 'language',
				'data'    => $data,
				'replies' => array( "Unknown language code \"{$code}\". Try en or am." ),
			);
		}
		$data['language'] = $code;
		// Persist the language choice into the user's identity row (telegram_user_id -> language).
		if ( $from_id > 0 ) {
			$store->update( 'tb_identity', array( 'language' => $code ), array( 'telegram_user_id' => (string) $from_id ) );
		}
		return array(
			'state'   => 'onboarding',
			'data'    => array( 'role' => $data['role'] ?? '', 'language' => $code ),
			'replies' => array( 'All set, ' . ($data['role'] ?? 'customer') . ' - tap the button to open the Mini App.' ),
			'app_button' => true,
		);
	}

	/** Onboarding-complete: state back to main, Open button ready. */
	private static function onboarding_done( Store $store, array $data, int $from_id ): array {
		// Ensure both role and language are present; if either is missing, loop back.
		if ( ! isset( $data['role'] ) || ! isset( $data['language'] ) ) {
			// fall back to role step then language step — but to keep it simple, just ask role again.
			return self::set_role( $store, $data, '', $from_id );
		}
		// Store the completed onboarding state so /start knows it's done.
		$data['completed_at'] = gmdate( 'Y-m-d H:i:s' );
		return array(
			'state'      => 'main',
			'data'       => $data,
			'replies'    => array( 'All set — the Mini App is ready for you.' ),
			'app_button' => true,
		);
	}

	// ── Persistence ──────────────────────────────────────────────────────────

	/** @return array{0:string state, 1:array data} */
	private static function load( Store $store, int $chat_id ): array {
		$row = $store->get_row( self::TABLE, 'chat_id = %d', array( $chat_id ) );
		if ( null === $row ) {
			return array( self::DEFAULT_STATE, array() );
		}
		$data = json_decode( (string) ( $row['data'] ?? '' ), true );
		return array( (string) ( $row['state'] ?? self::DEFAULT_STATE ), is_array( $data ) ? $data : array() );
	}

	private static function save( Store $store, int $chat_id, string $state, array $data, bool $persist ): void {
		if ( ! $persist && 'main' === $state && empty( $data ) ) {
			return; // nothing meaningful to keep.
		}
		$fields = array(
			'state'      => $state,
			'data'       => $data ? wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) : null,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( null === $store->get_row( self::TABLE, 'chat_id = %d', array( $chat_id ) ) ) {
			$store->insert( self::TABLE, array_merge( array( 'chat_id' => $chat_id ), $fields ) );
		} else {
			$store->update( self::TABLE, $fields, array( 'chat_id' => $chat_id ) );
		}
	}

	/** Telegram reply-keyboard JSON from a list of button labels. */
	private static function markup( array $buttons ): array {
		$keyboard = array();
		foreach ( $buttons as $label ) {
			$keyboard[] = array( array( 'text' => $label ) );
		}
		return array( 'keyboard' => $keyboard, 'resize_keyboard' => true, 'one_time_keyboard' => false );
	}

	/** Inline keyboard that opens the Mini App inside Telegram (WebApp button). */
	private static function app_markup(): array {
		return array(
			'inline_keyboard' => array(
				array( array( 'text' => '🚀 Open Mini App', 'web_app' => array( 'url' => self::mini_app_url() ) ) ),
			),
		);
	}
}