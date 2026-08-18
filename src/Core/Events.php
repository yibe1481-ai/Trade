<?php
declare( strict_types=1 );

namespace Trade\Core;

/**
 * Event bus (§A.8, §B.14.5). emit() persists to tb_events AND fans out via do_action
 * ("trade.{name}") so in-WP listeners can react without polling.
 */
final class Events {

	public static function emit( string $event_name, array $payload = array() ): void {
		$payload = array_merge( array( 'request_id' => Request::id() ), $payload );

		Store::default()->insert( 'tb_events', array(
			'event_name'    => $event_name,
			'payload_json'  => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		) );

		/**
		 * Fires when a Trade event occurs.
		 *
		 * @param string $event_name
		 * @param array  $payload
		 */
		do_action( 'trade.event', $event_name, $payload );
		do_action( 'trade.' . $event_name, $payload );
	}
}