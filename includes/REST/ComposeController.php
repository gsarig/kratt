<?php

declare( strict_types=1 );

namespace Kratt\REST;

use Kratt\AI\Client;
use Kratt\Catalog\BlockCatalog;
use Kratt\Catalog\PatternCatalog;
use Kratt\Settings\Settings;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class ComposeController extends WP_REST_Controller {

	protected $namespace = 'kratt/v1';
	protected $rest_base = 'compose';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'create_item_permissions_check' ],
				'args'                => [
					'prompt'         => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $v ) => is_string( $v ) && trim( $v ) !== '',
					],
					'editor_content' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'wp_kses_post',
					],
					'allowed_blocks' => [
						'type'    => 'array',
						'items'   => [ 'type' => 'string' ],
						'default' => null,
					],
					'post_id'        => [
						'type'    => 'integer',
						'default' => 0,
					],
					'post_type'      => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	/**
	 * Checks whether the current user can use the compose endpoint.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return true|\WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to use Kratt.', 'kratt' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Handles a compose request: builds a block list from the user's prompt.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$prompt         = (string) $request->get_param( 'prompt' );
		$editor_content = (string) ( $request->get_param( 'editor_content' ) ?? '' );
		$allowed_blocks = $request->get_param( 'allowed_blocks' );
		$post_id        = (int) $request->get_param( 'post_id' );
		$post_type      = (string) $request->get_param( 'post_type' );

		// Validate post context: if a post ID was supplied, confirm it exists and the current
		// user can edit it. Derive post_type from the post to prevent client spoofing.
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				$post_id   = 0;
				$post_type = '';
			} else {
				$post_type = $post->post_type;
			}
		}

		// Cap editor content to avoid excessive token usage.
		$max_chars = (int) apply_filters( 'kratt_editor_content_max_chars', KRATT_EDITOR_CONTENT_MAX_CHARS );
		if ( mb_strlen( $editor_content, 'UTF-8' ) > $max_chars ) {
			$editor_content = mb_substr( $editor_content, 0, $max_chars, 'UTF-8' ) . '…';
		}

		$catalog = BlockCatalog::get();

		if ( null !== $allowed_blocks ) {
			$catalog = BlockCatalog::filter_by_allowed( $catalog, $allowed_blocks );
		}

		if ( empty( $catalog ) ) {
			return rest_ensure_response(
				[
					'error'      => __( 'No blocks are available. Please run a catalog scan from the Kratt settings page.', 'kratt' ),
					'suggestion' => __( 'Go to Settings → Kratt and click "Rescan Blocks".', 'kratt' ),
				]
			);
		}

		$instructions      = self::resolve_instructions( $post_id, $post_type );
		$max_patterns      = (int) apply_filters( 'kratt_pattern_catalog_max', KRATT_MAX_PATTERNS );
		$preselected       = PatternCatalog::select_for_prompt( PatternCatalog::get_patterns(), (string) $prompt, $max_patterns * 2 );
		$filtered_patterns = PatternCatalog::filter_by_catalog( $preselected, $catalog );
		$patterns          = array_slice( $filtered_patterns, 0, $max_patterns, true );
		$patterns_prompt   = empty( $patterns ) ? '' : PatternCatalog::format_for_prompt( $patterns );
		$result            = Client::compose( $prompt, $editor_content, $catalog, $instructions, $patterns_prompt );

		return rest_ensure_response( $result );
	}

	/**
	 * Entry point for the Abilities API.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public static function compose_from_ability( array $args ): array {
		$catalog = BlockCatalog::get();

		if ( isset( $args['allowed_blocks'] ) && is_array( $args['allowed_blocks'] ) ) {
			$catalog = BlockCatalog::filter_by_allowed( $catalog, $args['allowed_blocks'] );
		}

		$post_id   = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$post_type = isset( $args['post_type'] ) ? (string) $args['post_type'] : '';

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				$post_id   = 0;
				$post_type = '';
			} else {
				$post_type = $post->post_type;
			}
		}

		$user_prompt       = (string) ( $args['prompt'] ?? '' );
		$editor_content    = (string) ( $args['editor_content'] ?? '' );
		$max_patterns      = (int) apply_filters( 'kratt_pattern_catalog_max', KRATT_MAX_PATTERNS );
		$preselected       = PatternCatalog::select_for_prompt( PatternCatalog::get_patterns(), $user_prompt, $max_patterns * 2 );
		$filtered_patterns = PatternCatalog::filter_by_catalog( $preselected, $catalog );
		$patterns          = array_slice( $filtered_patterns, 0, $max_patterns, true );
		$patterns_prompt   = empty( $patterns ) ? '' : PatternCatalog::format_for_prompt( $patterns );

		return Client::compose(
			$user_prompt,
			$editor_content,
			$catalog,
			self::resolve_instructions( $post_id, $post_type ),
			$patterns_prompt
		);
	}

	/**
	 * Resolves the additional instructions to pass to the AI.
	 *
	 * Starts from the saved setting and passes it through the kratt_system_instructions
	 * filter so that code can customise instructions per post type or context.
	 *
	 * @internal Shared with ReviewController. Not a stable public API.
	 *
	 * @param int    $post_id   The current post ID (0 if unsaved).
	 * @param string $post_type The current post type (always available from the editor).
	 * @return string
	 */
	public static function resolve_instructions( int $post_id, string $post_type ): string {
		$instructions = Settings::get_additional_instructions();

		/**
		 * Filters the additional instructions appended to the Kratt AI system prompt.
		 *
		 * @param string $instructions The saved instructions from Settings → Kratt.
		 * @param array{post_id: int, post_type: string} $context {
		 *     Context about the current editing session.
		 *
		 *     @type int    $post_id   The current post ID. 0 for unsaved posts.
		 *     @type string $post_type The current post type slug.
		 * }
		 */
		return (string) apply_filters(
			'kratt_system_instructions',
			$instructions,
			[
				'post_id'   => $post_id,
				'post_type' => $post_type,
			]
		);
	}
}
