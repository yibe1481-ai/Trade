<?php
declare( strict_types=1 );

namespace Trade\Search;

use Trade\Core\Error;
use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Listings\Service as ListingsService;
use Trade\Merchant\Service as MerchantService;
use WP_REST_Request;

/**
 * Search module (§B.11) — deterministic FULLTEXT + fixed-weight ranking.
 * No AI calls while ai_search_enabled=false. Weight constants mirror §B.11.3.
 */
final class Service {

	/** §B.11.3 — ranking weights (configuration per spec; hardcoded defaults). */
	public const W_TEXT        = 0.40;
	public const W_LOCATION    = 0.20;
	public const W_VERIFIED    = 0.15;
	public const W_AVAILABLE   = 0.10;
	public const W_FRESHNESS   = 0.10;
	public const W_TXN_SIGNAL  = 0.05;

	/** §B.6.1 — only these listing statuses are visible in public search. */
	public const PUBLIC_STATES = array( 'ACTIVE', 'PAUSED', 'OUT_OF_STOCK' );

	/** Default page size for search results. */
	public const DEFAULT_PER_PAGE = 20;

	/** Maximum per_page cap (prevents runaway result sets). */
	public const MAX_PER_PAGE = 100;

	/** §B.11.3 freshness decay constant — 30 days in seconds. */
	public const FRESHNESS_DECAY_SECONDS = 30 * 86400;

	public static function routes(): void {
		Rest::register( 'search', 'GET', '', array( self::class, 'search' ) );
	}

