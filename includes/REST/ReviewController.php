<?php

declare( strict_types=1 );

namespace Kratt\REST;

use Kratt\AI\Client;
use Kratt\Catalog\BlockCatalog;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class ReviewController extends WP_REST_Controller {

	protected $namespace = 'kratt/v1';
	protected $rest_base = 'review';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_item' ],
				'permission_callback' => [ $this, 'create_item_permissions_check' ],
				'args'                => [
					'editor_content' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'wp_kses_post',
					],
					'focus'          => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
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
	 * Checks whether the current user can use the review endpoint.
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
	 * Handles a review request: analyses editor content and returns findings.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$editor_content = (string) ( $request->get_param( 'editor_content' ) ?? '' );
		$focus          = (string) ( $request->get_param( 'focus' ) ?? '' );
		$post_id        = (int) $request->get_param( 'post_id' );
		$post_type      = (string) $request->get_param( 'post_type' );

		// Cap editor content to avoid excessive token usage, matching ComposeController.
		$max_chars = (int) apply_filters( 'kratt_editor_content_max_chars', KRATT_EDITOR_CONTENT_MAX_CHARS );
		if ( mb_strlen( $editor_content ) > $max_chars ) {
			$editor_content = mb_substr( $editor_content, 0, $max_chars ) . '…';
		}

		$catalog = BlockCatalog::get();

		if ( empty( $catalog ) ) {
			return rest_ensure_response(
				[
					'error'      => __( 'No blocks are available. Please run a catalog scan from the Kratt settings page.', 'kratt' ),
					'suggestion' => __( 'Go to Settings → Kratt and click "Rescan Blocks".', 'kratt' ),
				]
			);
		}

		$instructions = ComposeController::resolve_instructions( $post_id, $post_type );
		$result       = Client::review( $editor_content, $catalog, $focus, $instructions );

		return rest_ensure_response( $result );
	}
}
