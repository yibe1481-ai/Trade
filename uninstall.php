<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Reverts Phase 0 schema + options. Spec §B.13.1.7: migrations reversible.
global $wpdb;

foreach ( array(
	'tb_feature_flags', 'tb_events', 'tb_audit_logs', 'tb_jobs', 'tb_idempotency_keys',
	'tb_languages', 'tb_translations', 'tb_identity', 'tb_sessions', 'tb_customer_profiles',
	'tb_consents', 'tb_throttle',
) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

delete_option( 'trade_schema_version' );
delete_option( 'trade_default_lang' );
delete_option( 'trade_telegram_bot_token' );
delete_option( 'trade_telegram_webhook_secret' );