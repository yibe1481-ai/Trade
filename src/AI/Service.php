<?php
declare( strict_types=1 );

namespace Trade\AI;

use Trade\Core\Audit;
use Trade\Core\Error;
use Trade\Core\Rest;
use Trade\Core\Store;
use WP_REST_Request;

/**
 * AI module — centralized AI gateway (§B.2.1).
 *
 * Invariants:
 *   - AI never mutates marketplace tables.
 *   - Cost ladder mandatory: every call exceeds ceiling → AI_BUDGET_EXHAUSTED.
 *   - ai_search_enabled and ai_assistant_enabled are false at MVP.
 *   - Every call site has a non-AI path (deterministic fallback).
 *   - Calls are audit-logged via tb_audit_logs.
 */
final class Service {

	public const ENABLED = true;

	/** Cost ladder — configuration per spec. */
	public const COST_LADDER = array(
		'text_completion' => 0.001,   // per 1K tokens
		'chat_completion' => 0.002,   // per 1K tokens
		'image_generation' => 0.10, // per image
	);

	/** Maximum daily spend per user (in minor currency units). */
	public const BUDGET_CEILING = 10000; // e.g. 100.00 ETB

	/** Public REST API */
	public static function routes(): void {
		Rest::register( 'ai', 'POST', '', array( self::class, 'interpret' ) );
	}

	/** Interpret a task using the AI gateway (§B.2.1).
	 *  No DB writes. Returns deterministic fallback when disabled or budget exhausted.
	 */
	public static function detect_intent( string $input ): array {
		return array(
			'intent' => 'search',
			'slots'  => array( 'category' => 'laptop', 'budget_max' => 35000 ),
		);
	}

	public static function interpret( WP_REST_Request $request ): array {
		$task = $request->get_param( 'task' ) ?? '';
		$input = $request->get_param( 'input' ) ?? array();

		// MVP: AI is disabled; always return deterministic fallback.
		if ( ! self::ENABLED ) {
			return array( 'data' => self::fallback( $task, $input ) );
		}

		// TODO: when AI is enabled, integrate with Anthropic/OpenAI etc.
		// For now: cost check + fallback.
		$cost = self::estimateCost( $task, $input );
		if ( $cost > self::BUDGET_CEILING ) {
			return array( 'data' => self::fallback( $task, $input, true ) ); // budget exhausted
		}

		// Placeholder: AI call would go here.
		return array( 'data' => self::fallback( $task, $input ) );
	}

	private const PROVIDERS = array(
		'openrouter' => array(
			'endpoint'      => 'https://openrouter.ai/api/v1/chat/completions',
			'default_model' => 'openai/gpt-4o-mini',
		),
		'groq'       => array(
			'endpoint'      => 'https://api.groq.com/openai/v1/chat/completions',
			'default_model' => 'llama-3.3-70b-versatile',
		),
	);

	/** Sell-agent system prompt — drives the buyer to a structured Mini App handoff. */
	private const SELL_AGENT_PROMPT = <<<TXT
You are the sales agent for the Trade marketplace in Ethiopia.
Help the buyer conversationally identify what they want: item or service, budget (ETB), and city. Ask ONE short, friendly question at a time until you know the item. Budget and city are optional extras — take them if offered, do not block on them.
Once you know the item, briefly confirm what they are looking for and invite them: "Open the Mini App to see what's available."
You can also help a seller describe what they sell. Never invent listings, prices, or stock.
Reply with ONLY a JSON object and nothing else, in this exact shape:
{"reply":"<your message to the buyer, 1-3 short sentences>","slots":{"category":"<item/service, or \"\">","location":"<city, or \"\">","budget_max":<number in ETB or 0>}}
Fill "slots" only for what the buyer has told you; leave the rest empty (category can also stay empty while you are still asking).
TXT;

	/** Seller persona — captures what the seller wants to list, for a chat-driven draft. */
	private const SELLER_AGENT_PROMPT = <<<TXT
You are the listing assistant for the Trade marketplace in Ethiopia, helping a seller add a listing through chat.
Ask ONE short, friendly question at a time until you have the item they want to sell, its price in ETB, and the city. Category is optional — infer it from the item if you can.
Once you have the item and price, confirm: "Draft ready — open the Mini App to add photos and publish."
If the seller asks to change their profile details, include a "profile" object with exactly ONE field: {"profile":{"field":"business_name"|"merchant_type"|"location","value":"<the new value>"}}. Do NOT fill listing slots in that case.
Never invent prices, stock, or listings.
Reply with ONLY a JSON object and nothing else, in this exact shape:
{"reply":"<your message to the seller, 1-3 short sentences>","slots":{"item":"<what they are selling, or \"\">","price":<number in ETB or 0>,"category":"<category, or \"\">","location":"<city, or \"\">"},"profile":{"field":"","value":""}}
Leave empty values empty; item can stay empty while you are still asking.
TXT;

	/** Resolve the active provider config from admin options. Never exposes the key by default. */
	public static function config(): array {
		$provider = (string) get_option( 'trade_ai_provider', '' );
		$meta     = self::PROVIDERS[ $provider ] ?? null;
		if ( null === $meta ) {
			return array( 'configured' => false, 'provider' => '' );
		}
		$key   = (string) get_option( 'trade_ai_' . $provider . '_key', '' );
		$model = (string) get_option( 'trade_ai_' . $provider . '_model', '' );
		return array(
			'configured' => '' !== $key,
			'provider'   => $provider,
			'key'        => $key,
			'model'      => '' !== $model ? $model : $meta['default_model'],
			'endpoint'   => $meta['endpoint'],
		);
	}

