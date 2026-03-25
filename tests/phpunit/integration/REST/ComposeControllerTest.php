<?php

namespace Kratt\Tests\Integration\REST;

use Kratt\Catalog\BlockCatalog;
use Kratt\REST\ComposeController;
use Kratt\Settings\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Integration tests for ComposeController.
 *
 * Uses KRATT_TEST_MODE (defined in bootstrap) so compose calls return a
 * deterministic dummy response without real AI API calls.
 *
 * Covers: permissions, empty catalog guard, editor content capping,
 * allowed_blocks filtering, and the compose_from_ability() entry point.
 */
class ComposeControllerTest extends WP_UnitTestCase {

	private ComposeController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new ComposeController();
		BlockCatalog::scan();
	}

	protected function tearDown(): void {
		delete_option( 'kratt_block_catalog' );
		delete_option( 'kratt_catalog_scanned_at' );
		delete_option( 'kratt_additional_instructions' );
		remove_all_filters( 'kratt_system_instructions' );
		remove_all_filters( 'kratt_editor_content_max_chars' );
		remove_all_filters( 'kratt_dummy_response' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	// =========================================================================
	// Permissions
	// =========================================================================

	public function test_unauthenticated_user_is_rejected(): void {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$result  = $this->controller->create_item_permissions_check( $request );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_subscriber_without_edit_posts_is_rejected(): void {
		$subscriber = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$result  = $this->controller->create_item_permissions_check( $request );

		$this->assertWPError( $result );
	}

	public function test_editor_with_edit_posts_passes_permissions(): void {
		$editor = $this->factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$result  = $this->controller->create_item_permissions_check( $request );

		$this->assertTrue( $result );
	}

	public function test_administrator_passes_permissions(): void {
		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$result  = $this->controller->create_item_permissions_check( $request );

		$this->assertTrue( $result );
	}

	// =========================================================================
	// Empty catalog guard
	// =========================================================================

	public function test_empty_catalog_returns_error_with_suggestion(): void {
		// Clear the catalog to simulate a fresh install with no scan yet.
		Settings::save_catalog( [] );

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a paragraph.' );

		$response = $this->controller->create_item( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertArrayHasKey( 'suggestion', $data );
	}

	// =========================================================================
	// Editor content capping
	// =========================================================================

	public function test_editor_content_longer_than_8000_chars_is_accepted_without_error(): void {
		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a paragraph.' );
		$request->set_param( 'editor_content', str_repeat( 'x', 9000 ) );

		$response = $this->controller->create_item( $request );
		$data     = $response->get_data();

		// With test mode, should get a blocks response, not an error about content length.
		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertArrayHasKey( 'blocks', $data );
	}

	public function test_kratt_editor_content_max_chars_filter_is_invoked_with_constant(): void {
		// In KRATT_TEST_MODE, Client::compose() ignores editor_content entirely, so there
		// is no observable truncation effect on the compose side. What we CAN verify is that
		// apply_filters( 'kratt_editor_content_max_chars', KRATT_EDITOR_CONTENT_MAX_CHARS )
		// is called with the right default — confirming the filter is wired up correctly.
		// Truncation effect is verified in ReviewControllerTest via kratt_dummy_review_response,
		// which does receive the (possibly truncated) editor_content string.
		$received_value = null;
		add_filter(
			'kratt_editor_content_max_chars',
			function ( int $value ) use ( &$received_value ): int {
				$received_value = $value;
				return $value;
			}
		);

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a paragraph.' );
		$request->set_param( 'editor_content', str_repeat( 'x', 100 ) );

		$this->controller->create_item( $request );

		$this->assertSame( KRATT_EDITOR_CONTENT_MAX_CHARS, $received_value );
	}

	// =========================================================================
	// Allowed blocks filtering
	// =========================================================================

	public function test_allowed_blocks_limits_catalog_passed_to_client(): void {
		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		// Use a list containing only a block that doesn't exist — this empties the catalog.
		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add something.' );
		$request->set_param( 'allowed_blocks', [ 'nonexistent/block-xyz' ] );

		$response = $this->controller->create_item( $request );
		$data     = $response->get_data();

		// After filtering, catalog is empty; controller returns the empty-catalog error.
		$this->assertArrayHasKey( 'error', $data );
	}

	public function test_null_allowed_blocks_uses_full_catalog(): void {
		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a heading.' );
		// allowed_blocks defaults to null — full catalog is used.

		$response = $this->controller->create_item( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'blocks', $data );
	}

	// =========================================================================
	// compose_from_ability()
	// =========================================================================

	public function test_compose_from_ability_returns_blocks(): void {
		$result = ComposeController::compose_from_ability( [ 'prompt' => 'Add a heading.' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
	}

	public function test_compose_from_ability_with_empty_prompt_returns_response(): void {
		$result = ComposeController::compose_from_ability( [] );

		// Missing prompt results in Client::compose() receiving an empty string.
		// In test mode this still returns the dummy blocks.
		$this->assertIsArray( $result );
	}

	public function test_compose_from_ability_filters_allowed_blocks(): void {
		// If allowed_blocks contains only nonexistent blocks, the catalog is empty
		// and Client receives an empty catalog — still returns dummy blocks in test mode
		// but the catalog passed in is empty. We verify no exception is thrown.
		$result = ComposeController::compose_from_ability( [
			'prompt'         => 'Add something.',
			'allowed_blocks' => [ 'nonexistent/block' ],
		] );

		$this->assertIsArray( $result );
	}

	// =========================================================================
	// Additional instructions — filter hook
	// =========================================================================

	public function test_kratt_system_instructions_filter_is_applied(): void {
		$received_instructions = null;

		add_filter(
			'kratt_system_instructions',
			function ( $instructions ) use ( &$received_instructions ) {
				$received_instructions = $instructions;
				return $instructions;
			}
		);

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a heading.' );
		$this->controller->create_item( $request );

		$this->assertNotNull( $received_instructions );
	}

	public function test_kratt_system_instructions_filter_receives_saved_value(): void {
		update_option( 'kratt_additional_instructions', 'Avoid tables.' );
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

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a heading.' );
		$this->controller->create_item( $request );

		$this->assertSame( 'Avoid tables.', $received );
	}

	public function test_kratt_system_instructions_filter_receives_post_type_in_context(): void {
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

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a heading.' );
		$request->set_param( 'post_type', 'product' );
		$this->controller->create_item( $request );

		$this->assertIsArray( $received_context );
		$this->assertSame( 'product', $received_context['post_type'] );
	}

	public function test_kratt_system_instructions_filter_receives_post_id_in_context(): void {
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

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a heading.' );
		$request->set_param( 'post_id', $post_id );
		$this->controller->create_item( $request );

		$this->assertSame( $post_id, $received_context['post_id'] );
	}

	public function test_filter_return_value_overrides_saved_instructions(): void {
		update_option( 'kratt_additional_instructions', 'Original instruction.' );

		// The filter completely replaces the saved value.
		add_filter(
			'kratt_system_instructions',
			static fn() => 'Override instruction.'
		);

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a heading.' );
		$response = $this->controller->create_item( $request );

		// Test mode ignores instructions content but the request should complete without error.
		$this->assertArrayHasKey( 'blocks', $response->get_data() );
	}

	public function test_nonexistent_post_id_is_rejected_and_context_falls_back_to_zero(): void {
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

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a heading.' );
		$request->set_param( 'post_id', 999999 );
		$this->controller->create_item( $request );

		$this->assertSame( 0, $received_context['post_id'] );
		$this->assertSame( '', $received_context['post_type'] );
	}

	public function test_post_type_is_derived_from_post_not_client(): void {
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

		$admin   = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$post_id = $this->factory()->post->create( [ 'post_type' => 'post' ] );

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a heading.' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'post_type', 'spoofed_type' );
		$this->controller->create_item( $request );

		$this->assertSame( 'post', $received_context['post_type'] );
	}

	public function test_unsaved_post_sends_zero_post_id_in_context(): void {
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

		$request = new WP_REST_Request( 'POST', '/kratt/v1/compose' );
		$request->set_param( 'prompt', 'Add a heading.' );
		// post_id defaults to 0 when not sent (unsaved post).
		$this->controller->create_item( $request );

		$this->assertSame( 0, $received_context['post_id'] );
	}
}
