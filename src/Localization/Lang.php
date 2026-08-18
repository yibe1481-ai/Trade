<?php
declare( strict_types=1 );

namespace Trade\Localization;

use Trade\Core\Store;

/**
 * String resolver — user-facing text always comes from tb_translations, never code
 * (§A.11 configuration-over-code; INDEX.md localization invariant).
 * Resolution: requested lang → en → bare key.
 */
final class Lang {

	public static function text( string $key, ?string $lang = null, ?Store $store = null ): string {
		$store = $store ?? Store::default();
		$lang  = $lang ?? ( get_option( 'trade_default_lang', 'en' ) ?: 'en' );

		foreach ( array_unique( array( $lang, 'en' ) ) as $code ) {
			$row = $store->get_row(
				'tb_translations',
				'language_code = %s AND string_key = %s',
				array( $code, $key )
			);
			if ( $row && '' !== $row['value'] ) {
				return $row['value'];
			}
		}
		return $key;
	}
}