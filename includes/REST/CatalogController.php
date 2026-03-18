<?php

declare( strict_types=1 );

namespace Kratt\REST;

use Kratt\Catalog\BlockCatalog;
use Kratt\Settings\Settings;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class CatalogController extends WP_REST_Controller {

	protected $namespace = 'kratt/v1';
	protected $rest_base = 'catalog';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/rescan',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rescan' ],
				'permission_callback' => [ $this, 'permissions_check' ],
			]
		);
	}

	public function permissions_check( WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to manage the Kratt catalog.', 'kratt' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public function get_items( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response( [
			'blocks'     => BlockCatalog::get(),
			'scanned_at' => Settings::get_catalog_scanned_at(),
		] );
	}

	public function rescan( WP_REST_Request $request ): WP_REST_Response {
		BlockCatalog::scan();
		$catalog = BlockCatalog::get();

		return rest_ensure_response( [
			'message'    => sprintf(
				/* translators: %d: number of blocks found */
				__( 'Catalog updated. %d blocks found.', 'kratt' ),
				count( $catalog )
			),
			'blocks'     => $catalog,
			'scanned_at' => Settings::get_catalog_scanned_at(),
		] );
	}
}
