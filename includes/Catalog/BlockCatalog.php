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
					// Skip non-text attributes — the AI is instructed not to set them (rule 2).
					if ( 'string' !== $type && empty( $attr['enum'] ) ) {
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
}
