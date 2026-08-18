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

	public const ENABLED = false;

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

	/**
	 * Conversational reply for the Telegram assistant (§§81, 121).
	 * Bootstraps on the same cost-gate as interpret(): while AI is off, returns a
	 * deterministic, helpful answer; the moment AI is enabled this is the call site.
	 * Never mutates marketplace tables, always audit-logged.
	 *
	 * # ponytail: provider call is the one integration point. When wiring the real
	 *   provider, swap the return below for the LLM completion and keep the gate.
	 */
	public static function chat( string $text, ?Store $store = null ): string {
		Audit::write( 'ai.chat', 'ai', 'assistant', array(), array(), array( 'prompt_len' => strlen( $text ) ), 'system', '0', 'telegram' );

		if ( ! self::ENABLED || ! ( new \Trade\Core\Flags() )->get( 'ai_assistant_enabled', false ) ) {
			return "AI assistant is off at MVP. I can still help you navigate — use /menu or /help.";
		}

		$cost = self::estimateCost( 'chat', array( 'text' => $text ) );
		if ( $cost > self::BUDGET_CEILING ) {
			return "Your AI budget for today is used up. Use /menu to keep going without AI.";
		}

		// Todo: real provider completion lives here.
		return sprintf( 'You said: %s', $text );
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