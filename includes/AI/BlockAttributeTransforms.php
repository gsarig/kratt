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
	 * The AI sets lat and lng to specify the map centre, sourced from the
	 * ability's input_schema. Those are not real block attributes — the block
	 * uses bounds ([[lat, lng]]) instead. This handler converts the pair and
	 * removes lat/lng so they do not reach the editor as unknown attributes.
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
			$attributes['bounds'] = [ [ (float) $lat, (float) $lng ] ];
			unset( $attributes['lat'], $attributes['lng'] );
		}

		return $attributes;
	}
}
