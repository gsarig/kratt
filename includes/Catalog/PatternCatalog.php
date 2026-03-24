<?php

declare( strict_types=1 );

namespace Kratt\Catalog;

class PatternCatalog {

	private const MAX_PATTERNS = 30;

	/**
	 * Returns registered block patterns that have descriptions.
	 *
	 * Reads from the live WP_Block_Patterns_Registry on every call.
	 * Patterns without a description are skipped (they provide no
	 * useful context for the AI). Results are capped at MAX_PATTERNS
	 * to limit prompt token usage.
	 *
	 * @return array<string, array<string, mixed>> Keyed by pattern name.
	 */
	public static function get_patterns(): array {
		$registry    = \WP_Block_Patterns_Registry::get_instance();
		$registered  = $registry->get_all_registered();
		$patterns    = [];

		foreach ( $registered as $pattern ) {
			if ( count( $patterns ) >= self::MAX_PATTERNS ) {
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
}
