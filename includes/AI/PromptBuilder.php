<?php

declare( strict_types=1 );

namespace Kratt\AI;

use Kratt\Catalog\BlockCatalog;

class PromptBuilder {

	/**
	 * Builds the system prompt for the AI, including the block catalog and editor context.
	 *
	 * @param array<string, mixed> $catalog                 Available blocks to include in the prompt.
	 * @param string               $editor_content          Current editor content sent as read-only context.
	 * @param string               $additional_instructions Extra instructions appended after the Rules section.
	 */
	public static function build( array $catalog, string $editor_content = '', string $additional_instructions = '' ): string {
		$block_list = BlockCatalog::format_for_prompt( $catalog );

		$editor_section = '' !== $editor_content
			? "The editor currently contains these blocks (read-only \xe2\x80\x94 do not modify existing blocks, only add new ones). Reference them by index to specify where to insert:\n\n" . $editor_content
			: 'The editor is currently empty.';

		$example_success = '{"blocks": [{"name": "core/paragraph", "attributes": {"content": "Hello world"}}, {"name": "core/columns", "attributes": {}, "innerBlocks": [{"name": "core/column", "attributes": {"width": "50%"}, "innerBlocks": [{"name": "core/heading", "attributes": {"content": "Left", "level": 2}}]}, {"name": "core/column", "attributes": {"width": "50%"}, "innerBlocks": [{"name": "core/paragraph", "attributes": {"content": "Right side content."}}]}]}]}';
		$example_positioned = '{"blocks": [{"name": "core/map", "attributes": {}}], "insertBefore": 0}';
		$example_note = '{"blocks": [{"name": "core/map", "attributes": {}}], "note": "The map block was added but no location could be set — pin the address manually in the block settings."}';
		$example_failure = '{"error": "Explanation of why this cannot be done.", "suggestion": "A concrete alternative the user could try."}';

		$rules = implode(
			"\n",
			[
				'1. Use only block names from the Available Blocks list above.',
				'2. Only populate attributes that are plain human-readable text you can generate with certainty: headings, paragraph content, button labels, captions. For everything else — arrays, objects, IDs, URLs, coordinates, media — omit them and let WordPress use the block\'s registered defaults. Exception: when an attribute appears in the "Attributes" line of a block entry with a type and description, it is documented by a registered ability and is safe to set as described. An empty but insertable block is always better than a block with invented or incorrect attribute values.',
				'3. Choose the most semantically appropriate block. "Plain content" or "text" = core/paragraph. "Title" or "heading" = core/heading. Match intent, not exact words.',
				'4. If the site has a custom block that matches the requested concept, prefer it over assembling equivalent layouts from core blocks.',
				"5. For concepts without a dedicated block, assemble from available blocks using these patterns:\n   - Hero section \xe2\x86\x92 core/cover containing core/heading + core/paragraph + core/buttons\n   - FAQ \xe2\x86\x92 core/group containing repeated core/heading (level 3) + core/paragraph pairs\n   - Card grid \xe2\x86\x92 core/columns with core/column blocks, each containing core/image + core/heading + core/paragraph\n   - Call to action \xe2\x86\x92 core/group containing core/heading + core/paragraph + core/buttons\n   - Two-column layout \xe2\x86\x92 core/columns with two core/column blocks (width: \"50%\")\n   - Pricing table \xe2\x86\x92 core/columns with one core/column per pricing tier",
				'6. Container blocks (core/group, core/columns, core/column, core/cover, etc.) must have their child blocks in "innerBlocks".',
				'7. If a request is genuinely impossible with the available blocks, return an error with a concrete suggestion of what the user can do instead.',
				'8. If a request is ambiguous, make the most reasonable assumption and proceed — do not ask clarifying questions.',
			]
		);

		$sections = [
			'You are an assistant embedded in the WordPress Block Editor. Your sole job is to convert natural language requests into a JSON description of WordPress blocks to insert.',
			"## Available Blocks\n\nUse ONLY the blocks listed below \xe2\x80\x94 never invent or guess block names. If no suitable block exists, say so.\n\n" . $block_list,
			"## Current Editor Content\n\n" . $editor_section,
			"## Response Format\n\nAlways respond with a raw JSON object \xe2\x80\x94 no markdown fences, no prose, no explanation outside the JSON.\n\nOn success, return a \"blocks\" array where each entry has:\n- \"name\": the block name (required)\n- \"attributes\": object of attribute values (optional \xe2\x80\x94 omit if empty)\n- \"innerBlocks\": array of nested block specs using the same structure (optional)\n\nBy default, new blocks are appended at the end of the editor. When the request implies a specific position relative to an existing block, add \"insertBefore\" or \"insertAfter\" with the index from the Current Editor Content list.\n\nWhen a block is inserted but a meaningful attribute could not be set (e.g. a map location, an image URL, a video source), add a \"note\" field with a short, plain-English explanation of what the user needs to fill in manually. Only use \"note\" when something important was left empty; omit it otherwise.\n\nExample (append):\n" . $example_success . "\n\nExample (positioned \xe2\x80\x94 insert before block [0]):\n" . $example_positioned . "\n\nExample (with note):\n" . $example_note . "\n\nOn failure or when the request cannot be fulfilled:\n" . $example_failure,
			"## Rules\n\n" . $rules,
		];

		$prompt = implode( "\n\n", $sections );

		if ( '' !== $additional_instructions ) {
			$prompt .= "\n\n## Additional Instructions\n\n" . $additional_instructions;
		}

		return $prompt;
	}

