<?php
declare( strict_types=1 );

namespace Trade\Core;

use wpdb;

/**
 * Schema + seeds. The only file that knows the CREATE TABLEs.
 * Literal tb_ prefix (spec §B.4: "prefix is plugin-owned, not assumed wp_"),
 * explicit utf8mb4 collate — this server's default is utf8mb3, which breaks Amharic (§B.11.1).
 *
 * dbDelta-safe: each column/KEY on its own line, no trailing comma, KEY not INDEX.
 * NOTE: spec column names that collide with MySQL reserved words are renamed:
 *   tb_feature_flags.key       → flag_key
 *   tb_idempotency_keys.key    → idem_key
 * (documented in interfaces/core.md). Nothing else deviates from §B.4.
 */
final class Db {

	public const VERSION = '0.5.2';

	public const OPTION = 'trade_schema_version';

	/** @return array<string,string> table => CREATE TABLE DDL */
	public static function tables(): array {
		return self::TABLES;
	}

	private const TABLES = array(
		'tb_feature_flags' => 'CREATE TABLE tb_feature_flags (
  flag_key varchar(100) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 0,
  updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (flag_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_events' => 'CREATE TABLE tb_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event_name varchar(100) NOT NULL,
  payload_json longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_audit_logs' => 'CREATE TABLE tb_audit_logs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  actor_id varchar(64) NOT NULL,
  actor_type varchar(20) NOT NULL,
  action varchar(100) NOT NULL,
  entity varchar(100) NOT NULL,
  entity_id varchar(100) NOT NULL,
  source varchar(20) NOT NULL,
  before_json longtext NOT NULL,
  after_json longtext NOT NULL,
  metadata_json longtext NOT NULL,
  request_id varchar(64) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_jobs' => 'CREATE TABLE tb_jobs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(100) NOT NULL,
  payload_json longtext NOT NULL,
  run_after datetime NOT NULL,
  status varchar(20) NOT NULL,
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  max_attempts int(10) unsigned NOT NULL DEFAULT 5,
  lock_token varchar(64) NOT NULL DEFAULT \'\',
  lease_expires_at datetime NULL,
  last_error text NULL,
  idempotency_key varchar(191) NOT NULL DEFAULT \'\',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_jobs_status_run (status, run_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_idempotency_keys' => 'CREATE TABLE tb_idempotency_keys (
  idem_key varchar(191) NOT NULL,
  wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  endpoint varchar(191) NOT NULL,
  request_hash varchar(64) NOT NULL,
  response_json longtext NULL,
  status_code int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  UNIQUE KEY uq_idem (idem_key, wp_user_id, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_throttle' => 'CREATE TABLE tb_throttle (
  bucket_key varchar(191) NOT NULL,
  window_started_at datetime NOT NULL,
  window_seconds int(11) NOT NULL,
  count int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_languages' => 'CREATE TABLE tb_languages (
  code varchar(10) NOT NULL,
  name varchar(100) NOT NULL,
  native_name varchar(100) NOT NULL,
  direction varchar(3) NOT NULL DEFAULT \'ltr\',
  enabled tinyint(1) NOT NULL DEFAULT 1,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_translations' => 'CREATE TABLE tb_translations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  language_code varchar(10) NOT NULL,
  string_key varchar(191) NOT NULL,
  value text NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_trans (language_code, string_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_locations' => 'CREATE TABLE tb_locations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned NULL,
  level int(10) unsigned NOT NULL DEFAULT 0,
  name_key varchar(191) NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_locations_parent (parent_id, level),
  KEY idx_locations_name (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_categories' => 'CREATE TABLE tb_categories (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned NULL,
  slug varchar(191) NOT NULL,
  name_key varchar(191) NOT NULL,
  type varchar(20) NOT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_categories_slug (slug),
  KEY idx_categories_parent (parent_id, type),
  KEY idx_categories_active (active, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_category_attributes' => 'CREATE TABLE tb_category_attributes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  category_id bigint(20) unsigned NOT NULL,
  `key` varchar(100) NOT NULL,
  label_key varchar(191) NOT NULL,
  data_type varchar(20) NOT NULL,
  required tinyint(1) NOT NULL DEFAULT 0,
  options_json longtext NOT NULL,
  sort int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_category_attribute (category_id, `key`),
  KEY idx_category_attribute_sort (category_id, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_products' => 'CREATE TABLE tb_products (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  category_id bigint(20) unsigned NOT NULL,
  canonical_name varchar(255) NOT NULL,
  attributes_json longtext NOT NULL,
  created_by bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT \'active\',
  PRIMARY KEY  (id),
  KEY idx_products_category (category_id, status),
  KEY idx_products_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_product_variants' => 'CREATE TABLE tb_product_variants (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  variant_key varchar(191) NOT NULL,
  attributes_json longtext NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_product_variant (product_id, variant_key),
  KEY idx_product_variants_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_identity' => 'CREATE TABLE tb_identity (
  telegram_user_id varchar(64) NOT NULL,
  wp_user_id bigint(20) unsigned NOT NULL,
  language varchar(10) NOT NULL DEFAULT \'en\',
  created_at datetime NOT NULL,
  PRIMARY KEY  (telegram_user_id),
  KEY idx_identity_wp (wp_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_sessions' => 'CREATE TABLE tb_sessions (
  token_hash varchar(64) NOT NULL,
  wp_user_id bigint(20) unsigned NOT NULL,
  issued_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  expires_at datetime NOT NULL,
  revoked_at datetime NULL,
  PRIMARY KEY  (token_hash),
  KEY idx_sessions_wp (wp_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_consents' => 'CREATE TABLE tb_consents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  wp_user_id bigint(20) unsigned NOT NULL,
  consent_type varchar(50) NOT NULL,
  granted tinyint(1) NOT NULL DEFAULT 0,
  version varchar(20) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_consents_wp_type (wp_user_id, consent_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_customer_profiles' => 'CREATE TABLE tb_customer_profiles (
  wp_user_id bigint(20) unsigned NOT NULL,
  display_name varchar(100) NOT NULL DEFAULT \'\',
  location_id bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (wp_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_merchants' => 'CREATE TABLE tb_merchants (
  wp_user_id bigint(20) unsigned NOT NULL,
  business_name varchar(255) NOT NULL DEFAULT \'\',
  merchant_type varchar(50) NOT NULL DEFAULT \'\',
  location_id bigint(20) unsigned NULL,
  verification_status varchar(20) NOT NULL DEFAULT \'none\',
  verified_at datetime NULL,
  suspended_at datetime NULL,
  PRIMARY KEY  (wp_user_id),
  KEY idx_merchants_location (location_id),
  KEY idx_merchants_verification (verification_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_listings' => 'CREATE TABLE tb_listings (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  merchant_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  variant_id bigint(20) unsigned NULL,
  category_id bigint(20) unsigned NOT NULL,
  price bigint(20) NOT NULL,
  currency varchar(3) NOT NULL DEFAULT \'ETB\',
  location_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT \'draft\',
  published_at datetime NULL,
  search_text longtext NOT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_listings_status (status, category_id, location_id, price),
  KEY idx_listings_merchant (merchant_id, status),
  KEY idx_listings_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_inventory' => 'CREATE TABLE tb_inventory (
  listing_id bigint(20) unsigned NOT NULL,
  stock int(11) NOT NULL DEFAULT 0,
  sku varchar(191) NOT NULL DEFAULT \'\',
  updated_at datetime NOT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY  (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_service_availability' => 'CREATE TABLE tb_service_availability (
  listing_id bigint(20) unsigned NOT NULL,
  availability_state varchar(30) NOT NULL DEFAULT \'unavailable\',
  note longtext NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (listing_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_listing_images' => 'CREATE TABLE tb_listing_images (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing_id bigint(20) unsigned NOT NULL,
  storage_key varchar(191) NOT NULL,
  thumb_key varchar(191) NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_listing_images_listing_sort (listing_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_entitlements' => 'CREATE TABLE tb_entitlements (
  merchant_id bigint(20) unsigned NOT NULL,
  `key` varchar(100) NOT NULL,
  value varchar(191) NOT NULL,
  PRIMARY KEY  (merchant_id, `key`),
  KEY idx_entitlements_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_subscriptions' => 'CREATE TABLE tb_subscriptions (
  merchant_id bigint(20) unsigned NOT NULL,
  plan_code varchar(100) NOT NULL,
  status varchar(20) NOT NULL,
  started_at datetime NOT NULL,
  ends_at datetime NULL,
  PRIMARY KEY  (merchant_id, plan_code, started_at),
  KEY idx_subscriptions_status (merchant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_orders' => 'CREATE TABLE tb_orders (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  customer_id bigint(20) unsigned NOT NULL,
  merchant_id bigint(20) unsigned NOT NULL,
  listing_id bigint(20) unsigned NOT NULL,
  price int unsigned NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT \'ETB\',
  qty int unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT \'REQUESTED\',
  customer_confirmed_at datetime NULL,
  merchant_confirmed_at datetime NULL,
  cancel_reason varchar(500) NULL,
  disputed_by varchar(20) NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_orders_customer_listing (customer_id, listing_id, status),
  KEY idx_orders_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_reviews' => 'CREATE TABLE tb_reviews (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  customer_id bigint(20) unsigned NOT NULL,
  merchant_id bigint(20) unsigned NOT NULL,
  listing_id bigint(20) unsigned NOT NULL,
  rating tinyint unsigned NOT NULL,
  comment text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_reviews_order (order_id),
  KEY idx_reviews_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_customer_requests' => 'CREATE TABLE tb_customer_requests (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  customer_id bigint(20) unsigned NOT NULL,
  category_id bigint(20) unsigned NOT NULL,
  attributes_json longtext NULL,
  budget_max int unsigned NOT NULL DEFAULT 0,
  location_id bigint(20) unsigned NOT NULL,
  urgency varchar(20) NOT NULL DEFAULT \'normal\',
  status varchar(20) NOT NULL DEFAULT \'OPEN\',
  fulfilled_order_id bigint(20) unsigned NULL,
  expires_at datetime NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_requests_customer (customer_id, status),
  KEY idx_requests_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_request_matches' => 'CREATE TABLE tb_request_matches (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_id bigint(20) unsigned NOT NULL,
  merchant_id bigint(20) unsigned NOT NULL,
  listing_id bigint(20) unsigned NOT NULL,
  score double NOT NULL DEFAULT 0,
  notified_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_matches_request (request_id),
  KEY idx_matches_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_verification_documents' => 'CREATE TABLE tb_verification_documents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  merchant_id bigint(20) unsigned NOT NULL,
  document_type varchar(50) NOT NULL,
  storage_key varchar(191) NOT NULL,
  status varchar(20) NOT NULL DEFAULT \'pending\',
  verified_at datetime NULL,
  revoked_at datetime NULL,
  revocation_reason text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_verification_merchant (merchant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_feature_flag_events' => 'CREATE TABLE tb_feature_flag_events (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  flag_key varchar(100) NOT NULL,
  changed_by bigint(20) unsigned NOT NULL,
  old_value tinyint(1) NOT NULL DEFAULT 0,
  new_value tinyint(1) NOT NULL DEFAULT 0,
  changed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_feature_flag_events_flag (flag_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_reports' => 'CREATE TABLE tb_reports (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  reporter_id bigint(20) unsigned NOT NULL,
  entity_type varchar(50) NOT NULL,
  entity_id bigint(20) unsigned NOT NULL,
  reason text NOT NULL,
  status varchar(20) NOT NULL DEFAULT \'pending\',
  resolved_by bigint(20) unsigned NULL,
  resolved_at datetime NULL,
  resolved_reason text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_reports_entity (entity_type, entity_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
		'tb_bot_chats' => 'CREATE TABLE tb_bot_chats (
  chat_id bigint(20) unsigned NOT NULL,
  state varchar(40) NOT NULL DEFAULT \'main\',
  data text NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci',
	);

	/** Create/upgrade schema. Idempotent via dbDelta; safe to run on every mismatch. */
	public static function install(): void {
		self::schema();
		self::seed();
		update_option( self::OPTION, self::VERSION );
	}

	/** admin_init: apply schema when the stored version is behind. */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::install();
		}
	}

	private static function schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( implode( ';', self::TABLES ) );
	}

	/** Minimal defaults: default language + dev-routes flag. Existing rows are left alone. */
	private static function seed(): void {
		$store = Store::default();

		$lang = $store->get_row( 'tb_languages', 'code = %s', array( 'en' ) );
		if ( null === $lang ) {
			$store->insert( 'tb_languages', array(
				'code'        => 'en',
				'name'        => 'English',
				'native_name' => 'English',
				'direction'   => 'ltr',
				'enabled'     => 1,
				'is_default'  => 1,
			) );
		}

		$flag = $store->get_row( 'tb_feature_flags', 'flag_key = %s', array( 'trade_dev_routes_enabled' ) );
		if ( null === $flag ) {
			$store->insert( 'tb_feature_flags', array(
				'flag_key'   => 'trade_dev_routes_enabled',
				'enabled'    => 0,
				'updated_by' => 0,
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			) );
		}
	}
}
