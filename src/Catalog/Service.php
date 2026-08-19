<?php
declare( strict_types=1 );

namespace Trade\Catalog;

use Trade\Core\Audit;
use Trade\Core\Error;
use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Localization\Lang;
use WP_REST_Request;

/**
 * Catalog module — seed-backed locations, categories, dynamic attributes, and products.
 * Public GET routes are browsable; product creation is session-authenticated.
 */
final class Service {

	public static function routes(): void {
		Rest::register( 'categories', 'GET', '', array( self::class, 'categories' ) );
		Rest::register( 'categories/(?P<id>[0-9]+)/attributes', 'GET', '', array( self::class, 'category_attributes' ) );
		Rest::register( 'products', 'GET', '', array( self::class, 'products' ) );
		Rest::register( 'products', 'POST', 'tb_session', array( self::class, 'product_create' ) );
		Rest::register( 'locations', 'GET', '', array( self::class, 'locations' ) );
	}

	/** GET catalog/locations?parent_id=N — children of a location (country → region → city). */
	public static function locations( WP_REST_Request $request ): array {
		$parent = (int) ( $request->get_param( 'parent_id' ) ?? 0 );
		return array( 'data' => self::location_children( $parent ) );
	}

	/** Children of a location as {id, parent_id, level, name}; roots returned when $parent_id <= 0. */
	public static function location_children( int $parent_id, ?Store $store = null ): array {
		$store = self::store( $store );
		$where = $parent_id > 0 ? 'parent_id = %d' : 'level = 0';
		$args  = $parent_id > 0 ? array( $parent_id ) : array();
		$out   = array();
		$seen  = array();
		foreach ( $store->get_rows( 'tb_locations', $where, $args ) as $row ) {
			$name = self::location_name( (string) ( $row['name_key'] ?? '' ) );
			if ( isset( $seen[ $name ] ) ) {
				continue; // duplicate seed rows share a name_key
			}
			$seen[ $name ] = true;
			$out[]         = array(
				'id'        => (int) ( $row['id'] ?? 0 ),
				'parent_id' => isset( $row['parent_id'] ) && null !== $row['parent_id'] ? (int) $row['parent_id'] : null,
				'level'     => (int) ( $row['level'] ?? 0 ),
				'name'      => $name,
			);
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
		return $out;
	}

	/** Prettify a name_key: 'LOCATION_ADDIS_ABABA' → 'Addis Ababa' (translation rows not seeded). */
	private static function location_name( string $name_key ): string {
		$raw = \Trade\Localization\Lang::text( $name_key, 'en' );
		if ( $raw === $name_key ) {
			$raw = preg_replace( '/^LOCATION_/', '', $name_key ) ?? $name_key;
		}
		$words  = array_filter( preg_split( '/[\s_]+/', strtolower( $raw ) ) ?: array(), static fn( $w ) => '' !== $w );
		$pretty = implode( ' ', array_map( static fn( $w ) => strtoupper( $w[0] ) . substr( $w, 1 ), $words ) );
		return '' !== $pretty ? $pretty : $raw;
	}

	/** Phase-4 locations are catalog-owned and seed-backed. */
	public static function location_exists( int $location_id, ?Store $store = null ): bool {
		if ( $location_id <= 0 ) {
			return false;
		}
		return null !== self::store( $store )->get_row( 'tb_locations', 'id = %d', array( $location_id ) );
	}

	public static function get_category( int $category_id, ?Store $store = null ): ?array {
		if ( $category_id <= 0 ) {
			return null;
		}
		return self::store( $store )->get_row( 'tb_categories', 'id = %d', array( $category_id ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_category_attributes( int $category_id, ?Store $store = null ): array {
		$rows = self::store( $store )->get_rows( 'tb_category_attributes', 'category_id = %d', array( $category_id ) );
		usort( $rows, static function ( array $a, array $b ): int {
			return ( (int) ( $a['sort'] ?? 0 ) <=> (int) ( $b['sort'] ?? 0 ) ) ?: strcmp( (string) ( $a['key'] ?? '' ), (string) ( $b['key'] ?? '' ) );
		} );
		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_product_variants( int $product_id, ?Store $store = null ): array {
		$rows = self::store( $store )->get_rows( 'tb_product_variants', 'product_id = %d', array( $product_id ) );
		usort( $rows, static function ( array $a, array $b ): int {
			return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
		} );
		return $rows;
	}

	public static function categories( WP_REST_Request $request ): array {
		$filters = self::collection_filters( $request );
		$rows    = self::list_categories_rows( $filters );
		return self::paginated( $rows, $filters['page'], $filters['per_page'] );
	}

	public static function category_attributes( WP_REST_Request $request ): array {
		$category_id = (int) $request->get_param( 'id' );
		if ( $category_id <= 0 ) {
			throw Error::validation( array( 'id' ), 'catalog' );
		}

		$category = self::get_category( $category_id );
		if ( ! $category || 0 === (int) ( $category['active'] ?? 0 ) ) {
			Error::throw_( 'CATEGORY_NOT_FOUND', 'catalog', Error::text( 'CATEGORY_NOT_FOUND' ), array( 'category_id' => $category_id ) );
		}

		$filters = self::collection_filters( $request );
		$rows    = self::map_category_attributes( self::get_category_attributes( $category_id ) );
		return self::paginated( $rows, $filters['page'], $filters['per_page'] );
	}

	public static function products( WP_REST_Request $request ): array {
		$filters = self::collection_filters( $request );
		$rows    = self::list_products_rows( $filters );
		return self::paginated( $rows, $filters['page'], $filters['per_page'] );
	}

	public static function product_create( WP_REST_Request $request ): array {
		$params = $request->get_json_params() ?: array();
		return array( 'data' => self::create_product( is_array( $params ) ? $params : array(), get_current_user_id() ) );
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function create_product( array $payload, int $created_by, ?Store $store = null ): array {
		$store = self::store( $store );
		$errors = array();

		$category_id = isset( $payload['category_id'] ) ? (int) $payload['category_id'] : 0;
		if ( $category_id <= 0 ) {
			$errors[] = 'category_id';
		}

		$canonical_name = isset( $payload['canonical_name'] ) ? self::normalize_text( (string) $payload['canonical_name'] ) : '';
		if ( '' === $canonical_name || mb_strlen( $canonical_name ) > 255 ) {
			$errors[] = 'canonical_name';
		}

		$attributes = self::expect_assoc_array( $payload['attributes_json'] ?? null );
		if ( null === $attributes ) {
			$errors[] = 'attributes_json';
			$attributes = array();
		}

		$category = self::get_category( $category_id, $store );
		if ( $category_id > 0 && ( ! $category || 0 === (int) ( $category['active'] ?? 0 ) ) ) {
			Error::throw_( 'CATEGORY_NOT_FOUND', 'catalog', Error::text( 'CATEGORY_NOT_FOUND' ), array( 'category_id' => $category_id ) );
		}

		$defs = $category_id > 0 ? self::get_category_attributes( $category_id, $store ) : array();
		$def_map = array();
		foreach ( $defs as $def ) {
			$def_map[ (string) $def['key'] ] = $def;
		}

		if ( $category_id > 0 ) {
			foreach ( $defs as $def ) {
				$key = (string) $def['key'];
				if ( (int) ( $def['required'] ?? 0 ) === 1 && ! array_key_exists( $key, $attributes ) ) {
					$errors[] = 'attributes_json.' . $key;
				}
			}

			foreach ( $attributes as $key => $value ) {
				$key = (string) $key;
				if ( ! isset( $def_map[ $key ] ) ) {
					$errors[] = 'attributes_json.' . $key;
					continue;
				}
				$def = $def_map[ $key ];
				if ( ! self::matches_type( $value, (string) $def['data_type'] ) ) {
					$errors[] = 'attributes_json.' . $key;
					continue;
				}
				$options = self::json_decode_array( (string) ( $def['options_json'] ?? '[]' ) );
				if ( $options && ! in_array( $value, $options, true ) ) {
					$errors[] = 'attributes_json.' . $key;
				}
			}
		}

		$variants = array();
		if ( array_key_exists( 'variants', $payload ) ) {
			if ( ! is_array( $payload['variants'] ) ) {
				$errors[] = 'variants';
			} else {
				$seen = array();
				foreach ( $payload['variants'] as $index => $variant ) {
					if ( ! is_array( $variant ) ) {
						$errors[] = 'variants.' . $index;
						continue;
					}
					$key = self::normalize_variant_key( (string) ( $variant['variant_key'] ?? '' ) );
					$variant_attrs = self::expect_assoc_array( $variant['attributes_json'] ?? null );
					if ( '' === $key ) {
						$errors[] = 'variants.' . $index . '.variant_key';
						continue;
					}
					if ( isset( $seen[ $key ] ) ) {
						$errors[] = 'variants.' . $index . '.variant_key';
						continue;
					}
					$seen[ $key ] = true;
					if ( null === $variant_attrs ) {
						$errors[] = 'variants.' . $index . '.attributes_json';
						continue;
					}
					$variants[] = array(
						'variant_key'     => $key,
						'attributes_json' => $variant_attrs,
					);
				}
			}
		}

		if ( $errors ) {
			throw Error::validation( array_values( array_unique( $errors ) ), 'catalog' );
		}

		$store->insert( 'tb_products', array(
			'category_id'     => $category_id,
			'canonical_name'  => $canonical_name,
			'attributes_json' => wp_json_encode( $attributes, JSON_UNESCAPED_UNICODE ),
			'created_by'      => $created_by,
			'status'          => 'active',
		) );
		$product_id = $store->last_insert_id();

		foreach ( $variants as $variant ) {
			$store->insert( 'tb_product_variants', array(
				'product_id'      => $product_id,
				'variant_key'     => $variant['variant_key'],
				'attributes_json' => wp_json_encode( $variant['attributes_json'], JSON_UNESCAPED_UNICODE ),
			) );
		}

		$product = self::format_product( array(
			'id'              => $product_id,
			'category_id'     => $category_id,
			'canonical_name'  => $canonical_name,
			'attributes_json' => wp_json_encode( $attributes, JSON_UNESCAPED_UNICODE ),
			'created_by'      => $created_by,
			'status'          => 'active',
		), $store );

		Audit::write(
			'catalog.product.create',
			'product',
			(string) $product_id,
			array(),
			$product,
			array( 'variant_count' => count( $variants ) ),
			'user',
			(string) $created_by,
			'rest'
		);

		return $product;
	}

	/** @return array<int, array<string, mixed>> */
	private static function list_categories_rows( array $filters ): array {
		$rows = self::store()->get_rows( 'tb_categories', '1=1' );
		$rows = array_values( array_filter( $rows, static function ( array $row ) use ( $filters ): bool {
			if ( null !== $filters['type'] && (string) $row['type'] !== $filters['type'] ) {
				return false;
			}
			if ( null !== $filters['active'] && (int) ( $row['active'] ?? 0 ) !== (int) $filters['active'] ) {
				return false;
			}
			if ( array_key_exists( 'parent_id', $filters ) ) {
				$parent = $filters['parent_id'];
				if ( null === $parent ) {
					if ( null !== ( $row['parent_id'] ?? null ) && '' !== (string) $row['parent_id'] ) {
						return false;
					}
				} elseif ( (int) ( $row['parent_id'] ?? 0 ) !== (int) $parent ) {
					return false;
				}
			}
			return true;
		} ) );

		usort( $rows, static function ( array $a, array $b ): int {
			return ( (int) ( $a['parent_id'] ?? 0 ) <=> (int) ( $b['parent_id'] ?? 0 ) ) ?: ( strcmp( (string) ( $a['slug'] ?? '' ), (string) ( $b['slug'] ?? '' ) ) ) ?: ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
		} );

		return self::map_categories( $rows );
	}

	/** @return array<int, array<string, mixed>> */
	private static function list_products_rows( array $filters ): array {
		$rows = self::store()->get_rows( 'tb_products', '1=1' );
		$q    = '' === $filters['q'] ? '' : mb_strtolower( self::normalize_text( $filters['q'] ) );
		$rows  = array_values( array_filter( $rows, static function ( array $row ) use ( $filters, $q ): bool {
			if ( null !== $filters['category_id'] && (int) ( $row['category_id'] ?? 0 ) !== (int) $filters['category_id'] ) {
				return false;
			}
			if ( null !== $filters['status'] && (string) $row['status'] !== $filters['status'] ) {
				return false;
			}
			if ( '' !== $q ) {
				$haystack = mb_strtolower( self::normalize_text( (string) ( $row['canonical_name'] ?? '' ) ) . ' ' . (string) ( $row['attributes_json'] ?? '' ) );
				if ( false === mb_stripos( $haystack, $q ) ) {
					return false;
				}
			}
			return true;
		} ) );

		usort( $rows, static function ( array $a, array $b ): int {
			return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
		} );

		return self::map_products( $rows );
	}

	/** @return array<int, array<string, mixed>> */
	private static function map_categories( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'        => (int) $row['id'],
				'parent_id'  => isset( $row['parent_id'] ) && null !== $row['parent_id'] && '' !== $row['parent_id'] ? (int) $row['parent_id'] : null,
				'slug'       => (string) $row['slug'],
				'name_key'   => (string) $row['name_key'],
				'name'       => Lang::text( (string) $row['name_key'] ),
				'type'       => (string) $row['type'],
				'active'     => (bool) (int) $row['active'],
			);
		}
		return $out;
	}

	/** @return array<int, array<string, mixed>> */
	private static function map_category_attributes( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'            => (int) $row['id'],
				'category_id'    => (int) $row['category_id'],
				'key'           => (string) $row['key'],
				'label_key'     => (string) $row['label_key'],
				'label'         => Lang::text( (string) $row['label_key'] ),
				'data_type'     => (string) $row['data_type'],
				'required'      => (bool) (int) $row['required'],
				'options_json'  => self::json_decode_array( (string) $row['options_json'] ),
				'sort'          => (int) $row['sort'],
			);
		}
		return $out;
	}

	/** @return array<int, array<string, mixed>> */
	private static function map_products( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = self::format_product( $row );
		}
		return $out;
	}

	/** @return array<string, mixed> */
	private static function format_product( array $row, ?Store $store = null ): array {
		$store = self::store( $store );
		$cat   = self::get_category( (int) ( $row['category_id'] ?? 0 ), $store );

		return array(
			'id'                => (int) $row['id'],
			'category_id'       => (int) $row['category_id'],
			'category_name_key' => $cat['name_key'] ?? null,
			'category_name'     => isset( $cat['name_key'] ) ? Lang::text( (string) $cat['name_key'] ) : null,
			'canonical_name'    => (string) $row['canonical_name'],
			'attributes_json'   => self::json_decode_array( (string) $row['attributes_json'] ),
			'created_by'        => (int) $row['created_by'],
			'status'            => (string) $row['status'],
			'variants'          => self::map_variants( self::get_product_variants( (int) $row['id'], $store ) ),
		);
	}

	/** @return array<int, array<string, mixed>> */
	private static function map_variants( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'              => (int) $row['id'],
				'variant_key'     => (string) $row['variant_key'],
				'attributes_json' => self::json_decode_array( (string) $row['attributes_json'] ),
			);
		}
		return $out;
	}

	/**
	 * @return array{rows:array<int,array<string,mixed>>, total:int}
	 */
	private static function slice( array $rows, int $page, int $per_page ): array {
		$total  = count( $rows );
		$offset = max( 0, $page - 1 ) * $per_page;
		return array(
			'rows'  => array_values( array_slice( $rows, $offset, $per_page ) ),
			'total' => $total,
		);
	}

	private static function paginated( array $rows, int $page, int $per_page ): array {
		$page_data = self::slice( $rows, $page, $per_page );
		return Rest::paginated( $page_data['rows'], $page_data['total'], $page, $per_page );
	}

	/** @return array<string, mixed> */
	private static function collection_filters( WP_REST_Request $request ): array {
		$page     = self::positive_int( $request->get_param( 'page' ), 1 );
		$per_page = self::positive_int( $request->get_param( 'per_page' ), 20 );
		if ( $per_page > 100 ) {
			$per_page = 100;
		}

		return array(
			'page'        => $page,
			'per_page'    => $per_page,
			'type'        => self::nullable_string( $request->get_param( 'type' ) ),
			'active'      => self::nullable_bool( $request->get_param( 'active' ) ),
			'parent_id'   => self::nullable_int( $request->get_param( 'parent_id' ) ),
			'category_id' => self::nullable_int( $request->get_param( 'category_id' ) ),
			'status'      => self::nullable_string( $request->get_param( 'status' ) ),
			'q'           => self::nullable_string( $request->get_param( 'q' ) ) ?? '',
		);
	}

	private static function store( ?Store $store = null ): Store {
		return $store ?? Store::default();
	}

	private static function positive_int( mixed $value, int $default ): int {
		$int = is_numeric( $value ) ? (int) $value : 0;
		return $int > 0 ? $int : $default;
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
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		return '' === $value ? null : $value;
	}

	private static function nullable_bool( mixed $value ): ?bool {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$result = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		return null === $result ? null : $result;
	}

	private static function normalize_text( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( class_exists( '\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $value, \Normalizer::FORM_C );
			if ( false !== $normalized && null !== $normalized ) {
				$value = $normalized;
			}
		}
		return preg_replace( '/\s+/u', ' ', $value ) ?? $value;
	}

	private static function normalize_variant_key( string $value ): string {
		$value = strtolower( self::normalize_text( $value ) );
		return preg_match( '/^[a-z0-9._-]{1,191}$/', $value ) ? $value : '';
	}

	private static function expect_assoc_array( mixed $value ): ?array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return null;
		}
		return $value;
	}

	private static function matches_type( mixed $value, string $type ): bool {
		return match ( strtolower( $type ) ) {
			'bool', 'boolean' => is_bool( $value ),
			'int', 'integer'  => is_int( $value ) || ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ),
			'number'          => is_int( $value ) || is_float( $value ) || is_numeric( $value ),
			'array'           => is_array( $value ) && array_is_list( $value ),
			'object'          => is_array( $value ) && ! array_is_list( $value ),
			'select'          => true,
			default           => is_scalar( $value ) || null === $value,
		};
	}

	/** @return array<int, mixed> */
	private static function json_decode_array( string $json ): array {
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
