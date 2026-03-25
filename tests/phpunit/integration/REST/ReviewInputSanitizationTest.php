<?php

namespace Kratt\Tests\Integration\REST;

use Kratt\Catalog\BlockCatalog;
use Kratt\REST\ReviewController;
use WP_UnitTestCase;

/**
 * Security-focused tests for /kratt/v1/review input sanitization.
 *
 * All requests go through rest_get_server()->dispatch() so that
 * sanitize_callback and validate_callback actually fire, the same as
 * they would in a real HTTP request. Testing via the controller method
 * directly bypasses that layer and would not catch misconfigured callbacks.
 */
class ReviewInputSanitizationTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		BlockCatalog::scan();

		// Plugin::init() is gated on wp_ai_client_prompt existing, which is absent
		// in the test environment, so the rest_api_init action is never added.
		// Reset the global server so rest_api_init fires fresh, then hook into it
		// to register our routes the correct way before calling rest_get_server().
		global $wp_rest_server;
		$wp_rest_server = null;

		add_action(
			'rest_api_init',
			static function () {
				( new ReviewController() )->register_routes();
			}
		);

		rest_get_server();

		$admin = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
	}

	protected function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		delete_option( 'kratt_block_catalog' );
		delete_option( 'kratt_catalog_scanned_at' );
		remove_all_filters( 'kratt_system_instructions' );
		remove_all_filters( 'kratt_dummy_review_response' );
		remove_all_actions( 'rest_api_init' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Dispatches a POST request through the full REST server stack.
	 *
	 * @param string               $path   REST route path.
	 * @param array<string, mixed> $params Request body parameters.
	 * @return \WP_REST_Response
	 */
	private function post( string $path, array $params ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', $path );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		return rest_get_server()->dispatch( $request );
	}

	// =========================================================================
	// editor_content — wp_kses_post strips disallowed tags
	// =========================================================================

	public function test_editor_content_with_script_tag_does_not_cause_server_error(): void {
		// wp_kses_post strips <script>. The request should succeed with findings,
		// not a 500. The AI (test mode) never sees the raw HTML.
		$response = $this->post( '/kratt/v1/review', [
			'editor_content' => '<script>evil()</script>[0] core/paragraph: "Hello"',
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'findings', $response->get_data() );
	}

	public function test_editor_content_with_iframe_does_not_cause_server_error(): void {
		$response = $this->post( '/kratt/v1/review', [
			'editor_content' => '<iframe src="https://evil.example"></iframe>',
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'findings', $response->get_data() );
	}

	// =========================================================================
	// focus — sanitize_text_field strips HTML tags
	// =========================================================================

	public function test_focus_with_html_does_not_cause_server_error(): void {
		// sanitize_text_field strips tags but keeps text content, so the request
		// succeeds — it is not treated as invalid input, just cleaned.
		$response = $this->post( '/kratt/v1/review', [
			'focus' => '<script>alert("xss")</script>Check accessibility.',
		] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'findings', $response->get_data() );
	}

	// =========================================================================
	// post_type — sanitize_key strips non-[a-z0-9_-] characters and lowercases
	// =========================================================================

	public function test_post_type_special_characters_are_stripped(): void {
		$received_post_type = null;

		add_filter(
			'kratt_system_instructions',
			function ( $instructions, $context ) use ( &$received_post_type ) {
				$received_post_type = $context['post_type'];
				return $instructions;
			},
			10,
			2
		);

		$this->post( '/kratt/v1/review', [
			'post_type' => 'post<script>',
		] );

		$this->assertStringNotContainsString( '<', $received_post_type );
		$this->assertStringNotContainsString( '>', $received_post_type );
	}

	public function test_post_type_is_lowercased_by_sanitize_key(): void {
		$received_post_type = null;

		add_filter(
			'kratt_system_instructions',
			function ( $instructions, $context ) use ( &$received_post_type ) {
				$received_post_type = $context['post_type'];
				return $instructions;
			},
			10,
			2
		);

		$this->post( '/kratt/v1/review', [
			'post_type' => 'Product',
		] );

		$this->assertSame( 'product', $received_post_type );
	}

	// =========================================================================
	// post_id — WordPress validates 'type' => 'integer' before the controller
	// =========================================================================

	public function test_non_integer_post_id_is_rejected_by_rest_validation(): void {
		// WordPress validates 'type' => 'integer' before calling the controller.
		// A non-numeric string never reaches our code — WP returns 400 first.
		$response = $this->post( '/kratt/v1/review', [
			'post_id' => 'not-a-number',
		] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_negative_post_id_is_received_as_integer(): void {
		$received_post_id = null;

		add_filter(
			'kratt_system_instructions',
			function ( $instructions, $context ) use ( &$received_post_id ) {
				$received_post_id = $context['post_id'];
				return $instructions;
			},
			10,
			2
		);

		$this->post( '/kratt/v1/review', [
			'post_id' => -1,
		] );

		// A negative ID is not a valid post, but it should not crash — we accept
		// it as an integer and let the filter consumer decide what to do with it.
		$this->assertIsInt( $received_post_id );
	}
}
