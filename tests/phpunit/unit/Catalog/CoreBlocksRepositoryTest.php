<?php

namespace Kratt\Tests\Unit\Catalog;

use Kratt\Catalog\CoreBlocksRepository;
use WP_UnitTestCase;

/**
 * Unit tests for CoreBlocksRepository.
 *
 * Verifies that the bundled core-blocks.json is loaded correctly and
 * contains the expected structure and data.
 */
class CoreBlocksRepositoryTest extends WP_UnitTestCase {

	public function test_get_returns_array(): void {
		$blocks = CoreBlocksRepository::get();

		$this->assertIsArray( $blocks );
	}

	public function test_catalog_is_not_empty(): void {
		$blocks = CoreBlocksRepository::get();

		$this->assertNotEmpty( $blocks );
	}

	public function test_keys_are_block_slugs(): void {
		$blocks = CoreBlocksRepository::get();

		foreach ( array_keys( $blocks ) as $key ) {
			$this->assertStringContainsString( '/', $key, "Block key '{$key}' does not look like a block slug (expected 'namespace/name' format)." );
		}
	}

	public function test_contains_core_paragraph(): void {
		$blocks = CoreBlocksRepository::get();

		$this->assertArrayHasKey( 'core/paragraph', $blocks );
	}

	public function test_contains_core_heading(): void {
		$blocks = CoreBlocksRepository::get();

		$this->assertArrayHasKey( 'core/heading', $blocks );
	}

	public function test_contains_core_image(): void {
		$blocks = CoreBlocksRepository::get();

		$this->assertArrayHasKey( 'core/image', $blocks );
	}

	public function test_core_paragraph_has_title(): void {
		$blocks = CoreBlocksRepository::get();

		$this->assertArrayHasKey( 'title', $blocks['core/paragraph'] );
		$this->assertNotEmpty( $blocks['core/paragraph']['title'] );
	}

	public function test_core_paragraph_has_description(): void {
		$blocks = CoreBlocksRepository::get();

		$this->assertArrayHasKey( 'description', $blocks['core/paragraph'] );
		$this->assertNotEmpty( $blocks['core/paragraph']['description'] );
	}

	public function test_all_entries_have_required_keys(): void {
		$blocks   = CoreBlocksRepository::get();
		$required = [ 'title', 'description', 'keywords', 'hint', 'attributes' ];

		foreach ( $blocks as $name => $block ) {
			foreach ( $required as $key ) {
				$this->assertArrayHasKey( $key, $block, "Block '{$name}' is missing key '{$key}'." );
			}
		}
	}

	public function test_all_blocks_are_core_namespace(): void {
		$blocks = CoreBlocksRepository::get();

		foreach ( array_keys( $blocks ) as $name ) {
			$this->assertStringStartsWith( 'core/', $name, "Expected all bundled blocks to be in the 'core/' namespace, but found '{$name}'." );
		}
	}
}
