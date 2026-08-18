<?php
declare( strict_types=1 );

namespace Trade\Core;

/**
 * REST base (§B.5). One registration helper guarantees every route goes through the same
 * pipeline: request-id, envelope, error mapping, and §B.8 idempotency for mutating methods.
 */
final class Rest {

	public const NS = 'trade/v1';

	public static function register_routes(): void {
		// ponytail: demo endpoints prove the Phase 0 exit criterion; flag-gated so no debug
		// surface ships in the real namespace. Remove when Phase 2 lands real listing endpoints.
		if ( Flags::get( 'trade_dev_routes_enabled' ) ) {
			self::register( 'system/status', 'GET', 'manage_options', array( Demo::class, 'status' ) );
			self::register( 'system/echo', 'POST', 'manage_options', array( Demo::class, 'echo' ) );
		}
	}

	/**
	 * Register a route with the full pipeline.
	 * $handler receives WP_REST_Request and returns array{data:array,meta?:array}, or throws Error::Exception.
	 */
	public static function register( string $path, string $method, string $capability, callable $handler ): void {
		register_rest_route( self::NS, '/' . $path, array(
			// WP 7 treats any truthy return (incl. a WP_REST_Response) as "allowed"; return
			// true and enforce capability inside dispatch so denials get our §B.10 envelope.
			'methods'             => $method,
			'permission_callback' => static fn () => true,
			'callback'            => static function ( \WP_REST_Request $request ) use ( $handler, $method, $capability ) {
				return self::dispatch( $request, $method, $handler, $capability );
			},
		) );
	}

	private static function dispatch( \WP_REST_Request $request, string $method, callable $handler, string $capability ): \WP_REST_Response {
		$request_id = Request::id();
		$mutating   = in_array( $method, array( 'POST', 'PATCH', 'DELETE' ), true );

		// §B.3.2: resolve a Bearer session before the capability guard. '' capability = public
		// endpoint (auth/session, webhook) — no identity wiring, no check.
		if ( '' !== $capability ) {
			$auth = \Trade\Identity\Session::resolve( (string) $request->get_header( 'Authorization' ) );
			if ( $auth['error'] ) {
				return self::reply( Error::envelope( $auth['error'], 'identity', Error::text( $auth['error'] ) ), $request_id, Error::status( $auth['error'] ) );
			}
			if ( $auth['user_id'] > 0 ) {
				wp_set_current_user( $auth['user_id'] );
			}

			if ( ! current_user_can( $capability ) ) {
				return self::reply( Error::envelope( 'FORBIDDEN_CAPABILITY', 'core', Error::text( 'FORBIDDEN_CAPABILITY' ) ), $request_id, Error::status( 'FORBIDDEN_CAPABILITY' ) );
			}
		}

		$user_id  = get_current_user_id();
		$idem_key = $request->get_header( 'Idempotency-Key' );

		// §B.8: every mutating endpoint accepts Idempotency-Key; header absent → pass through.
		$phase = null;
		if ( $mutating && $idem_key ) {
			Idempotency::prune();
			$hash  = hash( 'sha256', (string) $request->get_body() );
			$phase = Idempotency::capture( $idem_key, (int) $user_id, $request->get_route(), $hash );

			if ( 'different_body' === $phase ) {
				return self::reply( Error::envelope( 'IDEMPOTENCY_KEY_REUSED', 'core', Error::text( 'IDEMPOTENCY_KEY_REUSED' ) ), $request_id, Error::status( 'IDEMPOTENCY_KEY_REUSED' ) );
			}
			if ( 'in_progress' === $phase ) {
				return self::reply( Error::envelope( 'REQUEST_IN_PROGRESS', 'core', Error::text( 'REQUEST_IN_PROGRESS' ) ), $request_id, Error::status( 'REQUEST_IN_PROGRESS' ) );
			}
			if ( 'replay' === $phase ) {
				$stored = Idempotency::stored( $idem_key, (int) $user_id, $request->get_route() );
				if ( $stored ) {
					return self::reply( $stored['body'], $request_id, $stored['status'] );
				}
			}
		}

		$body        = array();
		$status      = 200;
		$retry_after = null;
		try {
			$out   = $handler( $request );
			$body  = Error::ok( $out['data'] ?? $out, $out['meta'] ?? array() );
		} catch ( Exception $e ) {
			$body   = Error::envelope( $e->error_code(), $e->module, $e->getMessage(), $e->context, $e->retryable );
			$status = Error::status( $e->error_code() );
			if ( isset( $e->context['retry_after'] ) && is_numeric( $e->context['retry_after'] ) ) {
				$retry_after = (int) $e->context['retry_after'];
			}
		} catch ( \Throwable $e ) {
			$context = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? array( 'exception' => (string) $e ) : array();
			$body    = Error::envelope( 'INTERNAL_ERROR', 'core', Error::text( 'INTERNAL_ERROR' ), $context );
			$status  = 500;
		}

		if ( 'new' === $phase ) {
			Idempotency::release( $idem_key, (int) $user_id, $request->get_route(), $body, $status );
		}

		return self::reply( $body, $request_id, $status, $retry_after );
	}

	private static function reply( array $body, string $request_id, int $status = 200, ?int $retry_after = null ): \WP_REST_Response {
		$response = new \WP_REST_Response( $body, $status );
		$response->header( 'X-Request-ID', $request_id );
		if ( null !== $retry_after ) {
			$response->header( 'Retry-After', (string) $retry_after );
		}
		return $response;
	}

	/** Success envelope (§B.5). */
	public static function ok( array $data, array $meta = array() ): array {
		return array( 'success' => true, 'data' => $data, 'meta' => $meta );
	}

	/** Paginated collection envelope. */
	public static function paginated( array $rows, int $total, int $page, int $per_page ): array {
		return array( 'success' => true, 'data' => $rows, 'meta' => array(
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $total,
			'has_more' => $page * $per_page < $total,
		) );
	}
}