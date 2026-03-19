<?php

namespace Kratt\Tests\Integration\Abilities;

use Kratt\Abilities\InsertBlockAbility;
use WP_UnitTestCase;

/**
 * Integration tests for InsertBlockAbility.
 *
 * Verifies that the kratt/insert-block ability is registered correctly
 * and that its permission callback enforces the edit_posts capability.
 */
class InsertBlockAbilityTest extends WP_UnitTestCase {

	public function test_ability_is_registered_when_api_available(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_list_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		do_action( 'wp_abilities_api_init' );

		$abilities = wp_list_abilities();
		$this->assertArrayHasKey( 'kratt/insert-block', $abilities );
	}

	public function test_ability_has_label(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_list_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		do_action( 'wp_abilities_api_init' );

		$abilities = wp_list_abilities();
		$this->assertArrayHasKey( 'label', $abilities['kratt/insert-block'] );
		$this->assertNotEmpty( $abilities['kratt/insert-block']['label'] );
	}

	public function test_ability_has_input_schema_with_prompt(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_list_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		do_action( 'wp_abilities_api_init' );

		$abilities     = wp_list_abilities();
		$input_schema  = $abilities['kratt/insert-block']['input_schema'] ?? [];

		$this->assertArrayHasKey( 'properties', $input_schema );
		$this->assertArrayHasKey( 'prompt', $input_schema['properties'] );
	}

	public function test_ability_requires_prompt_in_input_schema(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_list_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		do_action( 'wp_abilities_api_init' );

		$abilities    = wp_list_abilities();
		$input_schema = $abilities['kratt/insert-block']['input_schema'] ?? [];

		$this->assertArrayHasKey( 'required', $input_schema );
		$this->assertContains( 'prompt', $input_schema['required'] );
	}

	public function test_permission_callback_denies_anonymous_user(): void {
		wp_set_current_user( 0 );

		// Call register() to set up the ability, then retrieve its permission callback.
		InsertBlockAbility::register();

		if ( ! function_exists( 'wp_list_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$abilities           = wp_list_abilities();
		$permission_callback = $abilities['kratt/insert-block']['permission_callback'] ?? null;

		if ( $permission_callback === null ) {
			$this->markTestSkipped( 'Ability permission_callback not accessible.' );
		}

		$this->assertFalse( $permission_callback() );
	}

	public function test_permission_callback_allows_editor(): void {
		$editor = $this->factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor );

		InsertBlockAbility::register();

		if ( ! function_exists( 'wp_list_abilities' ) ) {
			$this->markTestSkipped( 'WordPress Abilities API not available.' );
		}

		$abilities           = wp_list_abilities();
		$permission_callback = $abilities['kratt/insert-block']['permission_callback'] ?? null;

		if ( $permission_callback === null ) {
			$this->markTestSkipped( 'Ability permission_callback not accessible.' );
		}

		$this->assertTrue( $permission_callback() );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}
}
