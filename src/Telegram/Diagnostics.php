<?php
declare( strict_types=1 );

namespace Trade\Telegram;

/** Small admin-only diagnostic screen for Telegram connectivity. */
final class Diagnostics {

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_post_trade_telegram_diagnostics', array( self::class, 'run' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'trade',
			'Telegram Diagnostics',
			'Telegram Diagnostics',
			'manage_options',
			'trade-telegram-diagnostics',
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sorry, you are not allowed to do that.' );
		}
		$result = get_transient( 'trade_telegram_diagnostics_' . get_current_user_id() );
		delete_transient( 'trade_telegram_diagnostics_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1>Telegram Diagnostics</h1>
			<p>Run a live outbound Telegram API test. The bot token is never displayed.</p>
			<?php if ( is_array( $result ) ) : ?>
				<h2>Result</h2>
				<table class="widefat striped" style="max-width:900px">
					<tbody>
					<?php self::row( 'Token configured', ! empty( $result['token_configured'] ) ? 'YES' : 'NO' ); ?>
					<?php self::section_row( 'getMe', $result['api'] ?? array() ); ?>
					<?php self::section_row( 'getWebhookInfo', $result['webhook'] ?? array() ); ?>
					<?php self::section_row( 'sendMessage test', $result['send_test'] ?? array() ); ?>
				</tbody>
				</table>
			<?php endif; ?>
			<hr>
			<h2>Test outbound message</h2>
			<p>Enter your Telegram chat ID. The test sends one short message to that chat.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="trade_telegram_diagnostics">
				<?php wp_nonce_field( 'trade_telegram_diagnostics' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="trade_telegram_chat_id">Chat ID</label></th>
						<td><input name="chat_id" id="trade_telegram_chat_id" type="number" step="1" class="regular-text" required></td>
					</tr>
				</table>
				<?php submit_button( 'Run Telegram Diagnostics' ); ?>
			</form>
		</div>
		<?php
	}

	private static function row( string $label, string $value ): void {
		echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	private static function section_row( string $label, array $value ): void {
		if ( ! $value ) {
			self::row( $label, 'Not run' );
			return;
		}
		$parts = array();
		$parts[] = ! empty( $value['ok'] ) ? 'OK' : 'FAILED';
		if ( isset( $value['http_status'] ) ) {
			$parts[] = 'HTTP ' . (int) $value['http_status'];
		}
		if ( isset( $value['error'] ) && is_array( $value['error'] ) ) {
			if ( ! empty( $value['error']['code'] ) ) {
				$parts[] = 'code=' . $value['error']['code'];
			}
			if ( ! empty( $value['error']['telegram_description'] ) ) {
				$parts[] = $value['error']['telegram_description'];
			} elseif ( ! empty( $value['error']['message'] ) ) {
				$parts[] = $value['error']['message'];
			}
		}
		if ( isset( $value['data']['username'] ) ) {
			$parts[] = '@' . $value['data']['username'];
		}
		self::row( $label, implode( ' — ', $parts ) );
	}

	public static function run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sorry, you are not allowed to do that.' );
		}
		check_admin_referer( 'trade_telegram_diagnostics' );
		$chat_id = isset( $_POST['chat_id'] ) ? (int) $_POST['chat_id'] : 0;
		$result = ( new Bot() )->diagnostics( $chat_id > 0 ? $chat_id : null );
		set_transient( 'trade_telegram_diagnostics_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=trade-telegram-diagnostics' ) );
		exit;
	}
}
