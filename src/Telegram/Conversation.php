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

    		// Returning user changing language only — keep role, stay onboarded, keep talking.
    		if ( ! empty( $data['role'] ?? '' ) ) {
    			self::save_state( $store, $user_id, 'completed', $data );
    			$confirm = ( 'am' === $lang ) ? "🌐 ቋንቋ ተመርጧል: አማርኛ" : "🌐 Language set to: English";
    			if ( 'buyer' === ( $data['role'] ?? '' ) ) {
    				$confirm .= ( 'am' === $lang )
    					? "\n\nምን እየፈለጉ ነው? ንግግሩን እንቀጥል — ለመርዳት ተዘጋጅቻለሁ።"
    					: "\n\nI'm your sell-agent — what are you looking for? I'll find it for you.";
    			}
    			if ( null !== $message_id && $message_id > 0 ) {
    				$bot->editMessageText( $chat_id, $message_id, $confirm );
    			} else {
    				$bot->sendMessage( $chat_id, $confirm );
    			}
    			return;
    		}

    		self::save_state( $store, $user_id, 'awaiting_role', $data );

    		$confirm_text = ( 'am' === $lang )
    			? "🌐 ቋንቋ ተመርጧል: አማርኛ\n\nእባክዎ ሚናዎን ይምረጡ:"
    			: "🌐 Language set to: English\n\nPlease select your role:";

    		$keyboard = self::role_inline_markup();

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

    		// First-time seller → in-chat registration (business details + ID/license photos).
    		if ( 'seller' === $role && null === self::seller_merchant_id( $user_id, $store ) ) {
    			self::save_state( $store, $user_id, 'seller_reg', array(
    				'role'     => 'seller',
    				'language' => (string) ( $data['language'] ?? 'en' ),
    				'reg_step' => 'business_name',
    			) );
    			$msg = "🏪 Great, let's set up your seller account.\n\nWhat's your business name?";
    			if ( null !== $message_id && $message_id > 0 ) {
    				$bot->editMessageText( $chat_id, $message_id, $msg );
    			} else {
    				$bot->sendMessage( $chat_id, $msg );
    			}
    			return;
    		}

    		self::save_state( $store, $user_id, 'completed', $data );

    		// Buyer → open the sell-agent conversation; their next message routes to the AI (state 'completed').
    		$msg = ( 'buyer' === $role )
    			? "🤝 Welcome! I'm the Trade sell-agent.\n\nWhat are you looking for — a product or a service? Tell me what you need, your budget, and your city, and I'll help you find it."
    			: "Welcome! You can now add listings.";

    		if ( null !== $message_id && $message_id > 0 ) {
    			$bot->editMessageText( $chat_id, $message_id, $msg );
    		} else {
    			// Attach the anchored reply keyboard to the welcome itself (no extra message).
    			$bot->sendMessage( $chat_id, $msg, array( 'reply_markup' => self::anchor_markup() ) );
    		}
    		return;
    	}

    	// 3a. In-chat seller registration state machine.
    	if ( 'seller_reg' === $current_step ) {
    		self::seller_registration( $chat_id, $input, $data, $store, $user_id, $bot, $message_id );
    		return;
    	}

    	// 3b. Anchored controls (reply keyboard below the input): change language / change role / home.
    	if ( in_array( $input, array( '🌐 Language', '/language' ), true ) ) {
    		self::send_language_menu( $chat_id, $bot, $store );
    		return;
    	}
    	if ( in_array( $input, array( '🔄 Change role', '/role' ), true ) ) {
    		$bot->sendMessage( $chat_id, 'Choose a role:', array( 'reply_markup' => self::role_inline_markup() ) );
    		return;
    	}
    	if ( in_array( $input, array( '🏠 Home', '/home' ), true ) ) {
    		$bot->sendMessage( $chat_id, 'Choose an option:', array( 'reply_markup' => self::anchor_markup() ) );
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
    		$bot->sendMessage( $chat_id, 'Please choose your role to continue:', array( 'reply_markup' => self::role_inline_markup() ) );
    		return;
    	}

    	// 7. Post-onboarding: AI sell-agent converses, keeps a short thread.
    	//    Buyer: structured query → handoff button only when listings actually match.
    	//    Seller: captured item + price → create a DRAFT listing, then Mini App to manage.
    	if ( in_array( $current_step, array( 'main', 'completed' ), true ) && '' !== trim( $input ) && $bot->token_set() ) {
    		$history   = (array) ( $data['history'] ?? array() );
    		$history[] = array( 'role' => 'user', 'content' => $input );
    		$role      = (string) ( $data['role'] ?? '' );
    		$reply     = AIService::chat( $history, null, null, 'seller' === $role ? 'seller' : 'buyer' );
    		$reply     = is_string( $reply ) ? array( 'reply' => $reply, 'slots' => array() ) : $reply;
    		$slots     = is_array( $reply['slots'] ?? null ) ? $reply['slots'] : array();

    		$markup = self::anchor_markup();

    		if ( 'seller' === $role ) {
    			$out        = self::seller_step( $slots, $store, $user_id );
    			$reply_text = $out['text'];
    			$markup     = $out['markup'];
    		} else {
    			$category = trim( (string) ( $slots['category'] ?? '' ) );
    			if ( '' !== $category ) {
    				$location = trim( (string) ( $slots['location'] ?? '' ) );
    				$budget   = max( 0, (int) ( $slots['budget_max'] ?? 0 ) );
    				$q        = trim( $category . ' ' . $location );
    				$filters  = $budget > 0 ? array( 'price_max' => $budget ) : array();
    				$matches  = \Trade\Search\Service::search_listings( $q, $filters );
    				if ( count( $matches ) > 0 ) {
    					$params = array_filter( array(
    						'category'   => $category,
    						'location'   => $location,
    						'budget_max' => $budget > 0 ? $budget : null,
    					) );
    					$markup = self::app_button( self::mini_app_url( http_build_query( $params ) ) );
    				} else {
    					$reply['reply'] = "I couldn't find a match for {$category}" . ( $budget > 0 ? " under {$budget} ETB" : '' ) . " right now. Try a different item or budget, and I'll keep looking.";
    				}
    			}
    			$reply_text = is_string( $reply['reply'] ?? null ) ? $reply['reply'] : '';
    		}

    		$history[]       = array( 'role' => 'assistant', 'content' => $reply_text );
    		$data['history'] = array_slice( $history, -8 );
    		self::save_state( $store, $user_id, 'main', $data );
    		$bot->sendMessage( $chat_id, $reply_text, array( 'reply_markup' => $markup ) );
    		return;
    	}
    }

    /** Seller branch of §7: turn captured {item, price, category, location} into a DRAFT listing. */
    private static function seller_step( array $slots, Store $store, int $user_id ): array {
    	// Profile updates via chat ("change my business name to …", "I sell services now", …).
    	$profile = is_array( $slots['profile'] ?? null ) ? $slots['profile'] : null;
    	if ( $profile ) {
    		return self::apply_profile_update( $profile, $store, $user_id );
    	}

    	$item  = trim( (string) ( $slots['item'] ?? '' ) );
    	$price = max( 0, (int) ( $slots['price'] ?? 0 ) );

    	if ( '' === $item || $price <= 0 ) {
    		return array( 'text' => 'Tell me what you want to sell and the price — I’ll start the listing for you.', 'markup' => self::anchor_markup() );
    	}

    	$merchant_id = self::seller_merchant_id( $user_id, $store );
    	if ( null === $merchant_id ) {
    		return array(
    			'text'   => 'You need a merchant workspace before listing — open the Mini App to set it up.',
    			'markup' => self::app_button( self::mini_app_url( 'agent=seller' ) ),
    		);
    	}

    	$category    = trim( (string) ( $slots['category'] ?? '' ) );
    	$location    = trim( (string) ( $slots['location'] ?? '' ) );
    	$category_id = '' !== $category ? self::resolve_category_id( $category, $store ) : null;
    	$location_id = '' !== $location ? self::resolve_location_id( $location, $store ) : null;

    	if ( null === $category_id || null === $location_id ) {
    		return array(
    			'text'   => "Almost there — pick a category and city for \"{$item}\".\n\nCategories: " . self::name_list( 'tb_categories', 'slug', $store ) . "\nCities: " . self::name_list( 'tb_locations', 'name_key', $store ),
    			'markup' => self::anchor_markup(),
    		);
    	}

    	$product = \Trade\Catalog\Service::create_product( array(
    		'category_id'     => $category_id,
    		'canonical_name'  => $item,
    		'attributes_json' => self::default_attributes( $category_id, $store ),
    	), $merchant_id, $store );
    	$listing = \Trade\Listings\Service::create_listing( array(
    		'product_id'  => (int) ( $product['id'] ?? 0 ),
    		'price'       => $price,
    		'currency'    => 'ETB',
    		'location_id' => $location_id,
    	), $merchant_id, $store );

    	return array(
    		'text'   => "✅ Draft added: {$item} — {$price} ETB.\n\nOpen the Mini App to add photos, review, and publish.",
    		'markup' => self::app_button( self::mini_app_url( 'view=my_listings&listing_id=' . (int) ( $listing['id'] ?? 0 ) ) ),
    	);
    }

    /** Apply an AI-extracted profile change via Merchant\Service::update_profile. */
    private static function apply_profile_update( array $profile, Store $store, int $user_id ): array {
    	$merchant_id = self::seller_merchant_id( $user_id, $store );
    	if ( null === $merchant_id ) {
    		return array( 'text' => 'Set up your seller account first — choose the Seller role to register.', 'markup' => self::anchor_markup() );
    	}
    	$field = (string) ( $profile['field'] ?? '' );
    	$value = trim( (string) ( $profile['value'] ?? '' ) );
    	if ( in_array( $field, array( 'business_name', 'merchant_type' ), true ) && '' !== $value ) {
    		\Trade\Merchant\Service::update_profile( $merchant_id, array( $field => $value ), $store );
    		return array( 'text' => "✅ Profile updated — {$field}: {$value}.", 'markup' => self::anchor_markup() );
    	}
    	if ( 'location' === $field && '' !== $value ) {
    		$loc_id = self::resolve_location_id( $value, $store );
    		if ( null === $loc_id ) {
    			return array( 'text' => 'Pick a city: ' . self::name_list( 'tb_locations', 'name_key', $store ), 'markup' => self::anchor_markup() );
    		}
    		\Trade\Merchant\Service::update_profile( $merchant_id, array( 'location_id' => $loc_id ), $store );
    		return array( 'text' => '✅ Profile updated — city set.', 'markup' => self::anchor_markup() );
    	}
    	return array( 'text' => 'I can update your business name, type (product/service), or city. Try again.', 'markup' => self::anchor_markup() );
    }

    /**
     * First-time seller registration state machine (state 'seller_reg').
     * Steps: business_name → merchant_type → location → doc_id → doc_license (photos).
     */
    private static function seller_registration( int $chat_id, string $input, array $data, Store $store, int $user_id, Bot $bot, ?int $message_id ): void {
    	$step = (string) ( $data['reg_step'] ?? 'business_name' );

    	if ( 'business_name' === $step ) {
    		$name = trim( $input );
    		if ( '' === $name ) {
    			$bot->sendMessage( $chat_id, "What's your business name?" );
    			return;
    		}
    		$data['reg_name'] = $name;
    		$data['reg_step'] = 'merchant_type';
    		self::save_state( $store, $user_id, 'seller_reg', $data );
    		$bot->sendMessage( $chat_id, 'What do you sell?', array( 'reply_markup' => self::reg_type_markup() ) );
    		return;
    	}

    	if ( 'merchant_type' === $step ) {
    		if ( 'mtype:product' === $input ) {
    			$data['reg_type'] = 'product';
    		} elseif ( 'mtype:service' === $input ) {
    			$data['reg_type'] = 'service';
    		} else {
    			$bot->sendMessage( $chat_id, 'Choose a type:', array( 'reply_markup' => self::reg_type_markup() ) );
    			return;
    		}
    		$data['reg_step'] = 'location';
    		self::save_state( $store, $user_id, 'seller_reg', $data );
    		$bot->sendMessage( $chat_id, "Which city is your business in?" );
    		return;
    	}

    	if ( 'location' === $step ) {
    		$loc_id = self::resolve_location_id( $input, $store );
    		if ( null === $loc_id ) {
    			$bot->sendMessage( $chat_id, 'Pick a city: ' . self::name_list( 'tb_locations', 'name_key', $store ) );
    			return;
    		}
    		$wp_user_id    = \Trade\Identity\Service::find_identity( $user_id );
    		$data['wp_user_id'] = $wp_user_id;
    		$data['reg_location_id'] = $loc_id;
    		$data['reg_step'] = 'doc_id';
    		\Trade\Merchant\Service::create_profile( array(
    			'business_name' => (string) ( $data['reg_name'] ?? '' ),
    			'merchant_type' => (string) ( $data['reg_type'] ?? 'product' ),
    			'location_id'   => $loc_id,
    		), $wp_user_id, $store );
    		\Trade\Verification\Service::create_verification( $wp_user_id, $store ); // → pending + profile doc
    		self::save_state( $store, $user_id, 'seller_reg', $data );
    		$bot->sendMessage( $chat_id, "📸 Now send a clear photo of your national ID." );
    		return;
    	}

    	// doc_id / doc_license arrive as photos (handled in photo()); text here = nudge.
    	$bot->sendMessage( $chat_id, 'Please send a photo so we can verify your documents.' );
    }

    private static function reg_type_markup(): array {
    	return array( 'inline_keyboard' => array( array(
    		array( 'text' => '🏷 Product', 'callback_data' => 'mtype:product' ),
    		array( 'text' => '🛎 Service', 'callback_data' => 'mtype:service' ),
    	) ) );
    }

    /** Save photo bytes to uploads/trade-media/<key>; returns the storage key. */
    private static function persist_photo_bytes( string $bytes ): string {
    	$storage_key = bin2hex( random_bytes( 16 ) );
    	$dirs        = function_exists( 'wp_upload_dir' ) ? (array) wp_upload_dir() : array( 'basedir' => ABSPATH . 'wp-content/uploads' );
    	$target      = rtrim( (string) ( $dirs['basedir'] ?? ABSPATH . 'wp-content/uploads' ), '/' ) . '/trade-media';
    	if ( ! is_dir( $target ) ) {
    		@mkdir( $target, 0755, true );
    	}
    	@file_put_contents( $target . '/' . $storage_key, $bytes );
    	return $storage_key;
    }

    private static function app_button( string $url ): array {
    	return array( 'inline_keyboard' => array( array( array( 'text' => '🚀 Open Mini App', 'web_app' => array( 'url' => $url ) ) ) ) );
    }

    /** The seller's merchant id (via tb_identity → wp_user_id → tb_merchants), or null. */
    private static function seller_merchant_id( int $user_id, Store $store ): ?int {
    	$identity = $store->get_row( 'tb_identity', 'telegram_user_id = %s', array( (string) $user_id ) );
    	if ( ! is_array( $identity ) ) {
    		return null;
    	}
    	$wp_user_id = (int) ( $identity['wp_user_id'] ?? 0 );
    	foreach ( $store->get_rows( 'tb_merchants', '1=1' ) as $row ) {
    		if ( (int) ( $row['wp_user_id'] ?? 0 ) === $wp_user_id ) {
    			return (int) ( $row['id'] ?? 0 );
    		}
    	}
    	return null;
    }

    private static function resolve_category_id( string $name, Store $store ): ?int {
    	$name = self::norm_name( $name );
    	foreach ( $store->get_rows( 'tb_categories', '1=1' ) as $row ) {
    		$slug  = self::norm_name( (string) ( $row['slug'] ?? '' ) );
    		$nkey  = self::norm_name( (string) ( $row['name_key'] ?? '' ) );
    		if ( ( '' !== $slug && str_contains( $slug, $name ) ) || ( '' !== $nkey && str_contains( $nkey, $name ) ) ) {
    			return (int) ( $row['id'] ?? 0 );
    		}
    	}
    	return null;
    }

    private static function resolve_location_id( string $name, Store $store ): ?int {
    	$name = self::norm_name( $name );
    	foreach ( $store->get_rows( 'tb_locations', '1=1' ) as $row ) {
    		$hay = self::norm_name( (string) ( $row['name_key'] ?? '' ) );
    		if ( '' !== $hay && str_contains( $hay, $name ) ) {
    			return (int) ( $row['id'] ?? 0 );
    		}
    	}
    	return null;
    }

    /** Lowercase, strip spaces/underscores so 'addis ababa' matches name_key 'ADDIS_ABABA'. */
    private static function norm_name( string $s ): string {
    	$s = mb_strtolower( trim( $s ) );
    	return preg_replace( '/[^a-z0-9]/', '', $s ) ?? $s;
    }

    /**
     * Fill a category's attribute defs with defaults so the bot can create the product
     * without a full attribute form; the seller refines the details in the Mini App.
     * # ponytail: categories with zero defs can't be used this way (create_product
     *   requires a non-empty assoc attributes map) — rare; covered by the Mini App flow.
     */
    private static function default_attributes( int $category_id, Store $store ): array {
    	$attrs = array();
    	foreach ( \Trade\Catalog\Service::get_category_attributes( $category_id, $store ) as $def ) {
    		$key = (string) ( $def['key'] ?? '' );
    		if ( '' === $key ) {
    			continue;
    		}
    		$options = json_decode( (string) ( $def['options_json'] ?? '[]' ), true );
    		if ( is_array( $options ) && $options ) {
    			$attrs[ $key ] = $options[0];
    			continue;
    		}
    		$type = strtolower( (string) ( $def['data_type'] ?? 'string' ) );
    		if ( in_array( $type, array( 'int', 'integer', 'number' ), true ) ) {
    			$attrs[ $key ] = 0;
    		} elseif ( in_array( $type, array( 'bool', 'boolean' ), true ) ) {
    			$attrs[ $key ] = false;
    		} else {
    			$attrs[ $key ] = '';
    		}
    	}
    	return $attrs;
    }

    private static function name_list( string $table, string $col, Store $store ): string {
    	$names = array();
    	foreach ( array_slice( $store->get_rows( $table, '1=1' ), 0, 8 ) as $row ) {
    		$v = trim( (string) ( $row[ $col ] ?? '' ) );
    		if ( '' !== $v ) {
    			$names[] = $v;
    		}
    	}
    	return $names ? implode( ', ', $names ) : '—';
    }

    /**
     * A photo from a seller: download it and attach to their latest DRAFT listing.
     * Silently no-ops for non-sellers or when nothing can be downloaded.
     */
    public static function photo( int $chat_id, string $file_id, ?Store $store = null, ?Bot $bot = null, ?int $from_id = null ): void {
    	$store   = $store ?? Store::default();
    	$bot     = $bot ?? new Bot();
    	$user_id = $from_id ?? $chat_id;
    	if ( ! $bot->token_set() || '' === $file_id ) {
    		return;
    	}

    	// Registration documents (state seller_reg, steps doc_id / doc_license).
    	$row  = $store->get_row( 'tb_bot_chats', 'chat_id = %d', array( $user_id ) );
    	$data = is_array( $row ) ? ( json_decode( (string) ( $row['data'] ?? '' ), true ) ?? array() ) : array();
    	if ( 'seller_reg' === (string) ( $row['state'] ?? '' ) ) {
    		self::registration_photo( $chat_id, $file_id, $data, $store, $user_id, $bot );
    		return;
    	}

    	$merchant = self::seller_merchant_id( $user_id, $store );
    	if ( null === $merchant ) {
    		return;
    	}
    	$draft = null;
    	foreach ( $store->get_rows( 'tb_listings', '1=1' ) as $r ) {
    		if ( (int) ( $r['merchant_id'] ?? 0 ) === $merchant && 'DRAFT' === (string) ( $r['status'] ?? '' ) && ( null === $draft || (int) ( $r['id'] ?? 0 ) > (int) ( $draft['id'] ?? 0 ) ) ) {
    			$draft = $r;
    		}
    	}
    	if ( null === $draft ) {
    		$bot->sendMessage( $chat_id, "Finish adding your item first — tell me what you're selling and the price, then send the photo." );
    		return;
    	}

    	try {
    		$bytes = self::download_photo_bytes( $bot, $file_id );
    		if ( '' === $bytes ) {
    			return;
    		}
    		\Trade\Listings\Service::create_image( (int) $draft['id'], self::persist_photo_bytes( $bytes ), $merchant, $store );
    		$bot->sendMessage( $chat_id, '📸 Photo attached to your draft. Open the Mini App to review.' );
    	} catch ( \Throwable $e ) {
    		// # ponytail: swallow image failures — never 500 the webhook over a photo.
    	}
    }

    private static function download_photo_bytes( Bot $bot, string $file_id ): string {
    	$file      = $bot->getFile( $file_id );
    	$file_path = (string) ( $file['result']['file_path'] ?? '' );
    	return '' !== $file_path ? $bot->download_file( $file_path ) : '';
    }

    /** Store one registration document photo and advance the seller_reg flow. */
    private static function registration_photo( int $chat_id, string $file_id, array $data, Store $store, int $user_id, Bot $bot ): void {
    	$step = (string) ( $data['reg_step'] ?? '' );
    	if ( ! in_array( $step, array( 'doc_id', 'doc_license' ), true ) ) {
    		$bot->sendMessage( $chat_id, 'Send the document photo we asked for.' );
    		return;
    	}
    	try {
    		$bytes = self::download_photo_bytes( $bot, $file_id );
    		if ( '' === $bytes ) {
    			return;
    		}
    		$merchant_id = (int) ( $data['wp_user_id'] ?? 0 );
    		if ( $merchant_id <= 0 ) {
    			return;
    		}
    		$now      = gmdate( 'Y-m-d H:i:s' );
    		$doc_type = 'doc_id' === $step ? 'national_id' : 'trade_license';
    		$store->insert( 'tb_verification_documents', array(
    			'merchant_id'   => $merchant_id,
    			'document_type' => $doc_type,
    			'storage_key'   => self::persist_photo_bytes( $bytes ),
    			'status'        => 'pending',
    			'created_at'    => $now,
    			'updated_at'    => $now,
    		) );

    		if ( 'doc_id' === $step ) {
    			$data['reg_step'] = 'doc_license';
    			self::save_state( $store, $user_id, 'seller_reg', $data );
    			$bot->sendMessage( $chat_id, '✅ ID photo received. Now send a photo of your trade license.' );
    		} else {
    			unset( $data['reg_step'] );
    			$data['completed'] = true;
    			$data['role']      = 'seller';
    			self::save_state( $store, $user_id, 'completed', $data );
    			$bot->sendMessage(
    				$chat_id,
    				"✅ Registration submitted for admin review.\n\nYou can start adding listings now — open the Mini App to explore and manage.",
    				array( 'reply_markup' => self::app_button( self::mini_app_url( 'view=my_listings' ) ) )
    			);
    		}
    	} catch ( \Throwable $e ) {
    		// # ponytail: swallow photo failures — never 500 the webhook over a photo.
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

	private static function role_inline_markup(): array {
		return array(
			'inline_keyboard' => array(
				array(
					array( 'text' => '🛒 Buyer', 'callback_data' => 'role:buyer' ),
					array( 'text' => '🏪 Seller', 'callback_data' => 'role:seller' ),
				),
			),
		);
	}

	/** Anchored reply keyboard: change language / role anytime. */
	private static function anchor_markup(): array {
		return array(
			'keyboard'          => array(
				array( array( 'text' => '🌐 Language' ), array( 'text' => '🔄 Change role' ), array( 'text' => '🏠 Home' ) ),
				array( array( 'text' => '🚀 Open Mini App', 'web_app' => array( 'url' => self::mini_app_url() ) ) ),
			),
			'resize_keyboard'   => true,
			'one_time_keyboard' => false,
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