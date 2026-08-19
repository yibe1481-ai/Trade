<?php
declare( strict_types=1 );

namespace Trade\Telegram;

use Trade\AI\Service as AIService;
use Trade\Core\Db;
use Trade\Core\Store;

/**
 * Telegram onboarding & intent processing gateway:
 * Language (Inline) → Role → Conversational Intake / Intent Parsing → Contextual Mini App Handoff.
 */
final class Conversation {
	public const TABLE = 'tb_bot_chats';
	private const DEFAULT_STATE = 'main';

	private const ANCHORS = array(
		'🤖 AI Assistant' => 'assistant',
		'❓ Help'         => 'help',
		'ℹ️ Status'       => 'status',
		'🌐 Language'     => 'language',
		'🏠 Home'         => 'menu',
	);

	private const COMMANDS = array(
		'start',
		'menu',
		'help',
		'assistant',
		'status',
		'cancel',
		'language',
		'app',
	);

	public static function mini_app_url( string $params = '' ): string {
		$opt = (string) get_option( 'trade_mini_app_url', '' );
		$url = '' !== $opt ? $opt : home_url( '/wp-content/plugins/Trade/mini-app/' );
		if ( '' !== $params ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . $params;
		}
		return $url;
	}

	public static function step(
    	int $chat_id,
    	string $input,
    	?Store $store = null,
    	?Bot $bot = null,
    	?int $from_id = null,
    	?int $message_id = null
    ): void {
    	$store   = $store ?? Store::default();
    	$bot     = $bot ?? new Bot();
    	$user_id = $from_id ?? $chat_id;
    
    	// 1. Fetch current conversation state from Store
    	$row          = $store->get_row( 'tb_bot_chats', 'chat_id = %d', array( $user_id ) );
    	$data         = is_array( $row ) ? ( json_decode( (string) ( $row['data'] ?? '' ), true ) ?? array() ) : array();
    	$current_step = (string) ( $row['state'] ?? 'start' );
    
    	// 2. Handle Language Selection Callback
    	if ( str_starts_with( $input, 'lang:' ) ) {
    		$lang             = str_replace( 'lang:', '', $input ); // 'en' or 'am'
    		$data['language'] = $lang;
    
    		self::save_state( $store, $user_id, 'awaiting_role', $data );
    
    		$confirm_text = ( 'am' === $lang )
    			? "🌐 ቋንቋ ተመርጧል: አማርኛ\n\nእባክዎ ሚናዎን ይምረጡ:"
    			: "🌐 Language set to: English\n\nPlease select your role:";
    
    		$keyboard = array(
    			'inline_keyboard' => array(
    				array(
    					array( 'text' => '🛒 Buyer', 'callback_data' => 'role:buyer' ),
    					array( 'text' => '🏪 Seller', 'callback_data' => 'role:seller' ),
    				),
    			),
    		);
    
    		if ( null !== $message_id && $message_id > 0 ) {
    			$bot->editMessageText( $chat_id, $message_id, $confirm_text, array( 'reply_markup' => $keyboard ) );
    		} else {
    			$bot->sendMessage( $chat_id, $confirm_text, array( 'reply_markup' => $keyboard ) );
    		}
    		return;
    	}
    
    	// 3. Handle Role Selection Callback
    	if ( str_starts_with( $input, 'role:' ) ) {
    		$role         = str_replace( 'role:', '', $input );
    		$data['role'] = $role;
    
    		self::save_state( $store, $user_id, 'completed', $data );
    
    		$msg = ( 'buyer' === $role ) ? "Welcome! You can now browse listings." : "Welcome! You can now add listings.";
    
    		if ( null !== $message_id && $message_id > 0 ) {
    			$bot->editMessageText( $chat_id, $message_id, $msg );
    		} else {
    			$bot->sendMessage( $chat_id, $msg );
    		}
    		return;
    	}
    
    	// 4. Onboarding only on an explicit /start — a plain message must never force it.
    	if ( str_starts_with( $input, '/start' ) ) {
    		self::send_language_menu( $chat_id, $bot, $store );
    		return;
    	}

    	// 5. First contact without /start: greet once (never the language menu); save so it can't repeat.
    	if ( 'start' === $current_step ) {
    		self::save_state( $store, $user_id, 'main', array( 'greeted' => 1 ) );
    		$bot->sendMessage( $chat_id, "👋 Welcome to Trade!\n\nType /start to pick your language and role, or open the Mini App anytime." );
    		return;
    	}

    	// 6. Mid-onboarding (language picked, role pending): nudge back on track, no menu.
    	if ( 'awaiting_role' === $current_step && ! empty( $data['language'] ?? '' ) ) {
    		$bot->sendMessage(
    			$chat_id,
    			'Please choose your role to continue:',
    			array( 'reply_markup' => array( 'inline_keyboard' => array(
    				array(
    					array( 'text' => '🛒 Buyer', 'callback_data' => 'role:buyer' ),
    					array( 'text' => '🏪 Seller', 'callback_data' => 'role:seller' ),
    				),
    			) ) )
    		);
    		return;
    	}

    	// 7. Post-onboarding: AI sell-agent converses, keeps a short thread, and always
    	//    offers the Open Mini App handoff button. Falls back to a graceful message if
    	//    no AI provider is configured in Settings.
    	if ( in_array( $current_step, array( 'main', 'completed' ), true ) && '' !== trim( $input ) && $bot->token_set() ) {
    		$history        = (array) ( $data['history'] ?? array() );
    		$history[]      = array( 'role' => 'user', 'content' => $input );
    		$reply          = AIService::chat( $history );
    		$history[]      = array( 'role' => 'assistant', 'content' => $reply );
    		$data['history'] = array_slice( $history, -8 );
    		self::save_state( $store, $user_id, 'main', $data );
    		$bot->sendMessage( $chat_id, $reply, array( 'reply_markup' => self::app_markup() ) );
    		return;
    	}
    }
    
