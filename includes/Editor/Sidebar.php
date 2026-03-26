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

		wp_localize_script(
			'kratt-sidebar',
			'krattData',
			[
				'blockSnippetMaxChars' => max( 0, (int) apply_filters( 'kratt_block_snippet_max_chars', KRATT_BLOCK_SNIPPET_MAX_CHARS ) ),
			]
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
