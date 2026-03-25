<?php

declare( strict_types=1 );

namespace Kratt\Tests\Integration\AI;

use Kratt\AI\Client;
use WP_UnitTestCase;

/**
 * Integration tests for Client::resolve_pattern().
 *
 * Registers real patterns against the live WP_Block_Patterns_Registry so that
 * the full resolution path (registry lookup, content guard, catalog filtering)
 * can be exercised without making real AI calls.
 */
class ClientPatternTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $registered_patterns = [];

	protected function tearDown(): void {
		foreach ( $this->registered_patterns as $name ) {
			if ( \WP_Block_Patterns_Registry::get_instance()->is_registered( $name ) ) {
				unregister_block_pattern( $name );
			}
		}
		parent::tearDown();
	}

	private function register_test_pattern( string $name, array $args ): void {
		register_block_pattern( $name, $args );
		$this->registered_patterns[] = $name;
	}

	private function minimal_catalog(): array {
		return [
			'core/paragraph' => [ 'name' => 'core/paragraph' ],
			'core/heading'   => [ 'name' => 'core/heading' ],
		];
	}

	// =========================================================================
	// resolve_pattern() — unregistered / missing content
	// =========================================================================

	public function test_resolve_pattern_returns_error_for_unregistered_pattern(): void {
		$result = Client::resolve_pattern( 'kratt-test/does-not-exist', $this->minimal_catalog() );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'pattern_content', $result );
	}

	public function test_resolve_pattern_returns_error_for_pattern_with_empty_content(): void {
		$this->register_test_pattern(
			'kratt-test/empty-content',
			[
				'title'       => 'Empty',
				'description' => 'A pattern with no content.',
				'content'     => '',
			]
		);

		$result = Client::resolve_pattern( 'kratt-test/empty-content', $this->minimal_catalog() );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'pattern_content', $result );
	}

	// =========================================================================
	// resolve_pattern() — catalog enforcement
	// =========================================================================

	public function test_resolve_pattern_returns_content_when_all_blocks_are_in_catalog(): void {
		$this->register_test_pattern(
			'kratt-test/valid-pattern',
			[
				'title'   => 'Valid',
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);

		$result = Client::resolve_pattern( 'kratt-test/valid-pattern', $this->minimal_catalog() );

		$this->assertArrayHasKey( 'pattern_content', $result );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertIsString( $result['pattern_content'] );
		$this->assertNotEmpty( $result['pattern_content'] );
	}

	public function test_resolve_pattern_returns_error_when_all_blocks_are_outside_catalog(): void {
		$this->register_test_pattern(
			'kratt-test/disallowed-blocks',
			[
				'title'   => 'Disallowed',
				'content' => '<!-- wp:image /-->',
			]
		);

		// Catalog has only paragraph and heading; image is outside.
		$result = Client::resolve_pattern( 'kratt-test/disallowed-blocks', $this->minimal_catalog() );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'pattern_content', $result );
	}

	public function test_resolve_pattern_returns_error_when_pattern_has_any_disallowed_block(): void {
		$this->register_test_pattern(
			'kratt-test/mixed-blocks',
			[
				'title'   => 'Mixed',
				'content' => '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading --><!-- wp:image /-->',
			]
		);

		// Catalog allows heading but not image; the whole pattern is rejected.
		$result = Client::resolve_pattern( 'kratt-test/mixed-blocks', $this->minimal_catalog() );

		$this->assertArrayHasKey( 'error', $result );
		$this->assertArrayNotHasKey( 'pattern_content', $result );
	}
}
