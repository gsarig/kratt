<?php

namespace Kratt\Tests\Integration\REST;

use Kratt\Catalog\BlockCatalog;
use Kratt\REST\CatalogController;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Integration tests for CatalogController.
 *
 * Covers: permissions, GET /catalog response shape, POST /catalog/rescan
 * response shape, and that rescan actually updates the stored catalog.
 */
class CatalogControllerTest extends WP_UnitTestCase {

	private CatalogController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new CatalogController();
		BlockCatalog::scan();
	}

	protected function tearDown(): void {
		delete_option( 'kratt_block_catalog' );
		delete_option( 'kratt_catalog_scanned_at' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// =========================================================================
	// Permissions
	// =========================================================================

	public function test_unauthenticated_user_is_rejected(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/kratt/v1/catalog' );
		$result  = $this->controller->permissions_check( $request );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_editor_without_manage_options_is_rejected(): void {
		$editor = $this->factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$request = new WP_REST_Request( 'GET', '/kratt/v1/catalog' );
		$result  = $this->controller->permissions_check( $request );

		$this->assertWPError( $result );
	}

	public function test_administrator_passes_permissions(): void {
		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'GET', '/kratt/v1/catalog' );
		$result  = $this->controller->permissions_check( $request );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// GET /catalog
	// =========================================================================

	public function test_get_items_returns_blocks_key(): void {
		$request  = new WP_REST_Request( 'GET', '/kratt/v1/catalog' );
		$response = $this->controller->get_items( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'blocks', $data );
	}

	public function test_get_items_returns_scanned_at_key(): void {
		$request  = new WP_REST_Request( 'GET', '/kratt/v1/catalog' );
		$response = $this->controller->get_items( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'scanned_at', $data );
	}

	public function test_get_items_blocks_is_array(): void {
		$request  = new WP_REST_Request( 'GET', '/kratt/v1/catalog' );
		$response = $this->controller->get_items( $request );
		$data     = $response->get_data();

		$this->assertIsArray( $data['blocks'] );
	}

	public function test_get_items_returns_stored_catalog(): void {
		$request  = new WP_REST_Request( 'GET', '/kratt/v1/catalog' );
		$response = $this->controller->get_items( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'core/paragraph', $data['blocks'] );
	}

	// =========================================================================
	// POST /catalog/rescan
	// =========================================================================

	public function test_rescan_returns_message_key(): void {
		$request  = new WP_REST_Request( 'POST', '/kratt/v1/catalog/rescan' );
		$response = $this->controller->rescan( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'message', $data );
	}

	public function test_rescan_returns_blocks_key(): void {
		$request  = new WP_REST_Request( 'POST', '/kratt/v1/catalog/rescan' );
		$response = $this->controller->rescan( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'blocks', $data );
	}

	public function test_rescan_returns_scanned_at_key(): void {
		$request  = new WP_REST_Request( 'POST', '/kratt/v1/catalog/rescan' );
		$response = $this->controller->rescan( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'scanned_at', $data );
	}

	public function test_rescan_message_contains_block_count(): void {
		$request  = new WP_REST_Request( 'POST', '/kratt/v1/catalog/rescan' );
		$response = $this->controller->rescan( $request );
		$data     = $response->get_data();

		$count = count( $data['blocks'] );
		$this->assertStringContainsString( (string) $count, $data['message'] );
	}

	public function test_rescan_updates_catalog_in_database(): void {
		// Clear the catalog, then rescan and verify it's repopulated.
		delete_option( 'kratt_block_catalog' );
		$this->assertEmpty( BlockCatalog::get() );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/catalog/rescan' );
		$this->controller->rescan( $request );

		$this->assertNotEmpty( BlockCatalog::get() );
	}
}
