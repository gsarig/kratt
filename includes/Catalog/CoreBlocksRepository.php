<?php

declare( strict_types=1 );

namespace Kratt\Catalog;

class CoreBlocksRepository {

	/**
	 * Cached block data loaded from the JSON file. Null until first access.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $blocks = null;

	/**
	 * Returns the hand-curated core block catalog loaded from the bundled JSON file.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		if ( null !== self::$blocks ) {
			return self::$blocks;
		}

		$file = KRATT_DIR . 'src/data/core-blocks.json';

		if ( ! file_exists( $file ) ) {
			return [];
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents( $file );

		if ( false === $json ) {
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
