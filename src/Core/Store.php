<?php
declare( strict_types=1 );

namespace Trade\Core;

use wpdb;

/**
 * Thin $wpdb wrapper — the single SQL surface for the plugin's own tables.
 * Method list stays deliberately small: tools/smoke.php ships a MemoryStore
 * that mirrors every method here, so anything new must be implementable both ways.
 */
class Store {

	private ? wpdb $db;

	private static ?Store $default_store = null;

	public function __construct( ?wpdb $db = null ) {
		$this->db = $db;
	}

	/** Shared store; tools/smoke.php swaps in its MemoryStore via set_default(). */
	public static function default(): self {
		return self::$default_store ??= new self();
	}

	public static function set_default( ?self $store ): void {
		self::$default_store = $store;
	}

	/** Single row as assoc array, or null. $where_sql is a "k = %s AND …" fragment with $args; $tail may hold ORDER BY. */
	public function get_row( string $table, string $where_sql, array $args = array(), string $tail = '' ): ?array {
		$tail_clause = '' !== trim( $tail ) ? ' ' . trim( $tail ) : '';
		$sql         = "SELECT * FROM `{$table}` WHERE {$where_sql}{$tail_clause} LIMIT 1";
		$row         = $this->db()->get_row( $this->prepare( $sql, $args ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	public function get_rows( string $table, string $where_sql, array $args = array() ): array {
		$sql  = "SELECT * FROM `{$table}` WHERE {$where_sql}";
		$rows = $this->db()->get_results( $this->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function get_col( string $sql, array $args = array() ): array {
		$col = $this->db()->get_col( $this->prepare( $sql, $args ) );

		return is_array( $col ) ? $col : array();
	}

	public function insert( string $table, array $data ): bool {
		if ( empty( $data ) ) {
			return false;
		}

		return (bool) $this->db()->insert( $table, $data );
	}

	/** Equal-match update via wpdb; returns affected rows. */
	public function update( string $table, array $set, array $where ): int {
		if ( empty( $set ) || empty( $where ) ) {
			return 0;
		}

		$n = $this->db()->update( $table, $set, $where );

		return is_int( $n ) ? $n : 0;
	}

	/** Prepared UPDATE with a raw-where fragment; returns affected rows. For atomic conditional writes (claim/lease). */
	public function update_where( string $table, array $set, string $where_sql, array $args = array() ): int {
		if ( empty( $set ) ) {
			return 0;
		}

		$assignments = array();
		$set_values  = array();

		foreach ( $set as $column => $value ) {
			if ( null === $value ) {
				$assignments[] = "`{$column}` = NULL";
			} else {
				$placeholder   = is_int( $value ) || is_bool( $value ) ? '%d' : ( is_float( $value ) ? '%f' : '%s' );
				$assignments[] = "`{$column}` = {$placeholder}";
				$set_values[]  = $value;
			}
		}

		$sql = "UPDATE `{$table}` SET " . implode( ', ', $assignments ) . " WHERE {$where_sql}";
		$db  = $this->db();
		$db->query( $this->prepare( $sql, array_merge( $set_values, $args ) ) );

		return is_numeric( $db->rows_affected ) ? (int) $db->rows_affected : 0;
	}

	/** Prepared DELETE with raw-where fragment; returns affected rows. */
	public function delete_where( string $table, string $where_sql, array $args = array() ): int {
		$db = $this->db();
		$db->query( $this->prepare( "DELETE FROM `{$table}` WHERE {$where_sql}", $args ) );

		return is_numeric( $db->rows_affected ) ? (int) $db->rows_affected : 0;
	}

	/**
	 * Prepared UPDATE with a raw SET expression (column arithmetic) + raw-where fragment.
	 * For atomic stock mutations: stock = stock - %d, version = version + 1 WHERE ….
	 * $set_args and $where_args are merged for prepare(); each %d/%s consumed left-to-right.
	 */
	public function update_expr( string $table, string $set_sql, array $set_args, string $where_sql, array $where_args = array() ): int {
		$sql = "UPDATE `{$table}` SET {$set_sql} WHERE {$where_sql}";
		$db  = $this->db();
		$db->query( $this->prepare( $sql, array_merge( $set_args, $where_args ) ) );

		return is_numeric( $db->rows_affected ) ? (int) $db->rows_affected : 0;
	}

	public function count( string $table ): int {
		$count = $this->db()->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	public function last_insert_id(): int {
		return (int) $this->db()->insert_id;
	}

	private function db(): wpdb {
		$db = $this->db ?? ( $GLOBALS['wpdb'] ?? null );
		if ( ! $db instanceof wpdb ) {
			throw new \RuntimeException( 'Store: wpdb instance is not available.' );
		}

		return $db;
	}

	private function prepare( string $sql, array $args ): string {
		if ( empty( $args ) ) {
			return $sql;
		}

		return $this->db()->prepare( $sql, $args );
	}
}