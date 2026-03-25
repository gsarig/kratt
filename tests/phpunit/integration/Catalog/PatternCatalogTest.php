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

	public function test_get_patterns_returns_all_described_patterns(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
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

		// All 5 described patterns should be returned; no cap applied here.
		$this->assertGreaterThanOrEqual( 5, count( $result ) );
	}

	// =========================================================================
	// select_for_prompt()
	// =========================================================================

	public function test_select_for_prompt_returns_all_when_within_max(): void {
		$patterns = [
			'ns/hero'    => [ 'name' => 'ns/hero', 'title' => 'Hero', 'description' => 'A hero section.', 'keywords' => [], 'categories' => [] ],
			'ns/pricing' => [ 'name' => 'ns/pricing', 'title' => 'Pricing', 'description' => 'A pricing table.', 'keywords' => [], 'categories' => [] ],
		];

		$result = PatternCatalog::select_for_prompt( $patterns, 'hero section', 10 );

		$this->assertCount( 2, $result );
	}

	public function test_select_for_prompt_ranks_relevant_patterns_first(): void {
		$patterns = [
			'ns/pricing' => [ 'name' => 'ns/pricing', 'title' => 'Pricing Table', 'description' => 'Compare pricing tiers.', 'keywords' => [], 'categories' => [] ],
			'ns/hero'    => [ 'name' => 'ns/hero', 'title' => 'Hero Section', 'description' => 'Full-width hero with heading and button.', 'keywords' => [], 'categories' => [] ],
			'ns/faq'     => [ 'name' => 'ns/faq', 'title' => 'FAQ', 'description' => 'Frequently asked questions accordion.', 'keywords' => [], 'categories' => [] ],
		];

		$result = PatternCatalog::select_for_prompt( $patterns, 'add a hero section', 1 );

		$this->assertArrayHasKey( 'ns/hero', $result );
		$this->assertArrayNotHasKey( 'ns/pricing', $result );
		$this->assertArrayNotHasKey( 'ns/faq', $result );
	}

	public function test_select_for_prompt_returns_results_in_relevance_order(): void {
		$patterns = [
			'ns/pricing' => [ 'name' => 'ns/pricing', 'title' => 'Pricing Table', 'description' => 'Compare pricing tiers.', 'keywords' => [], 'categories' => [] ],
			'ns/high'    => [ 'name' => 'ns/high', 'title' => 'Hero Section', 'description' => 'Full-width hero section with heading.', 'keywords' => [], 'categories' => [] ],
			'ns/mid'     => [ 'name' => 'ns/mid', 'title' => 'Hero Banner', 'description' => 'A simple hero banner.', 'keywords' => [], 'categories' => [] ],
			'ns/gallery' => [ 'name' => 'ns/gallery', 'title' => 'Gallery', 'description' => 'An image gallery.', 'keywords' => [], 'categories' => [] ],
		];

		// Scores for "hero section":
		// ns/high scores 2 (title contains "hero" and "section")
		// ns/mid scores 1 (title contains "hero")
		// ns/pricing and ns/gallery score 0
		$result = PatternCatalog::select_for_prompt( $patterns, 'hero section', 3 );
		$keys   = array_keys( $result );

		$this->assertSame( 'ns/high', $keys[0] );
		$this->assertSame( 'ns/mid', $keys[1] );
	}

	public function test_select_for_prompt_falls_back_to_order_when_prompt_has_no_usable_words(): void {
		$patterns = [
			'ns/a' => [ 'name' => 'ns/a', 'title' => 'A', 'description' => 'First.', 'keywords' => [], 'categories' => [] ],
			'ns/b' => [ 'name' => 'ns/b', 'title' => 'B', 'description' => 'Second.', 'keywords' => [], 'categories' => [] ],
			'ns/c' => [ 'name' => 'ns/c', 'title' => 'C', 'description' => 'Third.', 'keywords' => [], 'categories' => [] ],
		];

		// All words are shorter than 3 characters so scoring is skipped.
		$result = PatternCatalog::select_for_prompt( $patterns, 'a b', 2 );

		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( 'ns/a', $result );
		$this->assertArrayHasKey( 'ns/b', $result );
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
