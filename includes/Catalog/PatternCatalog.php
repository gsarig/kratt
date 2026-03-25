<?php

declare( strict_types=1 );

namespace Kratt\Catalog;

class PatternCatalog {

	/**
	 * Returns all registered block patterns that have descriptions.
	 *
	 * Reads from the live WP_Block_Patterns_Registry on every call.
	 * Patterns without a description are skipped (they provide no
	 * useful context for the AI). No cap is applied here; use
	 * select_for_prompt() to rank and limit before building the AI prompt.
	 *
	 * @return array<string, array<string, mixed>> Keyed by pattern name.
	 */
	public static function get_patterns(): array {
		$registry   = \WP_Block_Patterns_Registry::get_instance();
		$registered = $registry->get_all_registered();
		$patterns   = [];

		foreach ( $registered as $pattern ) {
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
	 * Selects the most relevant patterns for inclusion in the AI prompt.
	 *
	 * If the total number of patterns is within $max, all are returned.
	 * Otherwise, each pattern is scored by keyword overlap with the user's
	 * prompt (across name, title, description, keywords, and categories) and
	 * the top $max are returned. Words shorter than 3 characters are ignored.
	 * When the prompt yields no usable words, the first $max patterns are
	 * returned unchanged.
	 *
	 * @param array<string, array<string, mixed>> $patterns Patterns from get_patterns().
	 * @param string                              $prompt   The user's compose prompt.
	 * @param int                                 $max      Maximum number of patterns to return.
	 * @return array<string, array<string, mixed>>
	 */
	public static function select_for_prompt( array $patterns, string $prompt, int $max ): array {
		if ( count( $patterns ) <= $max ) {
			return $patterns;
		}

		$split = preg_split( '/[^a-z0-9]+/u', strtolower( $prompt ) );
		$words = array_values(
			array_filter(
				is_array( $split ) ? $split : [],
				static fn( string $w ) => strlen( $w ) >= 3
			)
		);

		if ( empty( $words ) ) {
			return array_slice( $patterns, 0, $max, preserve_keys: true );
		}

		$scores = [];
		foreach ( $patterns as $name => $pattern ) {
			$scores[ $name ] = self::score_pattern( $pattern, $words );
		}

		arsort( $scores );

		$top_scores = array_slice( $scores, 0, $max, preserve_keys: true );
		$selected   = [];

		foreach ( array_keys( $top_scores ) as $name ) {
			if ( array_key_exists( $name, $patterns ) ) {
				$selected[ $name ] = $patterns[ $name ];
			}
		}

		return $selected;
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
	 * Scores a single pattern against a list of prompt words.
	 *
	 * Counts how many words appear in the combined name, title, description,
	 * keywords, and categories of the pattern (case-insensitive).
	 *
	 * @param array<string, mixed> $pattern    A pattern entry from get_patterns().
	 * @param string[]             $words      Lowercase words extracted from the prompt.
	 */
	private static function score_pattern( array $pattern, array $words ): int {
		$haystack = strtolower(
			implode(
				' ',
				[
					$pattern['name'] ?? '',
					$pattern['title'] ?? '',
					$pattern['description'] ?? '',
					implode( ' ', (array) ( $pattern['keywords'] ?? [] ) ),
					implode( ' ', (array) ( $pattern['categories'] ?? [] ) ),
				]
			)
		);

		$score = 0;
		foreach ( $words as $word ) {
			if ( str_contains( $haystack, $word ) ) {
				++$score;
			}
		}

		return $score;
	}

	/**
	 * Recursively checks whether all named blocks in a parsed block list are
	 * present in the catalog.
	 *
	 * Null blockName entries represent freeform HTML outside any block. Whitespace-only
	 * nodes (common between blocks) are harmless and always pass. Non-whitespace freeform
	 * content is treated as core/freeform and requires that block to be in the catalog.
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
			if ( null === $name ) {
				$inner = trim( (string) ( $block['innerHTML'] ?? '' ) );
				if ( '' !== $inner && ! isset( $catalog['core/freeform'] ) ) {
					return false;
				}
				continue;
			}
			if ( '' !== $name && ! isset( $catalog[ $name ] ) ) {
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
