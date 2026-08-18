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

	private ?wpdb $db;

	private static ?Store $default_store = null;

	public function __construct( ?wpdb $db = null ) {
		$this->db = $db ?? ( isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null );
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
		$row = $this->db->get_row( $this->prepare( "SELECT * FROM `{$table}` WHERE {$where_sql} {$tail} LIMIT 1", $args ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function get_rows( string $table, string $where_sql, array $args = array() ): array {
		return $this->db->get_results( $this->prepare( "SELECT * FROM `{$table}` WHERE {$where_sql}", $args ), ARRAY_A );
	}

	public function get_col( string $sql, array $args = array() ): array {
		return $this->db->get_col( $this->prepare( $sql, $args ) );
	}

	public function insert( string $table, array $data ): bool {
		return (bool) $this->db->insert( $table, $data );
	}

	/** Equal-match update via wpdb; returns affected rows. */
	public function update( string $table, array $set, array $where ): int {
		$n = $this->db->update( $table, $set, $where );
		return is_int( $n ) ? $n : 0;
	}

	/** Prepared UPDATE with a raw-where fragment; returns affected rows. For atomic conditional writes (claim/lease). */
	public function update_where( string $table, array $set, string $where_sql, array $args = array() ): int {
		$fields = array_keys( $set );
		array_walk( $fields, static function ( &$f ) {
			$f = "`{$f}` = %s";
		} );
		$sql = "UPDATE `{$table}` SET " . implode( ', ', $fields ) . " WHERE {$where_sql}";
		$this->db->query( $this->prepare( $sql, array_merge( array_values( $set ), $args ) ) );
		return $this->db->rows_affected;
	}

	/** Prepared DELETE with raw-where fragment; returns affected rows. */
	public function delete_where( string $table, string $where_sql, array $args = array() ): int {
		$this->db->query( $this->prepare( "DELETE FROM `{$table}` WHERE {$where_sql}", $args ) );
		return $this->db->rows_affected;
	}

	/**
	 * Prepared UPDATE with a raw SET expression (column arithmetic) + raw-where fragment.
	 * For §B.7.2 atomic stock mutations: stock = stock - %d, version = version + 1 WHERE ….
	 * $set_args and $where_args are merged for prepare(); each %d/%s consumed left-to-right.
	 */
	public function update_expr( string $table, string $set_sql, array $set_args, string $where_sql, array $where_args = array() ): int {
		$sql = "UPDATE `{$table}` SET {$set_sql} WHERE {$where_sql}";
		$this->db->query( $this->prepare( $sql, array_merge( $set_args, $where_args ) ) );
		return $this->db->rows_affected;
	}

	public function count( string $table ): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}

	public function last_insert_id(): int {
		return (int) $this->db->insert_id;
	}

	private function prepare( string $sql, array $args ): string {
		return count( $args ) ? $this->db->prepare( $sql, $args ) : $sql;
	}
}