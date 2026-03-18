<?php

declare( strict_types=1 );

namespace Kratt\Settings;

class Settings {

	private const OPTION_CATALOG    = 'kratt_block_catalog';
	private const OPTION_SCANNED_AT = 'kratt_catalog_scanned_at';

	public static function get_catalog(): array {
		return get_option( self::OPTION_CATALOG, [] );
	}

	public static function save_catalog( array $catalog ): void {
		update_option( self::OPTION_CATALOG, $catalog, autoload: false );
		update_option( self::OPTION_SCANNED_AT, current_time( 'mysql' ), autoload: false );
	}

	public static function get_catalog_scanned_at(): ?string {
		$value = get_option( self::OPTION_SCANNED_AT, '' );
		return $value !== '' ? $value : null;
	}
}