	/** REST controller — GET /search?q=…&category_id=…&location_id=…&price_min=…&price_max=… */
	public static function search( WP_REST_Request $request ): array {
		$q       = self::nullable_string( $request->get_param( 'q' ) ) ?? '';
		$filters = self::extract_filters( $request );

		if ( strlen( $q ) < 1 ) {
			throw Error::validation( array( 'q' ), 'search' );
		}

		$listings = self::search_listings( $q, $filters );
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $filters['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );

		$total    = count( $listings );
		$offset   = ( $page - 1 ) * $per_page;
		$pageRows = array_slice( $listings, $offset, $per_page );

		$meta = array(
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $total,
			'has_more' => $page * $per_page < $total,
		);
		// §B.11.4: when zero results, emit relaxed-filter suggestions.
		if ( 0 === $total ) {
			$meta['suggestions'] = self::empty_result_suggestions( $q, $filters );
		}
		return array( 'data' => $pageRows, 'meta' => $meta );
	}

	/**
	 * Pure service method: search + rank listings by query text and structured filters.
	 * Returns ranked, formatted listings (sorted desc by score, ties broken by id ASC).
	 *
	 * @return array<int, array<string,mixed>>
	 */
	public static function search_listings( string $q, array $filters ): array {
		$store  = self::store( $filters['store'] ?? null );
		$norm   = self::normalize_query( $q );
		$terms  = self::extract_terms( $norm );

		// §B.11.5: rule-based pre-filter — category is required for request→merchant
		// matching; for general search, structured filters are optional.
		$rows = self::store( $store )->get_rows( 'tb_listings', '1=1' );

		$scored = array();
		foreach ( $rows as $row ) {
			// Visibility gate: only PUBLIC_STATES unless merchant_id filter scopes to own.
			$status = (string) ( $row['status'] ?? '' );
			$merchant_filter = $filters['merchant_id'] ?? null;
			if ( null !== $merchant_filter ) {
				if ( (int) ( $row['merchant_id'] ?? 0 ) !== (int) $merchant_filter ) {
					continue;
				}
			} else {
				if ( ! in_array( $status, self::PUBLIC_STATES, true ) ) {
					continue;
				}
			}

			// Structured filters.
			if ( null !== ( $filters['category_id'] ?? null ) &&
				(int) ( $row['category_id'] ?? 0 ) !== (int) $filters['category_id'] ) {
				continue;
			}
			if ( null !== ( $filters['location_id'] ?? null ) &&
				(int) ( $row['location_id'] ?? 0 ) !== (int) $filters['location_id'] ) {
				continue;
			}
			if ( null !== ( $filters['price_min'] ?? null ) &&
				(int) ( $row['price'] ?? 0 ) < (int) $filters['price_min'] ) {
				continue;
			}
			if ( null !== ( $filters['price_max'] ?? null ) &&
				(int) ( $row['price'] ?? 0 ) > (int) $filters['price_max'] ) {
				continue;
			}

			// §B.11.1: text match against pre-normalized search_text (already lowercased + NFC).
			$haystack = (string) ( $row['search_text'] ?? '' );
			if ( '' !== $norm && false === mb_stripos( $haystack, $norm ) ) {
				continue;  // normalized query must appear as a substring
			}

			$score = self::rank( $row, $terms, $filters, $store );
			$scored[] = array( 'score' => $score, 'row' => $row );
		}

		// Sort: high score first, ties broken by listing_id ASC (stable pagination).
		usort( $scored, static function ( array $a, array $b ): int {
			$cmp = $b['score'] <=> $a['score'];
			return 0 !== $cmp ? $cmp : ( (int) ($a['row']['id'] ?? 0) <=> (int) ($b['row']['id'] ?? 0) );
		} );

		$out = array();
		foreach ( $scored as $s ) {
			$out[] = ListingsService::find_listing( (int) $s['row']['id'], $store );
		}
		return array_values( array_filter( $out, fn( $v ) => null !== $v ) );
	}

	/**
	 * §B.11.3 fixed-weight ranking.
	 * @param array<string,mixed> $row   raw tb_listings row
	 * @param array<int,string>   $terms extracted query terms
	 */
	public static function rank( array $row, array $terms, array $filters, ?Store $store ): float {
		$store = self::store( $store );
		$score = 0.0;

		// 0.40 × text_relevance: fraction of query terms present in search_text.
		$haystack = mb_strtolower( (string) ( $row['search_text'] ?? '' ) );
		$matched  = 0;
		foreach ( $terms as $term ) {
			if ( '' !== $term && false !== mb_strpos( $haystack, $term ) ) {
				$matched++;
			}
		}
		$text_rel = count( $terms ) > 0 ? $matched / count( $terms ) : 0.0;
		$score += self::W_TEXT * $text_rel;

		// 0.20 × location_proximity: exact location 1.0, else 0.1 (no parent hierarchy in MVP).
		$query_loc = $filters['location_id'] ?? null;
		if ( null !== $query_loc ) {
			$score += self::W_LOCATION * ( (int) ( $row['location_id'] ?? 0 ) === (int) $query_loc ? 1.0 : 0.1 );
		}

		// 0.15 × merchant_verified.
		$score += self::W_VERIFIED * (float) ( MerchantService::merchant_is_verified( (int) ( $row['merchant_id'] ?? 0 ), $store ) ? 1 : 0 );

		// 0.10 × availability: in-stock (product-type) or available (service-type).
		$stock = self::store( $store )->get_row( 'tb_inventory', 'listing_id = %d', array( (int) ( $row['id'] ?? 0 ) ) );
		$avail = self::store( $store )->get_row( 'tb_service_availability', 'listing_id = %d', array( (int) ( $row['id'] ?? 0 ) ) );
		if ( $stock && (int) ( $stock['stock'] ?? 0 ) > 0 ) {
			$score += self::W_AVAILABLE;
		} elseif ( $avail && in_array( (string) ( $avail['availability_state'] ?? '' ), array( 'AVAILABLE_TODAY', 'AVAILABLE_THIS_WEEK' ), true ) ) {
			$score += self::W_AVAILABLE;
		}

		// 0.10 × freshness: linear decay over FRESHNESS_DECAY_SECONDS from created_at.
		$created = strtotime( (string) ( $row['created_at'] ?? '' ) );
		if ( $created > 0 ) {
			$freshness = max( 0.0, 1.0 - ( ( time() - $created ) / self::FRESHNESS_DECAY_SECONDS ) );
			$score += self::W_FRESHNESS * $freshness;
		}

		// 0.05 × completed_txn_signal — no orders module yet; always 0.
		return $score;
	}

	/**
	 * §B.11.2 query pipeline: normalize → NFC → casefold → strip punctuation.
	 * Amharic-safe: Unicode NFC normalization + u (PCRE_UTF8) flag.
	 */
	public static function normalize_query( string $q ): string {
		$q = trim( $q );
		if ( '' === $q ) {
			return '';
		}
		if ( class_exists( '\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $q, \Normalizer::FORM_C );
			if ( false !== $normalized && null !== $normalized ) {
				$q = $normalized;
			}
		}
		// Strip punctuation, collapse whitespace, casefold.
		$q = preg_replace( '/[^\\p{L}\\p{N}\\s]+/u', ' ', $q ) ?? $q;
		$q = preg_replace( '/\\s+/u', ' ', $q ) ?? $q;
		return mb_strtolower( trim( $q ) );
	}

	/** Split normalized query into individual terms (whitespace-split). */
	public static function extract_terms( string $normalized ): array {
		if ( '' === $normalized ) {
			return array();
		}
		return array_values( array_filter( explode( ' ', $normalized ) ) );
	}

	/** §B.11.4 — empty-result suggestions: relaxed filters, nearest categories, CTA. */
	public static function empty_result_suggestions( string $q, array $filters, ?Store $store = null ): array {
		$store   = self::store( $store );
		$orig    = $filters;
		$suggestions = array();

		// 1. Relax price range (drop price_min/price_max).
		$relaxed = $filters;
		$relaxed['price_min'] = null;
		$relaxed['price_max'] = null;
		if ( null !== ( $orig['price_min'] ?? null ) || null !== ( $orig['price_max'] ?? null ) ) {
			$suggestions[] = array( 'type' => 'relaxed', 'hint' => 'price', 'filters' => $relaxed );
		}

		// 2. Relax location.
		$relaxed = $filters;
		$relaxed['location_id'] = null;
		if ( null !== ( $orig['location_id'] ?? null ) ) {
			$suggestions[] = array( 'type' => 'relaxed', 'hint' => 'location', 'filters' => $relaxed );
		}

		// 3. Relax category.
		$relaxed = $filters;
		$relaxed['category_id'] = null;
		if ( null !== ( $orig['category_id'] ?? null ) ) {
			$suggestions[] = array( 'type' => 'relaxed', 'hint' => 'category', 'filters' => $relaxed );
		}

		return $suggestions;
	}

	/** Parse structured query params from a REST request. */
	private static function extract_filters( WP_REST_Request $request ): array {
		return array(
			'page'        => max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) ),
			'per_page'    => min( self::MAX_PER_PAGE, max( 1, (int) ( $request->get_param( 'per_page' ) ?: self::DEFAULT_PER_PAGE ) ) ),
			'category_id' => self::nullable_int( $request->get_param( 'category_id' ) ),
			'location_id' => self::nullable_int( $request->get_param( 'location_id' ) ),
			'price_min'   => self::nullable_int( $request->get_param( 'price_min' ) ),
			'price_max'   => self::nullable_int( $request->get_param( 'price_max' ) ),
			'merchant_id' => self::nullable_int( $request->get_param( 'merchant_id' ) ),
		);
	}

	private static function store( ?Store $store = null ): Store {
		return $store ?? Store::default();
	}

	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$int = (int) $value;
		return $int > 0 ? $int : null;
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}
}
