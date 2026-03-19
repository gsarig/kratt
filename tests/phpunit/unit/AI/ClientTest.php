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
}
