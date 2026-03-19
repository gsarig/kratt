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
	 * For each registered ability, Kratt resolves which block it belongs to by normalizing the
	 * ability's namespace and comparing it against every block name in the catalog. Normalization
	 * strips everything except lowercase alphanumerics, then checks whether the result equals the
	 * concatenation of the block's namespace and slug after the same normalization.
	 *
	 * Example: ability `ootb-openstreetmap/add-map-to-post` has namespace `ootb-openstreetmap`,
	 * which normalizes to `ootbopenstreetmap`. Block `ootb/openstreetmap` normalizes to the same
	 * string, so the match is found. If zero or more than one block matches, the ability is skipped.
	 *
	 * Once matched, attribute descriptions are derived from the ability's `input_schema` properties.
	 * Param names are converted from snake_case to camelCase to match block attribute conventions.
	 * Only existing block attributes are enriched — no new attributes are invented.
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
			$block_name = self::resolve_block_for_ability( $ability, $catalog );
			if ( null === $block_name ) {
				continue;
			}

			// Derive descriptions from input_schema properties via snake_to_camel mapping.
			$properties = $ability->get_input_schema()['properties'] ?? [];
			foreach ( $properties as $param_name => $param_schema ) {
				if ( 'post_id' === $param_name ) {
					continue; // Ability-level param, not a block attribute.
				}

				$attr_name   = self::snake_to_camel( $param_name );
				$description = $param_schema['description'] ?? '';

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
	 * Resolves which catalog block a given ability belongs to, using name normalization.
	 *
	 * Strips the action segment from the ability name (everything after the first `/`), then
	 * normalizes the namespace by removing all non-alphanumeric characters and lowercasing.
	 * Each block name is normalized the same way (namespace + slug concatenated). If exactly
	 * one block matches, that block is returned. Zero or multiple matches mean the association
	 * is ambiguous, so null is returned and the ability is skipped.
	 *
	 * @param \WP_Ability          $ability The ability to resolve.
	 * @param array<string, mixed> $catalog The current block catalog.
	 * @return string|null The matched block name, or null if the match is ambiguous or absent.
	 */
	private static function resolve_block_for_ability( \WP_Ability $ability, array $catalog ): ?string {
		$ability_name = $ability->get_name();
		$slash_pos    = strpos( $ability_name, '/' );
		if ( false === $slash_pos ) {
			return null;
		}

		$normalize            = static fn( string $s ): string => preg_replace( '/[^a-z0-9]/', '', strtolower( $s ) ) ?? '';
		$normalized_namespace = $normalize( substr( $ability_name, 0, $slash_pos ) );

		$matches = [];
		foreach ( $catalog as $block_name => $block ) {
			$parts = explode( '/', $block_name, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			if ( $normalize( $parts[0] ) . $normalize( $parts[1] ) === $normalized_namespace ) {
				$matches[] = $block_name;
			}
		}

		// Ambiguous or no match — skip rather than guess.
		return 1 === count( $matches ) ? $matches[0] : null;
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
