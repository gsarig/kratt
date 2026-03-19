<?php

declare( strict_types=1 );

namespace Kratt\Catalog;

use Kratt\Settings\Settings;
use WP_Block_Type_Registry;

class BlockCatalog {

	/**
	 * Returns the stored catalog, or an empty array if not yet scanned.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		return Settings::get_catalog();
	}

	/**
	 * Scans the block registry, merges with core block data, and stores the result.
	 */
	public static function scan(): void {
		$core_blocks    = CoreBlocksRepository::get();
		$registry       = WP_Block_Type_Registry::get_instance();
		$all_registered = $registry->get_all_registered();
		$catalog = [];

		foreach ( $all_registered as $name => $block_type ) {
			// Core blocks: use handcrafted data if available, supplement from registry.
			if ( isset( $core_blocks[ $name ] ) ) {
				$catalog[ $name ] = array_merge(
					[
						'name'    => $name,
						'source'  => 'core',
						'enabled' => true,
					],
					$core_blocks[ $name ]
				);
				continue;
			}

			// Non-curated blocks: derive source from namespace.
			$catalog[ $name ] = [
				'name'        => $name,
				'source'      => str_starts_with( $name, 'core/' ) ? 'core' : 'custom',
				'enabled'     => true,
				'title'       => $block_type->title,
				'description' => $block_type->description,
				'keywords'    => $block_type->keywords,
				'hint'        => '',
				'dynamic'     => $block_type->is_dynamic(),
				'attributes'  => self::extract_attributes( $block_type ),
				'example'     => $block_type->example ?? [],
			];
		}

		$catalog = self::enrich_from_abilities( $catalog );
		Settings::save_catalog( $catalog );
	}

