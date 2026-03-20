<?php

declare( strict_types=1 );

namespace Kratt\AI;

/**
 * Built-in handlers for the kratt_block_attribute_transform filter.
 *
 * These handlers convert AI-output attributes that originate from ability
 * input_schema params (virtual params the AI can set) into the real block
 * attribute format the block actually accepts.
 */
class BlockAttributeTransforms {

	/**
	 * Transforms ootb/openstreetmap attributes from AI output.
	 *
	 * The ootb block uses bounds ([[lat, lng]]) to set the map centre. When the
	 * AI inserts a block via createBlock(), the server-side auto-centre logic in
	 * the ability's execute_callback never runs, so bounds must be set explicitly.
	 *
	 * Two sources are handled:
	 * - lat/lng: explicit map centre coords from the ability's input_schema.
	 *   Converted to bounds and removed (they are not real block attributes).
	 * - markers: when present and bounds is still unset, bounds is derived from
	 *   the first marker. This mirrors what the execute_callback does server-side,
	 *   compensating for the fact that Kratt inserts blocks client-side.
	 *
	 * @param array<string, mixed> $attributes Block attributes from the AI.
	 * @param string               $block_name Block name.
	 * @return array<string, mixed>
	 */
	public static function ootb_openstreetmap( array $attributes, string $block_name ): array {
		if ( 'ootb/openstreetmap' !== $block_name ) {
			return $attributes;
		}

		$lat = $attributes['lat'] ?? null;
		$lng = $attributes['lng'] ?? null;

		if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
			$attributes['bounds']            = [ [ (float) $lat, (float) $lng ] ];
			$attributes['showDefaultBounds'] = false;
			unset( $attributes['lat'], $attributes['lng'] );
			return $attributes;
		}

		// Fall back to the first marker when lat/lng were omitted (which the AI
		// will do when it reads "omit when placing markers" in the ability docs).
		if ( empty( $attributes['bounds'] ) ) {
			$first_marker = $attributes['markers'][0] ?? null;
			if ( is_array( $first_marker )
				&& is_numeric( $first_marker['lat'] ?? null )
				&& is_numeric( $first_marker['lng'] ?? null )
			) {
				$attributes['bounds']            = [ [ (float) $first_marker['lat'], (float) $first_marker['lng'] ] ];
				$attributes['showDefaultBounds'] = false;
			}
		}

		return $attributes;
	}
}
