<?php

namespace Kratt\Tests\Unit\AI;

use Kratt\AI\Client;
use WP_UnitTestCase;

/**
 * Unit tests for Client::compose() in test mode.
 *
 * The test bootstrap defines KRATT_TEST_MODE = true, so Client::compose()
 * always returns the deterministic dummy response. This lets us verify the
 * response structure without making real AI API calls.
 */
class ClientTest extends WP_UnitTestCase {

	private array $dummy_result;

	protected function setUp(): void {
		parent::setUp();
		$this->dummy_result = Client::compose( 'Add a heading', '', [] );
	}

	public function test_test_mode_returns_array(): void {
		$this->assertIsArray( $this->dummy_result );
	}

	public function test_test_mode_returns_blocks_key(): void {
		$this->assertArrayHasKey( 'blocks', $this->dummy_result );
	}

	public function test_test_mode_blocks_is_array(): void {
		$this->assertIsArray( $this->dummy_result['blocks'] );
	}

	public function test_test_mode_returns_two_blocks(): void {
		$this->assertCount( 2, $this->dummy_result['blocks'] );
	}

	public function test_test_mode_first_block_is_heading(): void {
		$first = $this->dummy_result['blocks'][0];

		$this->assertArrayHasKey( 'name', $first );
		$this->assertSame( 'core/heading', $first['name'] );
	}

	public function test_test_mode_second_block_is_paragraph(): void {
		$second = $this->dummy_result['blocks'][1];

		$this->assertArrayHasKey( 'name', $second );
		$this->assertSame( 'core/paragraph', $second['name'] );
	}

	public function test_test_mode_heading_contains_prompt_text(): void {
		$result  = Client::compose( 'My unique prompt text', '', [] );
		$heading = $result['blocks'][0];

		$this->assertStringContainsString( 'My unique prompt text', $heading['attributes']['content'] );
	}

	public function test_test_mode_blocks_have_attributes(): void {
		foreach ( $this->dummy_result['blocks'] as $block ) {
			$this->assertArrayHasKey( 'attributes', $block );
			$this->assertIsArray( $block['attributes'] );
		}
	}

	public function test_test_mode_does_not_return_error(): void {
		$this->assertArrayNotHasKey( 'error', $this->dummy_result );
	}

	// =========================================================================
	// strip_json_fences()
	// =========================================================================

	public function test_strip_json_fences_leaves_clean_json_unchanged(): void {
		$json = '{"blocks":[]}';

		$this->assertSame( $json, Client::strip_json_fences( $json ) );
	}

	public function test_strip_json_fences_removes_json_language_fence(): void {
		$fenced = "```json\n{\"blocks\":[]}\n```";

		$this->assertSame( '{"blocks":[]}', Client::strip_json_fences( $fenced ) );
	}

	public function test_strip_json_fences_removes_generic_fence(): void {
		$fenced = "```\n{\"blocks\":[]}\n```";

		$this->assertSame( '{"blocks":[]}', Client::strip_json_fences( $fenced ) );
	}

	public function test_strip_json_fences_handles_fence_with_no_newline(): void {
		$fenced = '```json{"blocks":[]}```';

		$this->assertSame( '{"blocks":[]}', Client::strip_json_fences( $fenced ) );
	}

	public function test_strip_json_fences_trims_surrounding_whitespace(): void {
		$padded = "  \n{\"blocks\":[]}\n  ";

		$this->assertSame( '{"blocks":[]}', Client::strip_json_fences( $padded ) );
	}

	// =========================================================================
	// filter_unknown_blocks()
	// =========================================================================

	private function minimal_catalog(): array {
		return [
			'core/paragraph' => [ 'name' => 'core/paragraph', 'enabled' => true ],
			'core/heading'   => [ 'name' => 'core/heading', 'enabled' => true ],
			'core/column'    => [ 'name' => 'core/column', 'enabled' => true ],
			'core/columns'   => [ 'name' => 'core/columns', 'enabled' => true ],
		];
	}