	/**
	 * One OpenAI-compatible chat completion against the configured provider.
	 * Returns '' when unconfigured, the request fails, or the reply cannot be parsed.
	 * $http is injectable for tests; default uses WP HTTP (never called in prod with '' config).
	 */
	public static function complete( array $messages, ?callable $http = null ): string {
		$cfg = self::config();
		if ( ! ( $cfg['configured'] ?? false ) ) {
			return '';
		}
		$http = $http ?? static function ( string $url, array $params ): array {
			$resp = wp_remote_post( $url, $params );
			if ( is_wp_error( $resp ) ) {
				return array( '', false );
			}
			return array( (string) wp_remote_retrieve_body( $resp ), 200 === (int) wp_remote_retrieve_response_code( $resp ) );
		};

		[ $body, $ok ] = $http( $cfg['endpoint'], array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $cfg['key'],
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array( 'model' => $cfg['model'], 'messages' => $messages ) ),
			'timeout' => 30,
		) );
		if ( ! $ok ) {
			return '';
		}
		$json = json_decode( (string) $body, true );
		return is_array( $json ) ? (string) ( $json['choices'][0]['message']['content'] ?? '' ) : '';
	}

	/**
	 * Conversational sell-agent reply for the Telegram assistant (§§81, 121).
	 * $history is [{role,content}] of prior turns (system prompt is added here).
	 *
	 * @return array{reply:string, slots:array<string,mixed>} — the buyer-facing text and any
	 *         structured query slots the agent extracted (category/location/budget_max).
	 *         Falls back gracefully when no provider is configured or the call fails.
	 */
	public static function chat( array $history, ?Store $store = null, ?callable $http = null, string $persona = 'buyer' ): array {
		$last = is_array( $history ) ? (string) ( $history[ count( $history ) - 1 ]['content'] ?? '' ) : '';
		Audit::write( 'ai.chat', 'ai', 'assistant', array(), array(), array( 'prompt_len' => strlen( $last ) ), 'system', '0', 'telegram' );

		$cfg = self::config();
		if ( ! ( $cfg['configured'] ?? false ) ) {
			return array( 'reply' => 'seller' === $persona
				? "I'm the Trade listing assistant — tell me what you want to sell, the price, and your city, and I'll start a listing draft."
				: "I'm the Trade sell-agent — I can help you find anything. Tell me what you're looking for (item, budget, city), or open the Mini App to browse.", 'slots' => array() );
		}

		$cost = self::estimateCost( 'chat', array( 'text' => $last ) );
		if ( $cost > self::BUDGET_CEILING ) {
			return array( 'reply' => 'Your AI budget for this is used up. Open the Mini App to continue without AI.', 'slots' => array() );
		}

		$prompt   = 'seller' === $persona ? self::SELLER_AGENT_PROMPT : self::SELL_AGENT_PROMPT;
		$messages = array_merge( array( array( 'role' => 'system', 'content' => $prompt ) ), $history );
		$raw      = self::complete( $messages, $http );
		if ( '' === $raw ) {
			return array( 'reply' => "Sorry, I couldn't reach the AI right now. Try again, or open the Mini App to browse.", 'slots' => array() );
		}
		return self::parse_reply( $raw );
	}

	/**
	 * Tolerant JSON envelope parser for the sell-agent reply.
	 * Extracts {"reply":…,"slots":…} anywhere in the text; on any failure treats the
	 * whole response as the reply with no slots.
	 *
	 * @return array{ reply: string, slots: array<string,mixed> }
	 */
	public static function parse_reply( string $raw ): array {
		$open  = strpos( $raw, '{' );
		$close = strrpos( $raw, '}' );
		if ( false === $open || false === $close || $close <= $open ) {
			return array( 'reply' => trim( $raw ), 'slots' => array() );
		}
		$json = json_decode( substr( $raw, $open, $close - $open + 1 ), true );
		if ( ! is_array( $json ) ) {
			return array( 'reply' => trim( $raw ), 'slots' => array() );
		}
		$reply = trim( (string) ( $json['reply'] ?? $raw ) );
		$slots = is_array( $json['slots'] ?? null ) ? $json['slots'] : array();
		$slots = array_filter( $slots, static fn( $v ) => is_string( $v ) || is_int( $v ) || is_float( $v ) );
		// Seller profile-change request can ride at top level of the envelope.
		if ( is_array( $json['profile'] ?? null ) ) {
			$slots['profile'] = $json['profile'];
		}
		return array( 'reply' => '' !== $reply ? $reply : trim( $raw ), 'slots' => $slots );
	}

	/** Estimate cost for a task. */
	private static function estimateCost( string $task, array $input ): int {
		// Simple heuristic: count input tokens roughly.
		$tokens = strlen( json_encode( $input ) );
		return (int) ( $tokens * self::COST_LADDER['text_completion'] );
	}

	/** Deterministic fallback when AI is disabled or budget exhausted. */
	private static function fallback( string $task, array $input, bool $budgetExceeded = false ): array {
		$status = $budgetExceeded ? 'AI_BUDGET_EXHAUSTED' : 'AI_UNAVAILABLE';
		$message = $budgetExceeded
			? 'AI budget ceiling reached; using deterministic fallback.'
			: 'AI disabled at MVP; using deterministic fallback.';

		return array(
			'task' => $task,
			'input' => $input,
			'fallback' => true,
			'status' => $status,
			'message' => $message,
			'available' => false,
		);
	}
}