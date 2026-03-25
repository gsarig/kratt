<?php

declare( strict_types=1 );

namespace Kratt\AI;

use Kratt\Catalog\PatternCatalog;

class Client {

	/**
	 * Sends a composition request to the AI and returns the result.
	 *
	 * @param string               $user_prompt             The user's natural language request.
	 * @param string               $editor_content          Current editor content for context.
	 * @param array<string, mixed> $catalog                 The block catalog to pass to the AI.
	 * @param string               $additional_instructions Extra instructions appended to the system prompt.
	 * @param string               $patterns_prompt         Formatted pattern list for the prompt; empty when no patterns.
	 * @return array{blocks: array<mixed>}|array{error: string, suggestion?: string}|array{pattern_content: string}
	 */
	public static function compose( string $user_prompt, string $editor_content, array $catalog, string $additional_instructions = '', string $patterns_prompt = '' ): array {
		if ( defined( 'KRATT_TEST_MODE' ) && KRATT_TEST_MODE ) {
			$dummy              = self::dummy_response( $user_prompt );
			$dummy['blocks']    = self::apply_block_attribute_transforms( $dummy['blocks'] );
			return $dummy;
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return [ 'error' => __( 'WP AI Client is not available. Please install a provider plugin.', 'kratt' ) ];
		}

		$system_prompt = PromptBuilder::build( $catalog, $editor_content, $additional_instructions, $patterns_prompt );

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
			$log_message = 'Kratt compose: unexpected AI response format. JSON error: ' . json_last_error_msg();
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$log_message .= '. Raw response: ' . substr( $response, 0, 500 );
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional production logging for malformed AI responses.
			error_log( $log_message );
			return [ 'error' => __( 'The AI returned an unexpected response format.', 'kratt' ) ];
		}

		if ( isset( $decoded['pattern'] ) && is_string( $decoded['pattern'] ) ) {
			return self::resolve_pattern( $decoded['pattern'], $catalog );
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
	 * @param string               $editor_content          Serialized block markup to review (e.g. output of serialize( blocks )).
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

			$final = is_array( $filtered ) ? $filtered : $findings;
			return [ 'findings' => self::filter_invalid_findings( $final ) ];
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
			$log_message = 'Kratt review: unexpected AI response format. JSON error: ' . json_last_error_msg();
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$log_message .= '. Raw response: ' . substr( $response, 0, 500 );
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional production logging for malformed AI responses.
			error_log( $log_message );
			return [
				'findings' => [],
				'error'    => __( 'The AI returned an unexpected response format.', 'kratt' ),
			];
		}

		return [ 'findings' => self::filter_invalid_findings( $decoded['findings'] ) ];
	}

	/**
	 * Filters a findings array to only include well-formed entries.
	 *
	 * Each valid finding must be an array with a string "type" matching one of the
	 * allowed categories and a non-empty string "message". Malformed or unknown entries
	 * are dropped before the response reaches the sidebar.
	 *
	 * @param array<mixed> $findings Raw findings from the AI response.
	 * @return array<int, mixed>
	 */
	public static function filter_invalid_findings( array $findings ): array {
		$allowed_types = [ 'structure', 'accessibility', 'consistency' ];
		$result        = [];

		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			if ( ! isset( $finding['type'] ) || ! is_string( $finding['type'] ) || ! in_array( $finding['type'], $allowed_types, true ) ) {
				continue;
			}
			if ( ! isset( $finding['message'] ) || ! is_string( $finding['message'] ) ) {
				continue;
			}

			$message = trim( $finding['message'] );
			if ( '' === $message ) {
				continue;
			}
			$finding['message'] = $message;

			if ( isset( $finding['suggestion'] ) ) {
				if ( ! is_string( $finding['suggestion'] ) || '' === trim( $finding['suggestion'] ) ) {
					unset( $finding['suggestion'] );
				} else {
					$finding['suggestion'] = trim( $finding['suggestion'] );
				}
			}

			if ( isset( $finding['block_index'] ) ) {
				if ( is_int( $finding['block_index'] ) || ( is_string( $finding['block_index'] ) && ctype_digit( $finding['block_index'] ) ) ) {
					$finding['block_index'] = (int) $finding['block_index'];
				} else {
					unset( $finding['block_index'] );
				}
			}

			$result[] = $finding;
		}

		return $result;
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
	 * Resolves a pattern name to its serialized block content, filtered against the catalog.
	 *
	 * Validates that the pattern is registered and has content, then runs its parsed
	 * blocks through filter_unknown_blocks() so that prompt-injected pattern names
	 * whose blocks fall outside the active catalog cannot bypass allowed_blocks enforcement.
	 *
	 * @param string               $pattern_name The pattern identifier returned by the AI.
	 * @param array<string, mixed> $catalog      The active block catalog, keyed by block name.
	 * @return array{pattern_content: string}|array{error: string, suggestion: string}
	 */
	public static function resolve_pattern( string $pattern_name, array $catalog ): array {
		$registry = \WP_Block_Patterns_Registry::get_instance();

		if ( ! $registry->is_registered( $pattern_name ) ) {
			return [
				'error'      => __( 'The suggested pattern does not exist on this site.', 'kratt' ),
				'suggestion' => __( 'Try describing what you want in more detail so blocks can be assembled instead.', 'kratt' ),
			];
		}

		$pattern = $registry->get_registered( $pattern_name );

		if ( ! is_array( $pattern ) || ! isset( $pattern['content'] ) || ! is_string( $pattern['content'] ) || '' === $pattern['content'] ) {
			return [
				'error'      => __( 'The suggested pattern could not be loaded because it has no content.', 'kratt' ),
				'suggestion' => __( 'Try describing what you want in more detail so blocks can be assembled instead.', 'kratt' ),
			];
		}

		// Guard against prompt injection: validate the pattern's blocks against the active
		// catalog. parse_blocks() output uses 'blockName' (WP format), so we delegate to
		// PatternCatalog::filter_by_catalog() which already handles that format correctly.
		$allowed = PatternCatalog::filter_by_catalog(
			[ $pattern_name => [ 'content' => $pattern['content'] ] ],
			$catalog
		);

		if ( empty( $allowed ) ) {
			return [
				'error'      => __( 'The suggested pattern contains blocks that are not available on this site.', 'kratt' ),
				'suggestion' => __( 'Try describing what you want in more detail so blocks can be assembled instead.', 'kratt' ),
			];
		}

		return [ 'pattern_content' => $pattern['content'] ];
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
