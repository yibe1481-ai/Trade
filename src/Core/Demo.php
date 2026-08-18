<?php
declare( strict_types=1 );

namespace Trade\Core;

use WP_REST_Request;

/**
 * Phase 0 exit-criterion proof: an endpoint that is added, authorized, audited,
 * error-handled, and idempotent end to end. Flag-gated; replaced by real modules.
 */
final class Demo {

	public static function status( WP_REST_Request $request ): array {
		$store  = Store::default();
		$tables = array( 'tb_feature_flags', 'tb_jobs', 'tb_events', 'tb_audit_logs', 'tb_idempotency_keys', 'tb_languages', 'tb_translations' );
		$counts = array();
		foreach ( $tables as $t ) {
			$counts[ $t ] = $store->count( $t );
		}
		return array(
			'data' => array(
				'plugin'     => 'trade',
				'schema'     => Db::VERSION,
				'tables'     => $counts,
				'flags'      => Flags::all(),
				'languages'  => $store->get_col( 'SELECT code FROM tb_languages WHERE enabled = 1 ORDER BY is_default DESC, code' ),
				'request_id' => Request::id(),
				'now'        => gmdate( 'c' ),
			),
		);
	}

	public static function echo( WP_REST_Request $request ): array {
		$params = $request->get_json_params() ?: array();
		$msg    = isset( $params['message'] ) ? $params['message'] : '';
		if ( ! is_string( $msg ) || '' === trim( $msg ) || mb_strlen( trim( $msg ) ) > 500 ) {
			throw Error::validation( array( 'message' ) );
		}

		$msg = trim( $msg );
		Audit::write( 'system.echo', 'system', '0', array(), array( 'message' => $msg ) );
		Events::emit( 'system.echo', array( 'message' => $msg ) );

		return array(
			'data' => array(
				'echo'        => $msg,
				'received_at' => gmdate( 'c' ),
				'request_id'  => Request::id(),
			),
		);
	}
}