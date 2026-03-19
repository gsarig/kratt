<?php

namespace Kratt\Tests\Unit\Catalog;

use Kratt\Catalog\BlockCatalog;
use WP_UnitTestCase;

/**
 * Unit tests for BlockCatalog::filter_by_allowed() and BlockCatalog::format_for_prompt().
 *
 * These tests exercise pure array logic and do not touch the database or block registry.
 */
class BlockCatalogTest extends WP_UnitTestCase {

	// =========================================================================
	// Fixtures
	// =========================================================================

	private function sample_catalog(): array {
		return [
			'core/paragraph' => [
				'name'        => 'core/paragraph',
				'source'      => 'core',
				'enabled'     => true,
				'title'       => 'Paragraph',
				'description' => 'Start with the building block of all narrative.',
				'keywords'    => [ 'text', 'body' ],
				'hint'        => 'Use for body copy.',
				'dynamic'     => false,
				'attributes'  => [
					'content' => [ 'type' => 'string', 'description' => 'The text content.' ],
				],
				'example'     => [ 'attributes' => [ 'content' => 'Hello world.' ] ],
			],
			'core/heading'   => [
				'name'        => 'core/heading',
				'source'      => 'core',
				'enabled'     => true,
				'title'       => 'Heading',
				'description' => 'Introduce new sections.',
				'keywords'    => [],
				'hint'        => '',
				'dynamic'     => false,
				'attributes'  => [
					'level'   => [ 'type' => 'integer', 'description' => 'Heading level 1-6.' ],
					'content' => [ 'type' => 'string', 'description' => '' ],
				],
				'example'     => [],
			],
			'acme/map'       => [
				'name'        => 'acme/map',
				'source'      => 'custom',
				'enabled'     => true,
				'title'       => 'Acme Map',
				'description' => 'Display an interactive map.',
				'keywords'    => [],
				'hint'        => '',
				'dynamic'     => true,
				'attributes'  => [],
				'example'     => [],
			],
			'acme/disabled'  => [
				'name'        => 'acme/disabled',
				'source'      => 'custom',
				'enabled'     => false,
				'title'       => 'Disabled Block',
				'description' => 'This block is disabled.',
				'keywords'    => [],
				'hint'        => '',
				'dynamic'     => false,
				'attributes'  => [],
				'example'     => [],
			],
		];
	}

	// =========================================================================
	// filter_by_allowed() tests
	// =========================================================================

	public function test_filter_returns_all_when_true(): void {
		$catalog  = $this->sample_catalog();
		$filtered = BlockCatalog::filter_by_allowed( $catalog, true );

		$this->assertSame( $catalog, $filtered );
	}

	public function test_filter_returns_empty_when_false(): void {
		$filtered = BlockCatalog::filter_by_allowed( $this->sample_catalog(), false );

		$this->assertEmpty( $filtered );
	}

	public function test_filter_returns_empty_when_empty_array(): void {
		$filtered = BlockCatalog::filter_by_allowed( $this->sample_catalog(), [] );

		$this->assertEmpty( $filtered );
	}

	public function test_filter_returns_only_allowed_blocks(): void {
		$filtered = BlockCatalog::filter_by_allowed(
			$this->sample_catalog(),
			[ 'core/paragraph', 'acme/map' ]
		);

		$this->assertArrayHasKey( 'core/paragraph', $filtered );
		$this->assertArrayHasKey( 'acme/map', $filtered );
		$this->assertArrayNotHasKey( 'core/heading', $filtered );
		$this->assertArrayNotHasKey( 'acme/disabled', $filtered );
	}

	public function test_filter_returns_empty_when_no_match(): void {
		$filtered = BlockCatalog::filter_by_allowed(
			$this->sample_catalog(),
			[ 'nonexistent/block' ]
		);

		$this->assertEmpty( $filtered );
	}

	public function test_filter_preserves_array_keys(): void {
		$filtered = BlockCatalog::filter_by_allowed(
			$this->sample_catalog(),
			[ 'core/heading' ]
		);

		$this->assertArrayHasKey( 'core/heading', $filtered );
		$this->assertCount( 1, $filtered );
	}

	// =========================================================================
	// format_for_prompt() tests
	// =========================================================================

	public function test_format_returns_empty_string_for_empty_catalog(): void {
		$output = BlockCatalog::format_for_prompt( [] );

		$this->assertSame( '', $output );
	}

	public function test_format_skips_disabled_blocks(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		$this->assertStringNotContainsString( 'acme/disabled', $output );
		$this->assertStringNotContainsString( 'Disabled Block', $output );
	}

	public function test_format_contains_block_name(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		$this->assertStringContainsString( 'core/paragraph', $output );
		$this->assertStringContainsString( 'core/heading', $output );
	}

	public function test_format_contains_block_title(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		$this->assertStringContainsString( 'Paragraph', $output );
		$this->assertStringContainsString( 'Heading', $output );
	}

	public function test_format_contains_block_description(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		$this->assertStringContainsString( 'building block of all narrative', $output );
	}

	public function test_format_adds_custom_tag_for_non_core_blocks(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		$this->assertStringContainsString( 'acme/map', $output );
		$this->assertStringContainsString( 'CUSTOM', $output );
	}

	public function test_format_adds_dynamic_tag_for_dynamic_blocks(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		$this->assertStringContainsString( 'DYNAMIC', $output );
	}

	public function test_format_no_tags_for_core_static_blocks(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		// The core/paragraph line should not have any tags
		$lines = explode( "\n\n", $output );
		$paragraph_line = array_values( array_filter( $lines, static fn( $l ) => str_contains( $l, 'core/paragraph' ) ) );

		$this->assertNotEmpty( $paragraph_line );
		$this->assertStringNotContainsString( '[CUSTOM]', $paragraph_line[0] );
		$this->assertStringNotContainsString( '[DYNAMIC]', $paragraph_line[0] );
	}

	public function test_format_includes_hint_when_set(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		$this->assertStringContainsString( 'Hint: Use for body copy.', $output );
	}

	public function test_format_includes_keywords_section(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		$this->assertStringContainsString( 'Also known as: text, body', $output );
	}

	public function test_format_includes_attributes_section(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		$this->assertStringContainsString( 'Attributes:', $output );
		$this->assertStringContainsString( 'content (string)', $output );
	}

	public function test_format_includes_example_as_json(): void {
		$output = BlockCatalog::format_for_prompt( $this->sample_catalog() );

		$this->assertStringContainsString( 'Example:', $output );
		$this->assertStringContainsString( '"content"', $output );
	}

	public function test_format_blocks_are_separated_by_double_newline(): void {
		$catalog = [
			'core/paragraph' => $this->sample_catalog()['core/paragraph'],
			'core/heading'   => $this->sample_catalog()['core/heading'],
		];
		$output = BlockCatalog::format_for_prompt( $catalog );

		$this->assertStringContainsString( "\n\n", $output );
	}

	public function test_format_enum_attributes_use_pipe_notation(): void {
		$catalog = [
			'core/button' => [
				'name'        => 'core/button',
				'source'      => 'core',
				'enabled'     => true,
				'title'       => 'Button',
				'description' => 'A clickable button.',
				'keywords'    => [],
				'hint'        => '',
				'dynamic'     => false,
				'attributes'  => [
					'type' => [
						'type'        => 'string',
						'description' => 'Button type.',
						'enum'        => [ 'button', 'submit', 'reset' ],
					],
				],
				'example'     => [],
			],
		];
		$output = BlockCatalog::format_for_prompt( $catalog );

		$this->assertStringContainsString( 'button|submit|reset', $output );
	}
}
