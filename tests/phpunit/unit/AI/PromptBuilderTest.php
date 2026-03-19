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

		$this->assertStringContainsString( 'omit them entirely', $prompt );
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
}
