<?php

declare( strict_types=1 );

namespace Kratt\Catalog;

class CoreBlocksRepository {

	private static ?array $blocks = null;

	public static function get(): array {
		if ( self::$blocks !== null ) {
			return self::$blocks;
		}

		$file = KRATT_DIR . 'src/data/core-blocks.json';

		if ( ! file_exists( $file ) ) {
			return [];
		}

		$json = file_get_contents( $file );

		if ( $json === false ) {
			return [];
		}

		$data = json_decode( $json, associative: true );

		if ( ! is_array( $data ) ) {
			return [];
		}

		self::$blocks = $data;
		return self::$blocks;
	}
}
