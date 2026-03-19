<?php

declare( strict_types=1 );

namespace Kratt\AI;

class Client {

	/**
	 * Sends a composition request to the AI and returns the result.
	 *
	 * @return array{blocks: array<mixed>}|array{error: string, suggestion?: string}
	 */
	public static function compose( string $user_prompt, string $editor_content, array $catalog ): array {
		if ( defined( 'KRATT_TEST_MODE' ) && KRATT_TEST_MODE ) {
			return self::dummy_response( $user_prompt );
		}

		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return [ 'error' => __( 'WP AI Client is not available. Please install a provider plugin.', 'kratt' ) ];
		}

		$system_prompt = PromptBuilder::build( $catalog, $editor_content );

		$response = wp_ai_client_prompt( $user_prompt )
			->using_system_instruction( $system_prompt )
			->generate_text();

		if ( is_wp_error( $response ) ) {
			return [ 'error' => $response->get_error_message() ];
		}

		if ( ! is_string( $response ) ) {
			return [ 'error' => __( 'Unexpected response from AI provider.', 'kratt' ) ];
		}

		$decoded = json_decode( $response, associative: true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
			return [ 'error' => __( 'The AI returned an unexpected response format.', 'kratt' ) ];
		}

		return $decoded;
	}

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
