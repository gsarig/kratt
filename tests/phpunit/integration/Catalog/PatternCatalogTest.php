<?php

namespace Kratt\Tests\Integration\Catalog;

use Kratt\Catalog\PatternCatalog;
use WP_UnitTestCase;

/**
 * Integration tests for PatternCatalog.
 *
 * Registers real block patterns against the live WP_Block_Patterns_Registry
 * so that PatternCatalog methods can be tested against actual data.
 */
class PatternCatalogTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $registered_patterns = [];

	protected function setUp(): void {
		parent::setUp();
		$this->registered_patterns = [];
	}

	protected function tearDown(): void {
		foreach ( $this->registered_patterns as $name ) {
			if ( \WP_Block_Patterns_Registry::get_instance()->is_registered( $name ) ) {
				unregister_block_pattern( $name );
			}
		}
		remove_all_filters( 'kratt_pattern_catalog_max' );
		parent::tearDown();
	}

	private function register_test_pattern( string $name, array $args ): void {
		register_block_pattern( $name, $args );
		$this->registered_patterns[] = $name;
	}

	// =========================================================================
	// get_patterns()
	// =========================================================================

	public function test_get_patterns_returns_array(): void {
		$result = PatternCatalog::get_patterns();

		$this->assertIsArray( $result );
	}

	public function test_patterns_without_description_are_skipped(): void {
		$this->register_test_pattern(
			'kratt-test/no-description',
			[
				'title'   => 'No Description Pattern',
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);

		$result = PatternCatalog::get_patterns();

		$this->assertArrayNotHasKey( 'kratt-test/no-description', $result );
	}

	public function test_format_for_prompt_includes_pattern_name(): void {
		$this->register_test_pattern(
			'kratt-test/hero-with-desc',
			[
				'title'       => 'Hero Section',
				'description' => 'A full-width hero with heading and button.',
				'content'     => '<!-- wp:heading --><h2>Hello</h2><!-- /wp:heading -->',
			]
		);

		$patterns = PatternCatalog::get_patterns();
		$output   = PatternCatalog::format_for_prompt( $patterns );

		$this->assertStringContainsString( 'kratt-test/hero-with-desc', $output );
		$this->assertStringContainsString( 'Hero Section', $output );
		$this->assertStringContainsString( 'A full-width hero with heading and button.', $output );
	}

	public function test_format_for_prompt_does_not_include_pattern_content(): void {
		$this->register_test_pattern(
			'kratt-test/with-content',
			[
				'title'       => 'Content Pattern',
				'description' => 'A pattern with block content.',
				'content'     => '<!-- wp:paragraph --><p>SECRET_CONTENT_STRING</p><!-- /wp:paragraph -->',
			]
		);

		$patterns = PatternCatalog::get_patterns();
		$output   = PatternCatalog::format_for_prompt( $patterns );

		$this->assertStringNotContainsString( 'SECRET_CONTENT_STRING', $output );
	}

	public function test_get_patterns_respects_max_cap(): void {
		for ( $i = 1; $i <= 35; $i++ ) {
			$this->register_test_pattern(
				"kratt-test/pattern-{$i}",
				[
					'title'       => "Pattern {$i}",
					'description' => "Description for pattern {$i}.",
					'content'     => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				]
			);
		}

		$result = PatternCatalog::get_patterns();

		$this->assertLessThanOrEqual( 30, count( $result ) );
	}

	public function test_kratt_pattern_catalog_max_filter_is_respected(): void {
		add_filter( 'kratt_pattern_catalog_max', fn() => 3 );

		for ( $i = 1; $i <= 10; $i++ ) {
			$this->register_test_pattern(
				"kratt-test/capped-{$i}",
				[
					'title'       => "Capped Pattern {$i}",
					'description' => "Description for capped pattern {$i}.",
					'content'     => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				]
			);
		}

		$result = PatternCatalog::get_patterns();

		$this->assertLessThanOrEqual( 3, count( $result ) );
	}

	// =========================================================================
	// filter_by_catalog()
	// =========================================================================

	public function test_filter_by_catalog_keeps_patterns_whose_blocks_are_in_catalog(): void {
		$this->register_test_pattern(
			'kratt-test/allowed-pattern',
			[
				'title'       => 'Allowed Pattern',
				'description' => 'A pattern using only allowed blocks.',
				'content'     => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			]
		);

		$patterns = PatternCatalog::get_patterns();
		$catalog  = [ 'core/paragraph' => [ 'name' => 'core/paragraph' ] ];
		$result   = PatternCatalog::filter_by_catalog( $patterns, $catalog );

		$this->assertArrayHasKey( 'kratt-test/allowed-pattern', $result );
	}

	public function test_filter_by_catalog_removes_patterns_with_disallowed_blocks(): void {
		$this->register_test_pattern(
			'kratt-test/blocked-pattern',
			[
				'title'       => 'Blocked Pattern',
				'description' => 'A pattern using a block not in the catalog.',
				'content'     => '<!-- wp:heading --><h2>Title</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			]
		);

		$patterns = PatternCatalog::get_patterns();
		// Catalog has paragraph but not heading.
		$catalog = [ 'core/paragraph' => [ 'name' => 'core/paragraph' ] ];
		$result  = PatternCatalog::filter_by_catalog( $patterns, $catalog );

		$this->assertArrayNotHasKey( 'kratt-test/blocked-pattern', $result );
	}

	public function test_filter_by_catalog_removes_patterns_with_empty_content(): void {
		$patterns = [
			'kratt-test/empty' => [
				'name'        => 'kratt-test/empty',
				'title'       => 'Empty',
				'description' => 'A pattern with no content.',
				'content'     => '',
			],
		];

		$catalog = [ 'core/paragraph' => [ 'name' => 'core/paragraph' ] ];
		$result  = PatternCatalog::filter_by_catalog( $patterns, $catalog );

		$this->assertArrayNotHasKey( 'kratt-test/empty', $result );
	}
}
