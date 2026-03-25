<?php

namespace Kratt\Tests\Integration\REST;

use Kratt\Catalog\BlockCatalog;
use Kratt\REST\ReviewController;
use Kratt\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Integration tests for ReviewController.
 *
 * Uses KRATT_TEST_MODE (defined in bootstrap) so review calls return a
 * deterministic dummy response without real AI API calls.
 *
 * Covers: permissions, response structure, focus param, and the
 * kratt_system_instructions filter integration.
 */
class ReviewControllerTest extends WP_UnitTestCase {

	private ReviewController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new ReviewController();
		BlockCatalog::scan();
	}

	protected function tearDown(): void {
		delete_option( 'kratt_block_catalog' );
		delete_option( 'kratt_catalog_scanned_at' );
		delete_option( 'kratt_additional_instructions' );
		remove_all_filters( 'kratt_system_instructions' );
		remove_all_filters( 'kratt_editor_content_max_chars' );
		remove_all_filters( 'kratt_dummy_review_response' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// =========================================================================
	// Permissions
	// =========================================================================

	public function test_unauthenticated_user_is_rejected(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		$result  = $this->controller->create_item_permissions_check( $request );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_subscriber_without_edit_posts_is_rejected(): void {
		$subscriber = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		$result  = $this->controller->create_item_permissions_check( $request );

		$this->assertWPError( $result );
	}

	public function test_editor_passes_permissions(): void {
		$editor = $this->factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		$result  = $this->controller->create_item_permissions_check( $request );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// Empty catalog guard
	// =========================================================================

	public function test_empty_catalog_returns_error_with_suggestion(): void {
		Settings::save_catalog( [] );

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );

		$response = $this->controller->create_item( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertArrayHasKey( 'suggestion', $data );
	}

	// =========================================================================
	// Response structure
	// =========================================================================

	public function test_review_returns_findings_key(): void {
		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		$request->set_param( 'editor_content', '[0] core/paragraph: "Hello world"' );

		$response = $this->controller->create_item( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'findings', $data );
		$this->assertIsArray( $data['findings'] );
	}

	public function test_review_with_empty_editor_content_returns_findings(): void {
		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		// editor_content defaults to '' — still valid in test mode.

		$response = $this->controller->create_item( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'findings', $data );
	}

	// =========================================================================
	// focus param
	// =========================================================================

	public function test_review_accepts_focus_param(): void {
		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		$request->set_param( 'editor_content', '[0] core/paragraph: "Hello world"' );
		$request->set_param( 'focus', 'Check accessibility only.' );

		$response = $this->controller->create_item( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'findings', $data );
		$this->assertIsArray( $data['findings'] );
	}

	// =========================================================================
	// kratt_system_instructions filter
	// =========================================================================

	public function test_kratt_system_instructions_filter_is_applied_on_review(): void {
		$received = null;

		add_filter(
			'kratt_system_instructions',
			function ( $instructions ) use ( &$received ) {
				$received = $instructions;
				return $instructions;
			}
		);

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		$this->controller->create_item( $request );

		$this->assertNotNull( $received );
	}

	public function test_kratt_system_instructions_filter_receives_saved_value(): void {
		update_option( 'kratt_additional_instructions', 'Focus on accessibility.' );
		$received = null;

		add_filter(
			'kratt_system_instructions',
			function ( $instructions ) use ( &$received ) {
				$received = $instructions;
				return $instructions;
			}
		);

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		$this->controller->create_item( $request );

		$this->assertSame( 'Focus on accessibility.', $received );
	}

	public function test_review_passes_post_type_to_instructions_filter(): void {
		$received_context = null;

		add_filter(
			'kratt_system_instructions',
			function ( $instructions, $context ) use ( &$received_context ) {
				$received_context = $context;
				return $instructions;
			},
			10,
			2
		);

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		$request->set_param( 'post_type', 'product' );
		$this->controller->create_item( $request );

		$this->assertIsArray( $received_context );
		$this->assertSame( 'product', $received_context['post_type'] );
	}

	public function test_review_passes_post_id_to_instructions_filter(): void {
		$received_context = null;

		add_filter(
			'kratt_system_instructions',
			function ( $instructions, $context ) use ( &$received_context ) {
				$received_context = $context;
				return $instructions;
			},
			10,
			2
		);

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$post_id = $this->factory()->post->create();

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		$request->set_param( 'post_id', $post_id );
		$this->controller->create_item( $request );

		$this->assertSame( $post_id, $received_context['post_id'] );
	}

	// =========================================================================
	// Editor content capping
	// =========================================================================

	public function test_kratt_editor_content_max_chars_filter_is_respected(): void {
		add_filter( 'kratt_editor_content_max_chars', fn() => 10 );

		$captured_content = null;
		add_filter(
			'kratt_dummy_review_response',
			function ( array $findings, string $editor_content ) use ( &$captured_content ) {
				$captured_content = $editor_content;
				return $findings;
			},
			10,
			2
		);

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/review' );
		$request->set_param( 'editor_content', str_repeat( 'x', 100 ) );

		$this->controller->create_item( $request );

		// Content truncated to 10 chars + ellipsis; 11+ consecutive 'x' chars must not appear.
		$this->assertNotNull( $captured_content );
		$this->assertStringNotContainsString( str_repeat( 'x', 11 ), $captured_content );
		$this->assertStringEndsWith( '…', $captured_content );
	}
}