	/**
	 * Filters the catalog to blocks allowed in the current editor context.
	 *
	 * @param array<string, mixed> $catalog       Full catalog.
	 * @param bool|string[]        $allowed_types Value of allowedBlockTypes from the editor.
	 * @return array<string, mixed>
	 */
	public static function filter_by_allowed( array $catalog, bool|array $allowed_types ): array {
		if ( true === $allowed_types ) {
			return $catalog;
		}

		if ( false === $allowed_types || [] === $allowed_types ) {
			return [];
		}

		return array_filter(
			$catalog,
			static fn( string $name ) => in_array( $name, $allowed_types, strict: true ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/**
	 * Formats the catalog as a string for inclusion in the AI system prompt.
	 *
	 * @param array<string, mixed> $catalog Catalog to format.
	 */
	public static function format_for_prompt( array $catalog ): string {
		$lines = [];

		foreach ( $catalog as $name => $block ) {
			if ( empty( $block['enabled'] ) ) {
				continue;
			}

			$tags = [];
			if ( 'custom' === $block['source'] ) {
				$tags[] = 'CUSTOM';
			}
			if ( ! empty( $block['dynamic'] ) ) {
				$tags[] = 'DYNAMIC';
			}
			$tag_str = $tags ? ' [' . implode( ', ', $tags ) . ']' : '';
			$line    = sprintf( '- %s (%s)%s: %s', $name, $block['title'] ?? $name, $tag_str, $block['description'] ?? '' );

			if ( ! empty( $block['hint'] ) ) {
				$line .= "\n  Hint: " . $block['hint'];
			}

			if ( ! empty( $block['keywords'] ) ) {
				$line .= "\n  Also known as: " . implode( ', ', $block['keywords'] );
			}

			if ( ! empty( $block['attributes'] ) ) {
				$attr_parts = [];
				foreach ( $block['attributes'] as $attr_name => $attr ) {
					$type = $attr['type'] ?? 'string';
					// Skip non-text attributes unless they have a description (ability-backed) or an enum.
					if ( 'string' !== $type && empty( $attr['enum'] ) && empty( $attr['description'] ) ) {
						continue;
					}
					$attr_desc = $attr['description'] ?? '';
					if ( ! empty( $attr['enum'] ) ) {
						$type = implode( '|', $attr['enum'] );
					}
					$attr_parts[] = sprintf( '%s (%s)%s', $attr_name, $type, $attr_desc ? ': ' . $attr_desc : '' );
				}
				if ( $attr_parts ) {
					$line .= "\n  Attributes: " . implode( '; ', $attr_parts );
				}
			}

			if ( ! empty( $block['example'] ) ) {
				$line .= "\n  Example: " . wp_json_encode( $block['example'] );
			}

			$lines[] = $line;
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Extracts simplified attribute definitions from a block type for use in the prompt.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function extract_attributes( \WP_Block_Type $block_type ): array {
		if ( empty( $block_type->attributes ) ) {
			return [];
		}

		$result = [];
		foreach ( $block_type->attributes as $attr_name => $attr_schema ) {
			$result[ $attr_name ] = [
				'type'        => $attr_schema['type'] ?? 'string',
				'description' => '',
			];
			if ( isset( $attr_schema['enum'] ) ) {
				$result[ $attr_name ]['enum'] = $attr_schema['enum'];
			}
		}
		return $result;
	}

	/**
	 * Enriches catalog entries with attribute documentation from registered WordPress abilities.
	 *
	 * Blocks that declare a `block_name` in their ability's `meta` array receive attribute
	 * descriptions derived from the ability's `input_schema`. This makes non-text attributes
	 * (coordinates, zoom levels, enums, etc.) visible in the AI prompt so the model can
	 * populate them reliably.
	 *
	 * Plugin authors can associate an ability with a block by adding to the ability's `meta`:
	 *
	 *   'meta' => [
	 *       'block_name'       => 'my-plugin/my-block',
	 *       'block_attributes' => [
	 *           // Explicit overrides for attrs that don't map 1:1 from ability params.
	 *           'bounds' => [ 'type' => 'array', 'description' => 'Map centre as [[lat, lng]].' ],
	 *       ],
	 *   ],
	 *
	 * Ability param names are converted from snake_case to camelCase to match block attribute
	 * conventions. Only existing block attributes are enriched; no new attributes are invented.
	 * Attributes explicitly listed in `block_attributes` are applied first and take precedence.
	 *
	 * @param array<string, mixed> $catalog Catalog to enrich.
	 * @return array<string, mixed> Enriched catalog.
	 */
	private static function enrich_from_abilities( array $catalog ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $catalog;
		}

		// Ensure abilities are registered — fire the init action if it hasn't run yet.
		if ( ! did_action( 'wp_abilities_api_init' ) ) {
			do_action( 'wp_abilities_api_init' );
		}

		$abilities = wp_get_abilities();

		foreach ( $abilities as $ability ) {
			$block_name = $ability->get_meta_item( 'block_name' );
			if ( empty( $block_name ) || ! isset( $catalog[ $block_name ] ) ) {
				continue;
			}

			// Apply explicit block_attributes overrides first.
			$manual = $ability->get_meta_item( 'block_attributes' ) ?? [];
			foreach ( $manual as $attr_name => $attr_def ) {
				$catalog[ $block_name ]['attributes'][ $attr_name ] = array_merge(
					$catalog[ $block_name ]['attributes'][ $attr_name ] ?? [],
					$attr_def
				);
			}

			// Derive descriptions from input_schema properties via snake_to_camel mapping.
			$properties = $ability->get_input_schema()['properties'] ?? [];
			foreach ( $properties as $param_name => $param_schema ) {
				if ( 'post_id' === $param_name ) {
					continue; // Ability-level param, not a block attribute.
				}

				$attr_name   = self::snake_to_camel( $param_name );
				$description = $param_schema['description'] ?? '';

				// Manual overrides take precedence — skip if already applied.
				if ( isset( $manual[ $attr_name ] ) ) {
					continue;
				}

				if ( ! $description || ! isset( $catalog[ $block_name ]['attributes'][ $attr_name ] ) ) {
					continue; // Only enrich existing attrs; never invent new ones.
				}

				if ( empty( $catalog[ $block_name ]['attributes'][ $attr_name ]['description'] ) ) {
					$catalog[ $block_name ]['attributes'][ $attr_name ]['description'] = $description;
				}
				if ( ! isset( $catalog[ $block_name ]['attributes'][ $attr_name ]['enum'] ) && isset( $param_schema['enum'] ) ) {
					$catalog[ $block_name ]['attributes'][ $attr_name ]['enum'] = $param_schema['enum'];
				}
			}

			// Set a hint if the block doesn't already have one.
			if ( empty( $catalog[ $block_name ]['hint'] ) ) {
				$catalog[ $block_name ]['hint'] = 'This block has a registered ability. The listed attributes are documented and safe to set.';
			}
		}

		return $catalog;
	}

	/**
	 * Converts a snake_case string to camelCase.
	 *
	 * Used to map ability input_schema param names (snake_case) to block attribute
	 * names (camelCase).
	 *
	 * @param string $str Snake-case input, e.g. "map_height".
	 * @return string camelCase output, e.g. "mapHeight".
	 */
	private static function snake_to_camel( string $str ): string {
		return lcfirst( str_replace( '_', '', ucwords( $str, '_' ) ) );
	}
}
