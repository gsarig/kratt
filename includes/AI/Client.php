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
			return self::dummy_response( $user_prompt );
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
		}

		return $decoded;
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
			if ( ! is_array( $block ) || ! isset( $block['name'] ) || ! isset( $catalog[ $block['name'] ] ) ) {
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
	 * Returns a deterministic dummy response for use in KRATT_TEST_MODE.
	 *
	 * @param string $prompt The original user prompt, echoed into the heading.
	 * @return array{blocks: array<array{name: string, attributes: array<string, mixed>}>}
	 */
	private static function dummy_response( string $prompt ): array {
		return [
			'blocks' => [
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
			],
		];
	}
}
