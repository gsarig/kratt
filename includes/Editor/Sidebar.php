<?php

declare( strict_types=1 );

namespace Kratt\Editor;

class Sidebar {

	public static function enqueue(): void {
		$asset_file = KRATT_DIR . 'build/index.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'kratt-sidebar',
			KRATT_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'kratt-sidebar', 'kratt' );

		if ( file_exists( KRATT_DIR . 'build/index.css' ) ) {
			wp_enqueue_style(
				'kratt-sidebar',
				KRATT_URL . 'build/index.css',
				[],
				$asset['version']
			);
		}
	}
}
