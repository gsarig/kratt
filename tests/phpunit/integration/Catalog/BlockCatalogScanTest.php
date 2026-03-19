<?php

namespace Kratt\Tests\Integration\Catalog;

use Kratt\Catalog\BlockCatalog;
use Kratt\Settings\Settings;
use WP_UnitTestCase;

/**
 * Integration tests for BlockCatalog::scan().
 *
 * Verifies that scanning the live block registry produces a correctly
 * structured catalog and that source classification is accurate.
 */
class BlockCatalogScanTest extends WP_UnitTestCase {

	private array $catalog;

	protected function setUp(): void {
		parent::setUp();
		BlockCatalog::scan();
		$this->catalog = BlockCatalog::get();
	}

	protected function tearDown(): void {
		delete_option( 'kratt_block_catalog' );
		delete_option( 'kratt_catalog_scanned_at' );
		parent::tearDown();
	}

	public function test_scan_produces_non_empty_catalog(): void {
		$this->assertNotEmpty( $this->catalog );
	}

	public function test_catalog_is_stored_to_database_after_scan(): void {
		// Retrieve directly from the option to confirm it was persisted.
		$stored = get_option( 'kratt_block_catalog', null );

		$this->assertIsArray( $stored );
		$this->assertNotEmpty( $stored );
	}

	public function test_scanned_at_is_set_after_scan(): void {
		$scanned_at = Settings::get_catalog_scanned_at();

		$this->assertNotNull( $scanned_at );
	}

	public function test_core_paragraph_is_in_catalog(): void {
		$this->assertArrayHasKey( 'core/paragraph', $this->catalog );
	}

	public function test_core_heading_is_in_catalog(): void {
		$this->assertArrayHasKey( 'core/heading', $this->catalog );
	}

	public function test_core_paragraph_has_core_source(): void {
		$this->assertSame( 'core', $this->catalog['core/paragraph']['source'] );
	}

	public function test_core_heading_has_core_source(): void {
		$this->assertSame( 'core', $this->catalog['core/heading']['source'] );
	}

	public function test_curated_core_block_has_title_from_bundled_json(): void {
		// core/paragraph is in the hand-curated core-blocks.json with rich descriptions.
		$this->assertArrayHasKey( 'title', $this->catalog['core/paragraph'] );
		$this->assertNotEmpty( $this->catalog['core/paragraph']['title'] );
	}

	public function test_all_catalog_entries_have_required_keys(): void {
		$required = [ 'name', 'source', 'enabled', 'title', 'description' ];

		foreach ( $this->catalog as $block_name => $block ) {
			foreach ( $required as $key ) {
				$this->assertArrayHasKey( $key, $block, "Block '{$block_name}' is missing required key '{$key}'." );
			}
		}
	}

	public function test_all_catalog_entries_have_boolean_enabled_flag(): void {
		foreach ( $this->catalog as $block_name => $block ) {
			$this->assertIsBool( $block['enabled'], "Block '{$block_name}' enabled flag is not a boolean." );
		}
	}

	public function test_core_blocks_never_classified_as_custom(): void {
		foreach ( $this->catalog as $block_name => $block ) {
			if ( str_starts_with( $block_name, 'core/' ) ) {
				$this->assertSame( 'core', $block['source'], "Block '{$block_name}' starts with 'core/' but has source '{$block['source']}'." );
			}
		}
	}

	public function test_non_core_blocks_are_classified_as_custom(): void {
		$non_core = array_filter(
			$this->catalog,
			static fn( string $name ) => ! str_starts_with( $name, 'core/' ),
			ARRAY_FILTER_USE_KEY
		);

		if ( empty( $non_core ) ) {
			$this->markTestSkipped( 'No custom blocks registered in this test environment.' );
		}

		foreach ( $non_core as $block_name => $block ) {
			$this->assertSame( 'custom', $block['source'], "Block '{$block_name}' does not start with 'core/' but has source '{$block['source']}'." );
		}
	}

	public function test_dynamic_field_is_boolean(): void {
		foreach ( $this->catalog as $block_name => $block ) {
			if ( isset( $block['dynamic'] ) ) {
				$this->assertIsBool( $block['dynamic'], "Block '{$block_name}' dynamic field is not a boolean." );
			}
		}
	}
}
