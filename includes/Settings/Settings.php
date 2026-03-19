<?php

declare( strict_types=1 );

namespace Kratt\Settings;

class Settings {

	private const OPTION_CATALOG    = 'kratt_block_catalog';
	private const OPTION_SCANNED_AT = 'kratt_catalog_scanned_at';

	/**
	 * Returns the stored block catalog, or an empty array if not yet scanned.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_catalog(): array {
		return get_option( self::OPTION_CATALOG, [] );
	}

	/**
	 * Persists the block catalog and records the current timestamp.
	 *
	 * @param array<string, mixed> $catalog The catalog to store.
	 */
	public static function save_catalog( array $catalog ): void {
		update_option( self::OPTION_CATALOG, $catalog, autoload: false );
		update_option( self::OPTION_SCANNED_AT, current_time( 'mysql' ), autoload: false );
	}

	/**
	 * Returns the datetime of the last catalog scan, or null if never scanned.
	 */
	public static function get_catalog_scanned_at(): ?string {
		$value = get_option( self::OPTION_SCANNED_AT, '' );
		return '' !== $value ? $value : null;
	}
}
