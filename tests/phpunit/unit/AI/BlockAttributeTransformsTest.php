<?php

namespace Kratt\Tests\Unit\AI;

use Kratt\AI\BlockAttributeTransforms;
use WP_UnitTestCase;

/**
 * Unit tests for BlockAttributeTransforms::ootb_openstreetmap().
 */
class BlockAttributeTransformsTest extends WP_UnitTestCase {

	// =========================================================================
	// ootb_openstreetmap()
	// =========================================================================

	public function test_converts_lat_lng_to_bounds(): void {
		$attributes = [ 'lat' => 39.1, 'lng' => 20.75 ];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertSame( [ [ 39.1, 20.75 ] ], $result['bounds'] );
		$this->assertFalse( $result['showDefaultBounds'] );
	}

	public function test_removes_lat_and_lng_after_conversion(): void {
		$attributes = [ 'lat' => 39.1, 'lng' => 20.75 ];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertArrayNotHasKey( 'lat', $result );
		$this->assertArrayNotHasKey( 'lng', $result );
	}

	public function test_preserves_other_attributes(): void {
		$attributes = [ 'lat' => 39.1, 'lng' => 20.75, 'zoom' => 12 ];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertSame( 12, $result['zoom'] );
	}

	public function test_does_not_set_bounds_when_only_lat_is_present(): void {
		$attributes = [ 'lat' => 39.1 ];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertArrayNotHasKey( 'bounds', $result );
		$this->assertArrayNotHasKey( 'lat', $result );
	}

	public function test_does_not_set_bounds_when_only_lng_is_present(): void {
		$attributes = [ 'lng' => 20.75 ];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertArrayNotHasKey( 'bounds', $result );
		$this->assertArrayNotHasKey( 'lng', $result );
	}

	public function test_removes_lat_lng_even_when_not_numeric(): void {
		$attributes = [ 'lat' => 'not-a-number', 'lng' => 'also-not-a-number' ];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertArrayNotHasKey( 'lat', $result );
		$this->assertArrayNotHasKey( 'lng', $result );
		$this->assertArrayNotHasKey( 'bounds', $result );
	}

	public function test_handles_non_array_markers_without_error(): void {
		$attributes = [ 'markers' => 'not-an-array' ];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertArrayNotHasKey( 'bounds', $result );
	}

	public function test_does_not_modify_attributes_for_other_blocks(): void {
		$attributes = [ 'lat' => 39.1, 'lng' => 20.75 ];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'core/paragraph' );

		$this->assertSame( $attributes, $result );
	}

	public function test_handles_string_numeric_lat_lng(): void {
		$attributes = [ 'lat' => '39.1', 'lng' => '20.75' ];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertSame( [ [ 39.1, 20.75 ] ], $result['bounds'] );
	}

	public function test_handles_empty_attributes(): void {
		$result = BlockAttributeTransforms::ootb_openstreetmap( [], 'ootb/openstreetmap' );

		$this->assertSame( [], $result );
	}

	public function test_derives_bounds_from_first_marker_when_lat_lng_absent(): void {
		$attributes = [
			'markers' => [
				[ 'lat' => 38.95, 'lng' => 20.73 ],
				[ 'lat' => 37.97, 'lng' => 23.72 ],
			],
		];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertSame( [ [ 38.95, 20.73 ] ], $result['bounds'] );
		$this->assertFalse( $result['showDefaultBounds'] );
	}

	public function test_does_not_overwrite_existing_bounds_when_markers_present(): void {
		$attributes = [
			'bounds'  => [ [ 51.5, -0.1 ] ],
			'markers' => [ [ 'lat' => 38.95, 'lng' => 20.73 ] ],
		];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertSame( [ [ 51.5, -0.1 ] ], $result['bounds'] );
	}

	public function test_does_not_set_bounds_when_markers_is_empty(): void {
		$attributes = [ 'markers' => [] ];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		$this->assertArrayNotHasKey( 'bounds', $result );
	}

	public function test_lat_lng_take_precedence_over_markers(): void {
		$attributes = [
			'lat'     => 39.1,
			'lng'     => 20.75,
			'markers' => [ [ 'lat' => 37.97, 'lng' => 23.72 ] ],
		];

		$result = BlockAttributeTransforms::ootb_openstreetmap( $attributes, 'ootb/openstreetmap' );

		// lat/lng win; bounds should reflect them, not the first marker.
		$this->assertSame( [ [ 39.1, 20.75 ] ], $result['bounds'] );
		$this->assertArrayNotHasKey( 'lat', $result );
		$this->assertArrayNotHasKey( 'lng', $result );
	}
}