	/**
	 * Builds the system prompt for an AI content review request.
	 *
	 * @param array<string, mixed> $catalog                 Available blocks for context.
	 * @param string               $editor_content          Serialized block markup to review (e.g. output of serialize( blocks )).
	 * @param string               $focus                   Optional review focus from the user.
	 * @param string               $additional_instructions Extra instructions appended to the prompt.
	 */
	public static function build_review( array $catalog, string $editor_content = '', string $focus = '', string $additional_instructions = '' ): string {
		$block_list = BlockCatalog::format_for_prompt( $catalog );

		$editor_section = '' !== $editor_content
			? $editor_content
			: 'The editor is currently empty.';

		$example_findings = '{"findings": [{"type": "structure", "message": "Heading hierarchy skips from H2 to H4.", "block_index": 2, "suggestion": "Change the H4 to H3 to maintain a logical hierarchy."}, {"type": "accessibility", "message": "Image block is missing alt text.", "block_index": 4, "suggestion": "Add descriptive alt text that conveys the image meaning."}]}';

		$sections = [
			'You are a content review assistant embedded in the WordPress Block Editor. Analyse the provided block content and return structured feedback.',
			"## Available Blocks\n\nFor context, the following blocks are registered on this site:\n\n" . $block_list,
			"## Current Editor Content\n\n" . $editor_section,
			"## Review Categories\n\nEvaluate the content across these categories:\n\n- **structure**: Heading hierarchy, logical flow, appropriate use of block types.\n- **accessibility**: Alt text on images, descriptive link and button text (flag generic labels such as \"click here\", \"read more\", or \"here\"), heading levels and hierarchy.\n- **consistency**: Tone, terminology, formatting patterns across the content.",
		];

		$sections[] = "## Response Format\n\nAlways respond with a raw JSON object \xe2\x80\x94 no markdown fences, no prose.\n\nThe object must always contain a \"findings\" array. Each entry in \"findings\" must have:\n- \"type\": one of \"structure\", \"accessibility\", or \"consistency\"\n- \"message\": a short description of the issue\n- \"block_index\": zero-based index of the relevant block (omit if the finding applies to the whole content)\n- \"suggestion\": a concrete, actionable fix\n\nIf there are no findings, respond with this exact JSON: {\"findings\": []}. Never return findings for issues that do not exist.\n\nExample:\n" . $example_findings;

		$prompt = implode( "\n\n", $sections );

		if ( '' !== $focus ) {
			$prompt .= "\n\n## Review Focus\n\n" . $focus;
		}

		if ( '' !== $additional_instructions ) {
			$prompt .= "\n\n## Additional Instructions\n\n" . $additional_instructions;
		}

		return $prompt;
	}
}
