<?php

namespace Kratt\Tests\Integration\Settings;

use Kratt\Settings\Settings;
use WP_UnitTestCase;

/**
 * Integration tests for Settings — verifies that catalog data is persisted
 * to and retrieved from the WordPress options table correctly.
 */
class SettingsTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		delete_option( 'kratt_block_catalog' );
		delete_option( 'kratt_catalog_scanned_at' );
	}

	public function test_get_catalog_returns_empty_array_when_not_set(): void {
		$catalog = Settings::get_catalog();

		$this->assertIsArray( $catalog );
		$this->assertEmpty( $catalog );
	}

	public function test_get_catalog_scanned_at_returns_null_when_not_set(): void {
		$scanned_at = Settings::get_catalog_scanned_at();

		$this->assertNull( $scanned_at );
	}

	public function test_save_catalog_persists_data(): void {
		$data = [
			'core/paragraph' => [
				'name'    => 'core/paragraph',
				'source'  => 'core',
				'enabled' => true,
				'title'   => 'Paragraph',
			],
		];

		Settings::save_catalog( $data );

		$retrieved = Settings::get_catalog();
		$this->assertSame( $data, $retrieved );
	}

	public function test_save_catalog_updates_scanned_at(): void {
		Settings::save_catalog( [] );

		$scanned_at = Settings::get_catalog_scanned_at();
		$this->assertNotNull( $scanned_at );
		$this->assertIsString( $scanned_at );
		$this->assertNotEmpty( $scanned_at );
	}

	public function test_save_catalog_scanned_at_is_mysql_datetime_format(): void {
		Settings::save_catalog( [] );

		$scanned_at = Settings::get_catalog_scanned_at();
		// MySQL datetime: YYYY-MM-DD HH:MM:SS
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $scanned_at );
	}

	public function test_save_catalog_overwrites_previous_data(): void {
		Settings::save_catalog( [ 'core/paragraph' => [ 'title' => 'Old' ] ] );
		Settings::save_catalog( [ 'core/heading' => [ 'title' => 'New' ] ] );

		$catalog = Settings::get_catalog();
		$this->assertArrayHasKey( 'core/heading', $catalog );
		$this->assertArrayNotHasKey( 'core/paragraph', $catalog );
	}

	public function test_save_empty_catalog_and_retrieve(): void {
		Settings::save_catalog( [] );

		$catalog = Settings::get_catalog();
		$this->assertIsArray( $catalog );
		$this->assertEmpty( $catalog );
	}
}