    /** Helper method to persist conversation state cleanly */
    private static function save_state( Store $store, int $chat_id, string $state, array $data ): void {
    	$fields = array(
    		'state'      => $state,
    		'data'       => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
    		'updated_at' => gmdate( 'Y-m-d H:i:s' ),
    	);
    
    	if ( null === $store->get_row( 'tb_bot_chats', 'chat_id = %d', array( $chat_id ) ) ) {
    		$store->insert( 'tb_bot_chats', array_merge( array( 'chat_id' => $chat_id ), $fields ) );
    	} else {
    		$store->update_where( 'tb_bot_chats', $fields, 'chat_id = %d', array( $chat_id ) );
    	}
    }
	
	private static function send_language_menu( int $chat_id, Bot $bot, Store $store ): void {
		$text = "Choose your language / ቋንቋ ይምረጡ:";
		$keyboard = array(
			'inline_keyboard' => array(
				array(
					array( 'text' => 'English 🇬🇧', 'callback_data' => 'lang:en' ),
					array( 'text' => 'አማርኛ 🇪🇹', 'callback_data' => 'lang:am' ),
				),
			),
		);

		$bot->sendMessage( $chat_id, $text, array( 'reply_markup' => $keyboard ) );
	}

	private static function ensure_table(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}

		$sql = "CREATE TABLE IF NOT EXISTS `tb_bot_chats` (
			`chat_id` bigint(20) NOT NULL,
			`state` varchar(40) NOT NULL DEFAULT 'main',
			`data` longtext NOT NULL,
			`updated_at` datetime NOT NULL,
			PRIMARY KEY (`chat_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;";

		$wpdb->query( $sql );
	}

	private static function dispatch( Store $store, string $state, array $data, string $input, int $from_id, ?int $message_id, Bot $bot, int $chat_id ): array {
		// A. Handle Slash Commands (e.g. /start payload, /menu)
		if ( '' !== $input && '/' === $input[0] ) {
			$raw_cmd   = strtolower( substr( $input, 1 ) );
			$parts     = explode( ' ', $raw_cmd, 2 );
			$cmd       = $parts[0];
			$parameter = $parts[1] ?? '';

			return self::command( $store, $cmd, $parameter, $data, $from_id );
		}

		// B. Onboarding States
		if ( 'language' === $state || str_starts_with( strtolower( $input ), 'lang:' ) ) {
			return self::set_language( $store, $data, self::normalize_language( $input ), $from_id, $message_id, $bot, $chat_id );
		}

		if ( 'role' === $state ) {
			return self::set_role( $data, strtolower( $input ) );
		}

		// C. Slot-filling Clarification State
		if ( 'intake_clarify' === $state ) {
			return self::handle_slot_response( $data, $input );
		}

		// D. Check Fixed Keyboard Anchors
		foreach ( self::ANCHORS as $label => $cmd ) {
			if ( 0 === strcasecmp( $label, $input ) ) {
				return self::command( $store, $cmd, '', $data, $from_id );
			}
		}

		// E. Conversational Intake (Natural Language Processing)
		if ( in_array( $state, array( 'main', 'assistant' ), true ) && '' !== $input ) {
			return self::process_natural_intent( $data, $input );
		}

		// F. Default Fallback
		return self::command( $store, 'help', '', $data, $from_id );
	}

	private static function command( Store $store, string $cmd, string $param, array $data, int $from_id ): array {
		if ( ! in_array( $cmd, self::COMMANDS, true ) ) {
			$cmd = 'help';
		}

		switch ( $cmd ) {
			case 'start':
				$data = array();
				if ( '' !== $param ) {
					$data['start_param'] = sanitize_text_field( $param );
				}
				return array(
					'state'         => 'language',
					'data'          => $data,
					'replies'       => array( "👋 Welcome!\n\nPlease select your language:" ),
					'inline_markup' => self::language_inline_markup(),
				);

			case 'app':
				return self::ready( $data );

			case 'menu':
				return array(
					'state'   => 'main',
					'data'    => $data,
					'replies' => array( 'Main menu:' ),
					'buttons' => array_keys( self::ANCHORS ),
				);

			case 'language':
				return array(
					'state'         => 'language',
					'data'          => $data,
					'replies'       => array( 'Choose your language:' ),
					'inline_markup' => self::language_inline_markup(),
				);

			case 'assistant':
				return array(
					'state'   => 'assistant',
					'data'    => $data,
					'replies' => array( '🤖 Tell me what you need. Type your request, search, or offer directly in chat.' ),
				);

			case 'status':
				$ai_status = ( class_exists( 'Trade\AI\Service' ) && defined( 'Trade\AI\Service::ENABLED' ) && AIService::ENABLED ) ? 'on' : 'off';
				return array(
					'state'   => 'main',
					'data'    => $data,
					'replies' => array( "Trade Bot\nSchema: " . Db::VERSION . "\nAI assistant: " . $ai_status ),
				);

			case 'cancel':
			case 'help':
			default:
				return array(
					'state'   => 'main',
					'data'    => $data,
					'replies' => array( "/start — onboarding\n/app — open Mini App\n/menu — main menu\n/language — change language\n/assistant — AI help\n/cancel — menu" ),
					'buttons' => array_keys( self::ANCHORS ),
				);
		}
	}

	private static function set_language( Store $store, array $data, string $code, int $from_id, ?int $message_id, Bot $bot, int $chat_id ): array {
		$lang = $store->get_row( 'tb_languages', 'code = %s AND enabled = 1', array( $code ) );
		if ( null === $lang ) {
			return array(
				'state'         => 'language',
				'data'          => $data,
				'replies'       => array( 'Please choose a supported language:' ),
				'inline_markup' => self::language_inline_markup(),
			);
		}

		$data['language'] = $code;
		if ( $from_id > 0 ) {
			$store->update( 'tb_identity', array( 'language' => $code ), array( 'telegram_user_id' => (string) $from_id ) );
		}

		// Edit original prompt message to replace inline keyboard with selection notice
		if ( null !== $message_id && $message_id > 0 ) {
			$lang_label = ( 'am' === $code ) ? 'አማርኛ' : 'English';
			if ( method_exists( $bot, 'editMessageText' ) ) {
				$bot->editMessageText( $chat_id, $message_id, "🌐 Language set to: " . $lang_label );
			} elseif ( method_exists( $bot, 'editMessageReplyMarkup' ) ) {
				$bot->editMessageReplyMarkup( $chat_id, $message_id, array( 'inline_keyboard' => array() ) );
			}
		}

		return array(
			'state'   => 'role',
			'data'    => $data,
			'replies' => array( 'Great. Now, are you a merchant or a customer?' ),
			'buttons' => array( '🛍 Merchant', '🧍 Customer' ),
		);
	}

	private static function set_role( array $data, string $low ): array {
		$role = null;
		if ( str_contains( $low, 'merchant' ) || str_contains( $low, '🛍' ) || 'm' === $low ) {
			$role = 'merchant';
		} elseif ( str_contains( $low, 'customer' ) || str_contains( $low, '🧍' ) || 'c' === $low ) {
			$role = 'customer';
		}

		if ( null === $role ) {
			return array(
				'state'   => 'role',
				'data'    => $data,
				'replies' => array( 'Please choose merchant or customer.' ),
				'buttons' => array( '🛍 Merchant', '🧍 Customer' ),
			);
		}

		$data['role']         = $role;
		$data['completed']    = true;
		$data['completed_at'] = gmdate( 'c' );

		return self::ready( $data );
	}

	private static function ready( array $data ): array {
		return array(
			'state'      => 'main',
			'data'       => $data,
			'replies'    => array( 'All set! I remember your preferences. Type what you need anytime or launch the Mini App.' ),
			'buttons'    => array_keys( self::ANCHORS ),
			'app_button' => true,
		);
	}

	private static function process_natural_intent( array $data, string $input ): array {
		if ( ! class_exists( 'Trade\AI\Service' ) || ! method_exists( 'Trade\AI\Service', 'detect_intent' ) ) {
			return array(
				'state'      => 'main',
				'data'       => $data,
				'replies'    => array( 'I received your request. Open the Mini App to continue.' ),
				'app_button' => true,
				'buttons'    => array_keys( self::ANCHORS ),
			);
		}

		$parsed = AIService::detect_intent( $input );

		if ( empty( $parsed['intent'] ) ) {
			return array(
				'state'   => 'main',
				'data'    => $data,
				'replies' => array( "I couldn't quite understand that. Try typing what you need (e.g., 'Used laptop in Jimma under 35000 ETB')." ),
				'buttons' => array_keys( self::ANCHORS ),
			);
		}

		$data['current_slots'] = $parsed['slots'] ?? array();

		if ( empty( $data['current_slots']['location'] ) ) {
			return array(
				'state'   => 'intake_clarify',
				'data'    => $data,
				'replies' => array( "Which city or area are you looking in?" ),
				'buttons' => array( '📍 Jimma', '📍 Addis Ababa', 'Skip' ),
			);
		}

		return self::render_intent_summary( $data );
	}

	private static function handle_slot_response( array $data, string $input ): array {
		if ( 'Skip' !== $input ) {
			$location                          = trim( str_replace( '📍', '', $input ) );
			$data['current_slots']['location'] = $location;
		}

		return self::render_intent_summary( $data );
	}

	private static function render_intent_summary( array $data ): array {
		$slots = $data['current_slots'] ?? array();

		$summary = "🎯 Here is what I captured:\n"
				 . "• Category: " . ( $slots['category'] ?? 'All' ) . "\n"
				 . "• Max Price: " . ( isset( $slots['budget_max'] ) ? $slots['budget_max'] . " ETB" : 'Any' ) . "\n"
				 . "• Location: " . ( $slots['location'] ?? 'All' ) . "\n\n"
				 . "Tap below to view matching results directly in the Mini App.";

		$app_payload = http_build_query( array_filter( $slots ) );

		return array(
			'state'      => 'main',
			'data'       => $data,
			'replies'    => array( $summary ),
			'app_button' => true,
			'app_params' => $app_payload,
			'buttons'    => array_keys( self::ANCHORS ),
		);
	}

	private static function language_inline_markup(): array {
		return array(
			'inline_keyboard' => array(
				array(
					array( 'text' => '🇺🇸 English', 'callback_data' => 'lang:en' ),
					array( 'text' => '🇪🇹 አማርኛ', 'callback_data' => 'lang:am' ),
				),
			),
		);
	}

	private static function normalize_language( string $input ): string {
		$input = strtolower( trim( $input ) );
		if ( str_starts_with( $input, 'lang:' ) ) {
			$input = substr( $input, 5 );
		}

		if ( str_contains( $input, 'am' ) || str_contains( $input, 'አማርኛ' ) ) {
			return 'am';
		}
		return ( 'en' === $input || str_contains( $input, 'english' ) ) ? 'en' : $input;
	}

	private static function load( Store $store, int $chat_id ): array {
		$row = $store->get_row( self::TABLE, 'chat_id = %d', array( $chat_id ) );
		if ( null === $row ) {
			return array( self::DEFAULT_STATE, array() );
		}
		$data = json_decode( (string) ( $row['data'] ?? '' ), true );
		return array( (string) ( $row['state'] ?? self::DEFAULT_STATE ), is_array( $data ) ? $data : array() );
	}

	private static function save( Store $store, int $chat_id, string $state, array $data ): void {
		$fields = array(
			'state'      => $state,
			'data'       => wp_json_encode( $data, JSON_UNESCAPED_UNICODE ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( null === $store->get_row( self::TABLE, 'chat_id = %d', array( $chat_id ) ) ) {
			$store->insert( self::TABLE, array_merge( array( 'chat_id' => $chat_id ), $fields ) );
		} else {
			$store->update( self::TABLE, $fields, array( 'chat_id' => $chat_id ) );
		}
	}

	private static function markup( array $buttons ): array {
		$keyboard = array();
		foreach ( $buttons as $label ) {
			$keyboard[] = array( array( 'text' => $label ) );
		}
		return array(
			'keyboard'          => $keyboard,
			'resize_keyboard'   => true,
			'one_time_keyboard' => false,
		);
	}

	private static function app_markup( string $params = '' ): array {
		return array(
			'inline_keyboard' => array(
				array(
					array(
						'text'    => '🚀 Open Mini App',
						'web_app' => array( 'url' => self::mini_app_url( $params ) ),
					),
				),
			),
		);
	}

	private static function log_failure( string $stage, \Throwable $e, int $chat_id ): void {
		$context = array(
			'stage'   => $stage,
			'chat_id' => $chat_id,
			'type'    => get_class( $e ),
			'message' => $e->getMessage(),
		);
		update_option( 'trade_telegram_last_webhook_error', $context, false );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Trade Telegram webhook failure: ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) );
		}
	}
}