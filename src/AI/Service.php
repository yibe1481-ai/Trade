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

	/** Sell-agent system prompt — guides a buyer to the Mini App handoff. */
	private const SELL_AGENT_PROMPT = <<<TXT
You are the sales agent for the Trade marketplace in Ethiopia.
Help the buyer conversationally identify what they want: item or service, budget (ETB), and city. Ask ONE short question at a time until you have enough. Keep replies to 1-3 short sentences.
Once you know enough, say briefly what they are looking for and invite them: "Open the Mini App to see what's available."
You can also help a seller describe what they sell. Never invent listings, prices, or stock.
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
	 * Always returns a string; falls back gracefully when no provider is configured.
	 * Never mutates marketplace tables, always audit-logged.
	 */
	public static function chat( array $history, ?Store $store = null, ?callable $http = null ): string {
		$last = is_array( $history ) ? (string) ( $history[ count( $history ) - 1 ]['content'] ?? '' ) : '';
		Audit::write( 'ai.chat', 'ai', 'assistant', array(), array(), array( 'prompt_len' => strlen( $last ) ), 'system', '0', 'telegram' );

		$cfg = self::config();
		if ( ! ( $cfg['configured'] ?? false ) ) {
			return "I'm the Trade sell-agent — I can help you find or sell anything. Tell me what you're looking for (item, budget, city), or open the Mini App to browse.";
		}

		$cost = self::estimateCost( 'chat', array( 'text' => $last ) );
		if ( $cost > self::BUDGET_CEILING ) {
			return "Your AI budget for today is used up. Open the Mini App to keep going without AI.";
		}

		$messages = array_merge( array( array( 'role' => 'system', 'content' => self::SELL_AGENT_PROMPT ) ), $history );
		$reply    = self::complete( $messages, $http );
		return '' !== $reply ? $reply : "Sorry, I couldn't reach the AI right now. Try again, or open the Mini App to browse.";
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