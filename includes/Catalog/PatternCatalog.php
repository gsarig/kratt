<?php

declare( strict_types=1 );

namespace Kratt\Catalog;

class PatternCatalog {

	/**
	 * Returns registered block patterns that have descriptions.
	 *
	 * Reads from the live WP_Block_Patterns_Registry on every call.
	 * Patterns without a description are skipped (they provide no
	 * useful context for the AI). Results are capped at KRATT_MAX_PATTERNS
	 * (default 30) to limit prompt token usage. Override via the
	 * `kratt_pattern_catalog_max` filter.
	 *
	 * @return array<string, array<string, mixed>> Keyed by pattern name.
	 */
	public static function get_patterns(): array {
		$registry    = \WP_Block_Patterns_Registry::get_instance();
		$registered  = $registry->get_all_registered();
		$patterns    = [];
		$max         = (int) apply_filters( 'kratt_pattern_catalog_max', KRATT_MAX_PATTERNS );

		foreach ( $registered as $pattern ) {
			if ( count( $patterns ) >= $max ) {
				break;
			}

			$description = $pattern['description'] ?? '';
			if ( '' === $description ) {
				continue;
			}

			$name = $pattern['name'] ?? '';
			if ( '' === $name ) {
				continue;
			}

			$patterns[ $name ] = [
				'name'        => $name,
				'title'       => $pattern['title'] ?? $name,
				'description' => $description,
				'categories'  => $pattern['categories'] ?? [],
				'keywords'    => $pattern['keywords'] ?? [],
				'content'     => $pattern['content'] ?? '',
			];
		}

		return $patterns;
	}

	/**
	 * Formats patterns for inclusion in the AI system prompt.
	 *
	 * Each pattern is rendered as:
	 *   - namespace/name (Title): Description
	 *     Categories: cat1, cat2
	 *
	 * Full pattern content is NOT included; only metadata.
	 *
	 * @param array<string, array<string, mixed>> $patterns
	 */
	public static function format_for_prompt( array $patterns ): string {
		$lines = [];

		foreach ( $patterns as $name => $pattern ) {
			$line = sprintf(
				'- %s (%s): %s',
				$name,
				$pattern['title'] ?? $name,
				$pattern['description'] ?? ''
			);

			$categories = $pattern['categories'] ?? [];
			if ( ! empty( $categories ) ) {
				$line .= "\n  Categories: " . implode( ', ', $categories );
			}

			$lines[] = $line;
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Filters a patterns array to only include patterns whose blocks are all
	 * present in the given catalog.
	 *
	 * Used when `allowed_blocks` is in effect: prevents the AI from selecting
	 * a pattern that contains blocks outside the permitted set.
	 *
	 * @param array<string, array<string, mixed>> $patterns Patterns from get_patterns().
	 * @param array<string, mixed>                $catalog  Block catalog, keyed by block name.
	 * @return array<string, array<string, mixed>>
	 */
	public static function filter_by_catalog( array $patterns, array $catalog ): array {
		return array_filter(
			$patterns,
			static function ( array $pattern ) use ( $catalog ): bool {
				$content = $pattern['content'] ?? '';
				if ( '' === $content ) {
					return false;
				}
				return self::all_blocks_in_catalog( parse_blocks( $content ), $catalog );
			}
		);
	}

	/**
	 * Recursively checks whether all named blocks in a parsed block list are
	 * present in the catalog.
	 *
	 * Null blockName entries (freeform content between blocks) are ignored.
	 *
	 * @param array<int, mixed>    $blocks  Output of parse_blocks().
	 * @param array<string, mixed> $catalog Block catalog, keyed by block name.
	 */
	private static function all_blocks_in_catalog( array $blocks, array $catalog ): bool {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = $block['blockName'] ?? null;
			if ( null !== $name && '' !== $name && ! isset( $catalog[ $name ] ) ) {
				return false;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				if ( ! self::all_blocks_in_catalog( $block['innerBlocks'], $catalog ) ) {
					return false;
				}
			}
		}
		return true;
	}
}
