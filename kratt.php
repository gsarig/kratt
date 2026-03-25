<?php
/**
 * Plugin Name: Kratt
 * Plugin URI:  https://github.com/gsarig/kratt
 * Description: WordPress AI block composer — generate and insert blocks via natural language using the WP AI Client.
 * Version:     0.1.0
 * Requires at least: 7.0
 * Requires PHP: 8.1
 * Author:      Giorgos Sarigiannidis
 * Author URI:  https://github.com/gsarig
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kratt
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KRATT_VERSION', '0.1.0' );
define( 'KRATT_DIR', plugin_dir_path( __FILE__ ) );
define( 'KRATT_URL', plugin_dir_url( __FILE__ ) );
define( 'KRATT_FILE', __FILE__ );
define( 'KRATT_EDITOR_CONTENT_MAX_CHARS', 8000 );
define( 'KRATT_BLOCK_SNIPPET_MAX_CHARS', 300 );
define( 'KRATT_MAX_PATTERNS', 30 );

if ( file_exists( KRATT_DIR . 'vendor/autoload.php' ) ) {
	require_once KRATT_DIR . 'vendor/autoload.php';
}

add_action(
	'plugins_loaded',
	function () {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			add_action(
				'admin_notices',
				function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'Kratt requires WordPress 7.0 or later with an AI provider plugin active (Anthropic, Google, or OpenAI).', 'kratt' )
					);
				}
			);
			return;
		}

		\Kratt\Plugin::instance()->init();
	}
);

register_activation_hook( __FILE__, [ \Kratt\Catalog\BlockCatalog::class, 'scan' ] );
