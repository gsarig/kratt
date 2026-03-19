<?php
/**
 * Runs on plugin uninstall and removes all options stored by Kratt.
 *
 * @see register_uninstall_hook()
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'kratt_block_catalog' );
delete_option( 'kratt_catalog_scanned_at' );
delete_option( 'kratt_additional_instructions' );
