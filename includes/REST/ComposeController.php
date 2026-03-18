<?php

declare( strict_types=1 );

namespace Kratt\REST;

use Kratt\AI\Client;
use Kratt\Catalog\BlockCatalog;
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
				],
			]
		);
	}

	public function create_item_permissions_check( WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to use Kratt.', 'kratt' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public function create_item( WP_REST_Request $request ): WP_REST_Response|\WP_Error {
		$prompt         = $request->get_param( 'prompt' );
		$editor_content = $request->get_param( 'editor_content' );
		$allowed_blocks = $request->get_param( 'allowed_blocks' );

		// Cap editor content to avoid excessive token usage.
		if ( strlen( $editor_content ) > 8000 ) {
			$editor_content = substr( $editor_content, 0, 8000 ) . '…';
		}

		$catalog = BlockCatalog::get();

		if ( $allowed_blocks !== null ) {
			$catalog = BlockCatalog::filter_by_allowed( $catalog, $allowed_blocks );
		}

		if ( empty( $catalog ) ) {
			return rest_ensure_response( [
				'error'      => __( 'No blocks are available. Please run a catalog scan from the Kratt settings page.', 'kratt' ),
				'suggestion' => __( 'Go to Settings → Kratt and click "Rescan Blocks".', 'kratt' ),
			] );
		}

		$result = Client::compose( $prompt, $editor_content, $catalog );

		return rest_ensure_response( $result );
	}

	/**
	 * Entry point for the Abilities API.
	 */
	public static function compose_from_ability( array $args ): array {
		$catalog = BlockCatalog::get();

		if ( isset( $args['allowed_blocks'] ) && is_array( $args['allowed_blocks'] ) ) {
			$catalog = BlockCatalog::filter_by_allowed( $catalog, $args['allowed_blocks'] );
		}

		return Client::compose(
			$args['prompt'] ?? '',
			$args['editor_content'] ?? '',
			$catalog
		);
	}
}
