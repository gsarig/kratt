<?php

namespace Kratt\Tests\Unit\AI;

use Kratt\AI\PromptBuilder;
use WP_UnitTestCase;

/**
 * Unit tests for PromptBuilder::build().
 *
 * Verifies that the generated system prompt contains the expected sections
 * and that editor content is correctly included or omitted.
 */
class PromptBuilderTest extends WP_UnitTestCase {

	private function minimal_catalog(): array {
		return [
			'core/paragraph' => [
				'name'        => 'core/paragraph',
				'source'      => 'core',
				'enabled'     => true,
				'title'       => 'Paragraph',
				'description' => 'Start with the building block of all narrative.',
				'keywords'    => [],
				'hint'        => '',
				'dynamic'     => false,
				'attributes'  => [],
				'example'     => [],
			],
		];
	}

	public function test_prompt_contains_available_blocks_heading(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog() );

		$this->assertStringContainsString( '## Available Blocks', $prompt );
	}

	public function test_prompt_contains_block_from_catalog(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog() );

		$this->assertStringContainsString( 'core/paragraph', $prompt );
		$this->assertStringContainsString( 'Paragraph', $prompt );
	}

	public function test_prompt_contains_empty_editor_message_when_no_content(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '' );

		$this->assertStringContainsString( 'The editor is currently empty.', $prompt );
	}

	public function test_prompt_contains_editor_content_when_provided(): void {
		$content = '<!-- wp:paragraph --><p>Hello world</p><!-- /wp:paragraph -->';
		$prompt  = PromptBuilder::build( $this->minimal_catalog(), $content );

		$this->assertStringContainsString( $content, $prompt );
		$this->assertStringNotContainsString( 'The editor is currently empty.', $prompt );
	}

	public function test_prompt_contains_read_only_instruction_for_editor_content(): void {
		$content = '<!-- wp:paragraph --><p>Existing</p><!-- /wp:paragraph -->';
		$prompt  = PromptBuilder::build( $this->minimal_catalog(), $content );

		$this->assertStringContainsString( 'read-only', $prompt );
	}

	public function test_prompt_contains_current_editor_content_section(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog() );

		$this->assertStringContainsString( '## Current Editor Content', $prompt );
	}

	public function test_prompt_contains_response_format_section(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog() );

		$this->assertStringContainsString( '## Response Format', $prompt );
	}

	public function test_prompt_contains_blocks_json_format(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog() );

		$this->assertStringContainsString( '"blocks"', $prompt );
		$this->assertStringContainsString( '"name"', $prompt );
	}

	public function test_prompt_contains_error_format(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog() );

		$this->assertStringContainsString( '"error"', $prompt );
		$this->assertStringContainsString( '"suggestion"', $prompt );
	}

	public function test_prompt_contains_rules_section(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog() );

		$this->assertStringContainsString( '## Rules', $prompt );
	}

	public function test_prompt_instructs_not_to_invent_block_names(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog() );

		$this->assertStringContainsString( 'never invent', $prompt );
	}

	public function test_prompt_instructs_to_omit_uncertain_attributes(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog() );

		$this->assertStringContainsString( 'omit them', $prompt );
	}

	public function test_prompt_describes_inner_blocks_usage(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog() );

		$this->assertStringContainsString( 'innerBlocks', $prompt );
	}

	// =========================================================================
	// Additional instructions
	// =========================================================================

	public function test_no_additional_instructions_section_when_empty(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '', '' );

		$this->assertStringNotContainsString( '## Additional Instructions', $prompt );
	}

	public function test_additional_instructions_section_appears_when_provided(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '', 'Avoid tables.' );

		$this->assertStringContainsString( '## Additional Instructions', $prompt );
	}

	public function test_additional_instructions_content_is_included_in_prompt(): void {
		$instructions = 'Prefer core/cover for hero sections. Avoid tables unless presenting product features.';
		$prompt       = PromptBuilder::build( $this->minimal_catalog(), '', $instructions );

		$this->assertStringContainsString( $instructions, $prompt );
	}

	public function test_additional_instructions_appear_after_rules_section(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '', 'Custom rule.' );

		$rules_pos        = strpos( $prompt, '## Rules' );
		$instructions_pos = strpos( $prompt, '## Additional Instructions' );

		$this->assertGreaterThan( $rules_pos, $instructions_pos );
	}

	// =========================================================================
	// Patterns section
	// =========================================================================

	public function test_prompt_contains_patterns_section_when_provided(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '', '', 'my-theme/hero (Hero): A hero pattern.' );

		$this->assertStringContainsString( '## Available Patterns', $prompt );
	}

	public function test_prompt_omits_patterns_section_when_empty(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '', '', '' );

		$this->assertStringNotContainsString( '## Available Patterns', $prompt );
	}

	public function test_patterns_section_appears_after_blocks_section(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '', '', 'my-theme/hero (Hero): A hero pattern.' );

		$blocks_pos   = strpos( $prompt, '## Available Blocks' );
		$patterns_pos = strpos( $prompt, '## Available Patterns' );

		$this->assertNotFalse( $blocks_pos );
		$this->assertNotFalse( $patterns_pos );
		$this->assertGreaterThan( $blocks_pos, $patterns_pos );
	}

	public function test_prompt_contains_pattern_rule_when_patterns_provided(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '', '', 'my-theme/hero (Hero): A hero pattern.' );

		$this->assertStringContainsString( '"pattern"', $prompt );
	}

	public function test_prompt_omits_pattern_rule_when_no_patterns(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '', '', '' );

		$this->assertStringNotContainsString( 'If a registered pattern closely matches', $prompt );
	}

	public function test_response_format_mentions_pattern_alternative_when_patterns_provided(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '', '', 'my-theme/hero (Hero): A hero pattern.' );

		$this->assertStringContainsString( 'pattern-namespace/pattern-name', $prompt );
		$this->assertStringContainsString( 'Available Patterns list', $prompt );
	}

	public function test_response_format_omits_pattern_alternative_when_no_patterns(): void {
		$prompt = PromptBuilder::build( $this->minimal_catalog(), '', '', '' );

		// The pattern-reference format should not appear in the Response Format section.
		$response_format_pos = strpos( $prompt, '## Response Format' );
		$rules_pos           = strpos( $prompt, '## Rules' );
		$response_format     = substr( $prompt, (int) $response_format_pos, (int) $rules_pos - (int) $response_format_pos );

		$this->assertStringNotContainsString( 'pattern-namespace/pattern-name', $response_format );
	}

	// =========================================================================
	// build_review()
	// =========================================================================

	public function test_build_review_contains_available_blocks_section(): void {
		$prompt = PromptBuilder::build_review( $this->minimal_catalog() );

		$this->assertStringContainsString( '## Available Blocks', $prompt );
	}

	public function test_build_review_contains_block_from_catalog(): void {
		$prompt = PromptBuilder::build_review( $this->minimal_catalog() );

		$this->assertStringContainsString( 'core/paragraph', $prompt );
	}

	public function test_build_review_contains_editor_content_when_provided(): void {
		$content = '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->';
		$prompt  = PromptBuilder::build_review( $this->minimal_catalog(), $content );

		$this->assertStringContainsString( $content, $prompt );
	}

	public function test_build_review_shows_empty_message_when_no_content(): void {
		$prompt = PromptBuilder::build_review( $this->minimal_catalog(), '' );

		$this->assertStringContainsString( 'The editor is currently empty.', $prompt );
	}

	public function test_build_review_contains_review_categories_section(): void {
		$prompt = PromptBuilder::build_review( $this->minimal_catalog() );

		$this->assertStringContainsString( '## Review Categories', $prompt );
		$this->assertStringContainsString( 'structure', $prompt );
		$this->assertStringContainsString( 'accessibility', $prompt );
		$this->assertStringContainsString( 'consistency', $prompt );
	}

	public function test_build_review_contains_response_format_section(): void {
		$prompt = PromptBuilder::build_review( $this->minimal_catalog() );

		$this->assertStringContainsString( '## Response Format', $prompt );
		$this->assertStringContainsString( '"findings"', $prompt );
	}

	public function test_build_review_no_focus_section_when_empty(): void {
		$prompt = PromptBuilder::build_review( $this->minimal_catalog(), '', '' );

		$this->assertStringNotContainsString( '## Review Focus', $prompt );
	}

	public function test_build_review_contains_focus_section_when_provided(): void {
		$prompt = PromptBuilder::build_review( $this->minimal_catalog(), '', 'Check accessibility only.' );

		$this->assertStringContainsString( '## Review Focus', $prompt );
		$this->assertStringContainsString( 'Check accessibility only.', $prompt );
	}

	public function test_build_review_no_additional_instructions_when_empty(): void {
		$prompt = PromptBuilder::build_review( $this->minimal_catalog(), '', '', '' );

		$this->assertStringNotContainsString( '## Additional Instructions', $prompt );
	}

	public function test_build_review_contains_additional_instructions_when_provided(): void {
		$prompt = PromptBuilder::build_review( $this->minimal_catalog(), '', '', 'Be concise.' );

		$this->assertStringContainsString( '## Additional Instructions', $prompt );
		$this->assertStringContainsString( 'Be concise.', $prompt );
	}

	public function test_build_review_additional_criteria_appears_after_response_format(): void {
		$prompt = PromptBuilder::build_review( $this->minimal_catalog(), '', '', 'Custom rule.' );

		$criteria_pos        = strpos( $prompt, '## Additional Instructions' );
		$response_format_pos = strpos( $prompt, '## Response Format' );

		$this->assertGreaterThan( 0, $criteria_pos );
		$this->assertGreaterThan( $response_format_pos, $criteria_pos );
	}
}
