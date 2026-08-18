<?php
declare( strict_types=1 );

namespace Trade\Core;

/**
 * Audit writer — every mutating action writes a row (§A.11). Only core writes tb_audit_logs;
 * nothing else may touch that table directly (INDEX.md core invariant).
 */
final class Audit {

	public static function write(
		string $action,
		string $entity,
		string $entity_id,
		array $before = array(),
		array $after = array(),
		array $metadata = array(),
		string $actor_type = 'user',
		string $actor_id = '0',
		string $source = 'rest'
	): void {
		$actor = function_exists( 'get_current_user_id' ) ? (string) get_current_user_id() : $actor_id;
		Store::default()->insert( 'tb_audit_logs', array(
			'actor_id'      => $actor,
			'actor_type'    => $actor_type,
			'action'        => $action,
			'entity'        => $entity,
			'entity_id'     => $entity_id,
			'source'        => $source,
			'before_json'   => wp_json_encode( $before, JSON_UNESCAPED_UNICODE ),
			'after_json'    => wp_json_encode( $after, JSON_UNESCAPED_UNICODE ),
			'metadata_json' => wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE ),
			'request_id'    => Request::id(),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		) );
	}
}