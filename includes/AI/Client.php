<?php

declare( strict_types=1 );

namespace Kratt\AI;

class Client {

	/**
	 * Sends a composition request to the AI and returns the result.
	 *
	 * @param string               $user_prompt             The user's natural language request.
	 * @param string               $editor_content          Current editor content for context.
	 * @param array<string, mixed> $catalog                 The block catalog to pass to the AI.
	 * @param string               $additional_instructions Extra instructions appended to the system prompt.
	 * @return array{blocks: array<mixed>}|array{error: string, suggestion?: string}
	 */
	public static function compose( string $user_prompt, string $editor_content, array $catalog, string $additional_instructions = '' ): array {
		if ( defined( 'KRATT_TEST_MODE' ) && KRATT_TEST_MODE ) {
			$dummy              = self::dummy_response( $user_prompt );
			$dummy['blocks']    = self::apply_block_attribute_transforms( $dummy['blocks'] );
			return $dummy;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return [ 'error' => __( 'WP AI Client is not available. Please install a provider plugin.', 'kratt' ) ];
		}

		$system_prompt = PromptBuilder::build( $catalog, $editor_content, $additional_instructions );

		$response = wp_ai_client_prompt( $user_prompt )
			->using_system_instruction( $system_prompt )
			->generate_text();

		if ( is_wp_error( $response ) ) {
			return [ 'error' => $response->get_error_message() ];
		}

		if ( ! is_string( $response ) ) {
			return [ 'error' => __( 'Unexpected response from AI provider.', 'kratt' ) ];
		}

		$decoded = json_decode( self::strip_json_fences( $response ), associative: true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return [ 'error' => __( 'The AI returned an unexpected response format.', 'kratt' ) ];
		}

		if ( isset( $decoded['blocks'] ) && is_array( $decoded['blocks'] ) ) {
			$decoded['blocks'] = self::filter_unknown_blocks( $decoded['blocks'], $catalog );
			$decoded['blocks'] = self::apply_block_attribute_transforms( $decoded['blocks'] );
		}

		return $decoded;
	}

	/**
	 * Sends a review request to the AI and returns findings.
	 *
	 * @param string               $editor_content          The rich block summary from the editor.
	 * @param array<string, mixed> $catalog                 The block catalog for context.
	 * @param string               $focus                   Optional review focus prompt.
	 * @param string               $additional_instructions Extra instructions from settings/filter.
	 * @return array{findings: array<mixed>}|array{findings: array<mixed>, error: string}
	 */
	public static function review(
		string $editor_content,
		array $catalog,
		string $focus = '',
		string $additional_instructions = ''
	): array {
		if ( defined( 'KRATT_TEST_MODE' ) && KRATT_TEST_MODE ) {
			$findings = [
				[
					'type'        => 'structure',
					'message'     => 'Heading hierarchy gap detected.',
					'block_index' => 0,
					'suggestion'  => 'Review heading levels for proper nesting.',
				],
				[
					'type'        => 'accessibility',
					'message'     => 'Image is missing alt text.',
					'block_index' => 1,
					'suggestion'  => 'Add descriptive alt text for screen readers.',
				],
			];

			/**
			 * Filters the dummy findings returned in KRATT_TEST_MODE.
			 *
			 * Only fires when KRATT_TEST_MODE is true. Use this to test how
			 * specific findings are rendered without making real AI calls.
			 */
			$filtered = apply_filters( 'kratt_dummy_review_response', $findings, $editor_content );

			return [ 'findings' => is_array( $filtered ) ? $filtered : $findings ];
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return [
				'findings' => [],
				'error'    => __( 'WP AI Client is not available. Please install a provider plugin.', 'kratt' ),
			];
		}

		$system_prompt = PromptBuilder::build_review( $catalog, $editor_content, $focus, $additional_instructions );
		$user_prompt   = '' !== $focus ? $focus : __( 'Review the content.', 'kratt' );

		$response = wp_ai_client_prompt( $user_prompt )
			->using_system_instruction( $system_prompt )
			->generate_text();

		if ( is_wp_error( $response ) ) {
			return [
				'findings' => [],
				'error'    => $response->get_error_message(),
			];
		}

		if ( ! is_string( $response ) ) {
			return [
				'findings' => [],
				'error'    => __( 'Unexpected response from AI provider.', 'kratt' ),
			];
		}

		$decoded = json_decode( self::strip_json_fences( $response ), associative: true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) || ! isset( $decoded['findings'] ) || ! is_array( $decoded['findings'] ) ) {
			return [
				'findings' => [],
				'error'    => __( 'The AI returned an unexpected response format.', 'kratt' ),
			];
		}

