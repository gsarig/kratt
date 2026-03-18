<?php

declare( strict_types=1 );

namespace Kratt\AI;

use WP_Error;

class Client {

	/**
	 * Sends a composition request to the AI and returns the result.
	 *
	 * @return array{markup: string}|array{error: string, suggestion?: string}
	 */
	public static function compose( string $user_prompt, string $editor_content, array $catalog ): array {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return [ 'error' => __( 'WP AI Client is not available. Please install a provider plugin.', 'kratt' ) ];
		}

		$system_prompt = PromptBuilder::build( $catalog, $editor_content );

		/**
		 * wp_ai_client_prompt() is the WP 7.0 AI Client API.
		 * @see https://make.wordpress.org/ai/2025/11/21/introducing-the-wordpress-ai-client-sdk/
		 */
		$response = wp_ai_client_prompt( $user_prompt )
			->using_system_instruction( $system_prompt )
			->as_json_response( [
				'type'       => 'object',
				'properties' => [
					'markup'     => [ 'type' => 'string' ],
					'error'      => [ 'type' => 'string' ],
					'suggestion' => [ 'type' => 'string' ],
				],
			] )
			->send();

		if ( is_wp_error( $response ) ) {
			return [ 'error' => $response->get_error_message() ];
		}

		if ( is_string( $response ) ) {
			$decoded = json_decode( $response, associative: true );
			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $decoded ) ) {
				return [ 'error' => __( 'The AI returned an unexpected response format.', 'kratt' ) ];
			}
			return $decoded;
		}

		if ( is_array( $response ) ) {
			return $response;
		}

		return [ 'error' => __( 'Unexpected response from AI provider.', 'kratt' ) ];
	}
}
