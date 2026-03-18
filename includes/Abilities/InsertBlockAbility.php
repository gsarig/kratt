<?php

declare( strict_types=1 );

namespace Kratt\Abilities;

class InsertBlockAbility {

	/**
	 * Registers the kratt/insert-block ability with the WP Abilities API (WP 7.0+).
	 * This makes the block insertion capability discoverable by other AI agents and MCP tools.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'kratt/insert-block',
			[
				'label'               => __( 'Insert a WordPress block', 'kratt' ),
				'description'         => __( 'Inserts one or more blocks into the editor from a natural language prompt.', 'kratt' ),
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'prompt'         => [
							'type'        => 'string',
							'description' => 'Natural language description of the blocks to insert.',
						],
						'editor_content' => [
							'type'        => 'string',
							'description' => 'Current editor content as serialized block markup (read-only context).',
						],
						'allowed_blocks' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => 'List of allowed block names in the current editor context.',
						],
					],
					'required'   => [ 'prompt' ],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'markup'     => [ 'type' => 'string' ],
						'error'      => [ 'type' => 'string' ],
						'suggestion' => [ 'type' => 'string' ],
					],
				],
				'callback'            => [ \Kratt\REST\ComposeController::class, 'compose_from_ability' ],
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
			]
		);
	}
}