	public function test_filter_unknown_blocks_keeps_known_blocks(): void {
		$blocks = [
			[ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Hello' ] ],
		];

		$result = Client::filter_unknown_blocks( $blocks, $this->minimal_catalog() );

		$this->assertCount( 1, $result );
		$this->assertSame( 'core/paragraph', $result[0]['name'] );
	}

	public function test_filter_unknown_blocks_removes_unknown_names(): void {
		$blocks = [
			[ 'name' => 'core/paragraph', 'attributes' => [] ],
			[ 'name' => 'fake/invented-block', 'attributes' => [] ],
		];

		$result = Client::filter_unknown_blocks( $blocks, $this->minimal_catalog() );

		$this->assertCount( 1, $result );
		$this->assertSame( 'core/paragraph', $result[0]['name'] );
	}

	public function test_filter_unknown_blocks_returns_empty_when_all_unknown(): void {
		$blocks = [
			[ 'name' => 'invented/one' ],
			[ 'name' => 'invented/two' ],
		];

		$result = Client::filter_unknown_blocks( $blocks, $this->minimal_catalog() );

		$this->assertSame( [], $result );
	}

	public function test_filter_unknown_blocks_validates_inner_blocks_recursively(): void {
		$blocks = [
			[
				'name'        => 'core/columns',
				'attributes'  => [],
				'innerBlocks' => [
					[
						'name'        => 'core/column',
						'attributes'  => [],
						'innerBlocks' => [
							[ 'name' => 'core/paragraph', 'attributes' => [] ],
							[ 'name' => 'fake/block', 'attributes' => [] ],
						],
					],
				],
			],
		];

		$result = Client::filter_unknown_blocks( $blocks, $this->minimal_catalog() );

		$this->assertCount( 1, $result );
		$inner  = $result[0]['innerBlocks'][0]['innerBlocks'];
		$this->assertCount( 1, $inner );
		$this->assertSame( 'core/paragraph', $inner[0]['name'] );
	}

	public function test_filter_unknown_blocks_removes_unknown_inner_blocks_parent(): void {
		$blocks = [
			[
				'name'        => 'fake/container',
				'innerBlocks' => [
					[ 'name' => 'core/paragraph', 'attributes' => [] ],
				],
			],
		];

		// The parent is unknown, so the whole entry (including its children) is dropped.
		$result = Client::filter_unknown_blocks( $blocks, $this->minimal_catalog() );

		$this->assertSame( [], $result );
	}

	public function test_filter_unknown_blocks_handles_empty_array(): void {
		$result = Client::filter_unknown_blocks( [], $this->minimal_catalog() );

		$this->assertSame( [], $result );
	}

	public function test_filter_unknown_blocks_skips_malformed_entries(): void {
		$blocks = [
			'not-an-array',
			[ 'no-name-key' => true ],
			[ 'name' => 'core/paragraph' ],
		];

		$result = Client::filter_unknown_blocks( $blocks, $this->minimal_catalog() );

		$this->assertCount( 1, $result );
		$this->assertSame( 'core/paragraph', $result[0]['name'] );
	}

	// =========================================================================
	// apply_block_attribute_transforms()
	// =========================================================================

	public function test_apply_transforms_returns_blocks_unchanged_with_no_filter(): void {
		$blocks = [
			[ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Hello' ] ],
		];

		$result = Client::apply_block_attribute_transforms( $blocks );

		$this->assertSame( 'Hello', $result[0]['attributes']['content'] );
	}

	public function test_apply_transforms_invokes_registered_filter(): void {
		$callback = static function ( array $attributes, string $block_name ): array {
			if ( 'test/block' === $block_name ) {
				$attributes['extra'] = 'injected';
			}
			return $attributes;
		};
		add_filter( 'kratt_block_attribute_transform', $callback, 10, 2 );

		try {
			$blocks = [
				[ 'name' => 'test/block', 'attributes' => [ 'content' => 'Hi' ] ],
			];

			$result = Client::apply_block_attribute_transforms( $blocks );

			$this->assertSame( 'injected', $result[0]['attributes']['extra'] );
			$this->assertSame( 'Hi', $result[0]['attributes']['content'] );
		} finally {
			remove_filter( 'kratt_block_attribute_transform', $callback, 10 );
		}
	}

	public function test_apply_transforms_does_not_affect_other_blocks(): void {
		$callback = static function ( array $attributes, string $block_name ): array {
			if ( 'target/block' === $block_name ) {
				$attributes['flag'] = true;
			}
			return $attributes;
		};
		add_filter( 'kratt_block_attribute_transform', $callback, 10, 2 );

		try {
			$blocks = [
				[ 'name' => 'target/block', 'attributes' => [] ],
				[ 'name' => 'other/block', 'attributes' => [] ],
			];

			$result = Client::apply_block_attribute_transforms( $blocks );

			$this->assertTrue( $result[0]['attributes']['flag'] );
			$this->assertArrayNotHasKey( 'flag', $result[1]['attributes'] );
		} finally {
			remove_filter( 'kratt_block_attribute_transform', $callback, 10 );
		}
	}

	public function test_apply_transforms_recurses_into_inner_blocks(): void {
		$callback = static function ( array $attributes, string $block_name ): array {
			if ( 'core/paragraph' === $block_name ) {
				$attributes['transformed'] = true;
			}
			return $attributes;
		};
		add_filter( 'kratt_block_attribute_transform', $callback, 10, 2 );

		try {
			$blocks = [
				[
					'name'        => 'core/columns',
					'attributes'  => [],
					'innerBlocks' => [
						[
							'name'        => 'core/column',
							'attributes'  => [],
							'innerBlocks' => [
								[ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'Hi' ] ],
							],
						],
					],
				],
			];

			$result = Client::apply_block_attribute_transforms( $blocks );

			$inner = $result[0]['innerBlocks'][0]['innerBlocks'][0];
			$this->assertTrue( $inner['attributes']['transformed'] );
		} finally {
			remove_filter( 'kratt_block_attribute_transform', $callback, 10 );
		}
	}

	public function test_apply_transforms_handles_block_without_attributes_key(): void {
		$blocks = [
			[ 'name' => 'core/paragraph' ],
		];

		$result = Client::apply_block_attribute_transforms( $blocks );

		$this->assertIsArray( $result[0]['attributes'] );
	}

	public function test_apply_transforms_skips_malformed_entries(): void {
		$blocks = [
			'not-an-array',
			[ 'no-name-key' => true ],
			[ 'name' => 'core/paragraph', 'attributes' => [ 'content' => 'OK' ] ],
		];

		$result = Client::apply_block_attribute_transforms( $blocks );

		$this->assertSame( 'OK', $result[2]['attributes']['content'] );
	}
}
