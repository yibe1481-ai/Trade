<?php
/**
 * Plugin Name: Trade Bot
 * Description: AI-assisted local commerce platform for Telegram (WordPress backend). Phase 0 — Foundation.
 * Version:     0.1.1
 * Requires PHP: 8.2
 * Author:      Trade Bot
 * Text Domain: trade
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/src/autoload.php';

use Trade\Catalog\Service as CatalogService;
use Trade\Core\Db;
use Trade\Core\Rest;
use Trade\Identity\Session as Sessions;
use Trade\Listings\Service as ListingsService;
use Trade\Merchant\Service as MerchantService;
use Trade\Search\Service as SearchService;
use Trade\Telegram\Webhook;
use Trade\Telegram\Diagnostics as TelegramDiagnostics;
use Trade\Orders\Service as OrdersService;
use Trade\Requests\Service as RequestsService;
use Trade\Verification\Service as VerificationService;
use Trade\TrustSafety\Service as TrustSafetyService;
use Trade\Notifications\Service as NotificationsService;
use Trade\AI\Service as AIService;
use Trade\Admin\Service as AdminService;
use Trade\MiniApp\Service as MiniAppService;

register_activation_hook( __FILE__, array( Db::class, 'install' ) );
add_action( 'plugins_loaded', array( AdminService::class, 'boot' ) );

// Temporary direct top-level diagnostic entry. This deliberately bypasses the
// Trade parent-menu registration so the Telegram API test remains reachable
// while the admin menu architecture is being stabilized.
add_action( 'admin_menu', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	add_menu_page(
		'Telegram Diagnostics',
		'Telegram Diagnostics',
		'manage_options',
		'trade-telegram-diagnostics',
		array( TelegramDiagnostics::class, 'render' ),
		'dashicons-telegram',
		3
	);
}, 1 );
add_action( 'admin_post_trade_telegram_diagnostics', array( TelegramDiagnostics::class, 'run' ) );

add_action( 'admin_init', array( Db::class, 'maybe_upgrade' ) );
add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
add_action( 'rest_api_init', array( CatalogService::class, 'routes' ) );
add_action( 'rest_api_init', array( MerchantService::class, 'routes' ) );
add_action( 'rest_api_init', array( ListingsService::class, 'routes' ) );
add_action( 'rest_api_init', array( Webhook::class, 'routes' ) );
add_action( 'rest_api_init', array( SearchService::class, 'routes' ) );
add_action( 'rest_api_init', array( OrdersService::class, 'routes' ) );
add_action( 'rest_api_init', array( RequestsService::class, 'routes' ) );
add_action( 'rest_api_init', array( VerificationService::class, 'routes' ) );
add_action( 'rest_api_init', array( TrustSafetyService::class, 'routes' ) );
add_action( 'rest_api_init', array( NotificationsService::class, 'routes' ) );
add_action( 'rest_api_init', array( AIService::class, 'routes' ) );
add_action( 'rest_api_init', array( MiniAppService::class, 'routes' ) );

add_action( 'trade.MERCHANT_VERIFICATION_REVOKED', array( ListingsService::class, 'pause_on_revocation' ) );
add_filter( 'user_has_cap', array( Sessions::class, 'grant_trade_caps' ), 10, 3 );