		return [ 'findings' => $decoded['findings'] ];
	}

	/**
	 * Strips markdown code fences from an AI response string.
	 *
	 * Some models wrap JSON in ```json ... ``` or ``` ... ``` fences despite
	 * being instructed not to. This normalises the response before JSON decoding.
	 *
	 * @param string $response Raw string from the AI provider.
	 * @return string
	 */
	public static function strip_json_fences( string $response ): string {
		$response = trim( $response );
		$response = preg_replace( '/^```(?:json)?\s*\n?/', '', $response ) ?? $response;
		$response = preg_replace( '/\n?```\s*$/', '', $response ) ?? $response;
		return trim( $response );
	}

	/**
	 * Recursively filters a blocks array to only include names present in the catalog.
	 *
	 * Prevents unknown or hallucinated block names from reaching the editor.
	 * innerBlocks are validated with the same catalog.
	 *
	 * @param array<int, mixed>    $blocks  Blocks from the AI response.
	 * @param array<string, mixed> $catalog The block catalog, keyed by block name.
	 * @return array<int, mixed>
	 */
	public static function filter_unknown_blocks( array $blocks, array $catalog ): array {
		$valid = [];

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || ! isset( $block['name'] ) || ! is_string( $block['name'] ) || ! isset( $catalog[ $block['name'] ] ) ) {
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::filter_unknown_blocks( $block['innerBlocks'], $catalog );
			}

			$valid[] = $block;
		}

		return $valid;
	}

	/**
	 * Applies the kratt_block_attribute_transform filter to each block's attributes.
	 *
	 * Runs after filter_unknown_blocks(). Allows built-in and third-party handlers
	 * to convert AI-output attributes into the real block attribute format before
	 * blocks reach the editor (e.g. lat/lng → bounds for map blocks).
	 *
	 * @param array<int, mixed> $blocks Blocks from the AI response.
	 * @return array<int, mixed>
	 */
	public static function apply_block_attribute_transforms( array $blocks ): array {
		foreach ( $blocks as &$block ) {
			if ( ! is_array( $block ) || ! isset( $block['name'] ) || ! is_string( $block['name'] ) ) {
				continue;
			}

			$attributes = $block['attributes'] ?? [];
			if ( ! is_array( $attributes ) ) {
				$attributes = [];
			}

			$filtered = apply_filters( 'kratt_block_attribute_transform', $attributes, $block['name'] );

			$block['attributes'] = is_array( $filtered ) ? $filtered : $attributes;

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::apply_block_attribute_transforms( $block['innerBlocks'] );
			}
		}
		unset( $block );

		return $blocks;
	}

	/**
	 * Returns a deterministic dummy response for use in KRATT_TEST_MODE.
	 *
	 * @param string $prompt The original user prompt, echoed into the heading.
	 * @return array{blocks: array<mixed>}
	 */
	private static function dummy_response( string $prompt ): array {
		$blocks = [
			[
				'name'       => 'core/heading',
				'attributes' => [
					'level'   => 2,
					'content' => 'Test mode: "' . $prompt . '"',
				],
			],
			[
				'name'       => 'core/paragraph',
				'attributes' => [
					'content' => 'This is a dummy response from Kratt test mode. No tokens were used.',
				],
			],
		];

		$filtered = apply_filters( 'kratt_dummy_response', $blocks, $prompt );

		return [
			'blocks' => is_array( $filtered ) ? $filtered : $blocks,
		];
	}
}
