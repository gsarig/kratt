# Kratt: WordPress AI Block Composer

## Project Overview

Kratt adds a sidebar panel to the Block Editor. Users describe what they want to build
in plain language; Kratt calls the WP AI Client, interprets the JSON response, and inserts
the appropriate blocks at the cursor position.

Requires WordPress 7.0+ (for `wp_ai_client_prompt()` and the WordPress Abilities API).

---

## Critical Development Rules

### ootb-openstreetmap is read-only

The `ootb-openstreetmap` plugin directory is present on this machine as a dependency used
for integration testing. **Never modify any file inside it.** It is a separate project
with its own maintainer. All cross-plugin concerns are handled on the Kratt side only.

### Minimize Impact

Make the smallest possible change to achieve the goal. Do not refactor, rename, or
restructure code not directly related to the task. Working code must stay working.

### PHP vs. JS Boundary

Kratt has two distinct layers: PHP (REST, AI client, block catalog, ability integration,
settings) and JavaScript (Gutenberg sidebar UI). If a task affects both layers, warn before
proceeding; cross-layer impact needs explicit sign-off.

### Public API Stability

The following are public API. Any change to signatures or removal is a **breaking change**
and must be flagged before implementing:

| Hook | Type | Purpose |
|------|------|---------|
| `kratt_block_attribute_transform` | filter | Transform AI-output attributes before blocks reach the editor |
| `kratt_dummy_response` | filter | Override test mode blocks without editing the plugin |
| `kratt_dummy_review_response` | filter | Override test mode review findings without editing the plugin |
| `kratt_system_instructions` | filter | Add or replace system prompt instructions per context |
| `kratt_editor_content_max_chars` | filter | Override the server-side character cap on `editor_content` (default 8000) |
| `kratt_block_snippet_max_chars` | filter | Override the per-block text snippet limit in the editor summary (default 300) |
| `kratt_pattern_catalog_max` | filter | Override the maximum number of patterns included in the AI prompt (default 100) |

REST endpoints are also public API: `POST /kratt/v1/compose`, `POST /kratt/v1/review`,
`GET /kratt/v1/catalog`, `POST /kratt/v1/catalog/rescan`.

`POST /kratt/v1/compose` returns one of three shapes:
- `{"blocks": [...]}` — success; blocks to insert
- `{"pattern_content": "..."}` — success; serialized block markup from a registered pattern
- `{"error": "...", "suggestion": "..."}` — failure; human-readable explanation

---

## Architecture Notes

### The Block Catalog

`BlockCatalog::scan()` builds the catalog from the WordPress block registry and stores it
in a WordPress option. Two data sources are merged:

1. `src/data/core-blocks.json`: hand-curated metadata for common core blocks
2. `WP_Block_Type_Registry`: all registered blocks (core, theme, plugin)

The catalog is scanned at activation and whenever a plugin or theme is activated.

### Ability Integration

`BlockCatalog::enrich_from_abilities()` reads registered WordPress abilities and adds
attribute documentation from their `input_schema` to matching catalog entries. Ability
params that have no matching block attribute are added as **virtual prompt-only attributes**;
the AI reads their descriptions and sets them, and a transform handler then converts them
to the real block attribute format.

Ability-to-block matching uses name normalization: strip non-alphanumeric chars + lowercase,
compare ability namespace against block `namespace+slug` concatenated. Ambiguous matches
(zero or multiple) are always skipped.

### kratt_block_attribute_transform Filter

Runs after `filter_unknown_blocks()` on every AI response, applying recursively to inner
blocks. Built-in handlers live in `BlockAttributeTransforms`. The ootb/openstreetmap handler
converts `lat`/`lng` to `bounds` + sets `showDefaultBounds: false`. If a handler returns a
non-array, the original attributes are preserved.

### Test Mode

Define `KRATT_TEST_MODE = true` in `wp-config.php` to skip the AI call entirely. The dummy
response passes through the full transform pipeline. Use the `kratt_dummy_response` filter
to override which blocks the dummy returns; this is the intended pattern for testing
specific block transforms without burning API tokens.

### AI Response Flow

```
POST /kratt/v1/compose
  → ComposeController
  → Client::compose()
      → PromptBuilder::build()         (assembles system prompt)
      → wp_ai_client_prompt()          (calls AI provider)
      → strip_json_fences()            (normalise response)
      → json_decode()
      → filter_unknown_blocks()        (remove hallucinated block names)
      → apply_block_attribute_transforms()  (run kratt_block_attribute_transform)
  → REST response
```

---

## Stack

- **PHP** 8.1+: REST endpoints, AI client, block catalog, settings
- **JavaScript** (ES modules, React): Gutenberg sidebar UI (`@wordpress/` packages)
- **WordPress** 7.0+: `wp_ai_client_prompt()`, Abilities API
- **Composer**: PHP dependencies and dev tools (PHPCS, PHPStan)
- **npm**: JS build pipeline (`wp-scripts`)

---

## Project Structure

```
kratt/
├── kratt.php                       # Plugin entry point: constants, autoloader, boot
├── includes/
│   ├── Plugin.php                  # Registers all hooks
│   ├── AI/
│   │   ├── Client.php              # Calls wp_ai_client_prompt(), parses JSON response
│   │   ├── PromptBuilder.php       # Assembles system prompt
│   │   └── BlockAttributeTransforms.php  # Built-in kratt_block_attribute_transform handlers
│   ├── Catalog/
│   │   ├── BlockCatalog.php        # Scans, stores, filters, formats the block catalog
│   │   └── CoreBlocksRepository.php
│   ├── Editor/
│   │   └── Sidebar.php             # Enqueues sidebar JS/CSS
│   ├── REST/
│   │   ├── ComposeController.php
│   │   └── CatalogController.php
│   ├── Settings/
│   │   └── Settings.php
│   └── Abilities/
│       └── InsertBlockAbility.php
├── src/
│   ├── index.js                    # Sidebar UI (React)
│   └── data/
│       └── core-blocks.json        # Hand-curated core block metadata
├── build/                          # Compiled JS/CSS (gitignored, run npm run build)
├── tests/
│   └── phpunit/
│       ├── unit/                   # Pure logic tests (no DB, no registry)
│       └── integration/            # WordPress integration tests
├── composer.json
├── phpstan.neon
├── phpcs.xml
├── Makefile
└── CONTRIBUTING.md                 # Architecture details and contribution guide
```

---

## Commands

### Setup

```bash
composer install
npm ci && npm run build
bash bin/install-wp-tests.sh wordpress_test wordpress wordpress 127.0.0.1 latest
```

### Development

```bash
npm run build       # Rebuild JS after JS changes
npm run start       # Watch mode
```

### Testing: Run After Every Change

```bash
make lint           # PHPStan (level 6) + PHPCS; run first, fastest
make phpunit        # PHPUnit (requires WordPress test database)
make test           # lint + phpunit
```

Docker variant (no local test database needed):

```bash
make test-docker
```

---

## Testing Workflow

1. Implement the change
2. Run `make lint` and fix all PHPStan and PHPCS errors before continuing
3. Run `make phpunit` and confirm all tests pass
4. Only report done when both pass

If tests are still failing after 3 fix attempts, stop and explain what was tried and why
it is still failing; do not keep iterating blindly.

### Testing without burning AI tokens

Set `KRATT_TEST_MODE = true` in `wp-config.php`. The dummy response (heading + paragraph)
goes through the full transform pipeline. To test a specific block, add this to
`functions.php` (do not edit the plugin):

```php
add_filter( 'kratt_dummy_response', function( array $blocks, string $prompt ): array {
    return [
        [ 'name' => 'ootb/openstreetmap', 'attributes' => [ 'markers' => [ [ 'lat' => 38.95, 'lng' => 20.73 ] ] ] ],
    ];
}, 10, 2 );
```

---

## Code Style

- WordPress Coding Standards (enforced by PHPCS via `phpcs.xml`)
- PHPStan level 6; no errors allowed
- PHP 8.1+; use typed properties, union types, named arguments where appropriate
- JavaScript: ES modules, `@wordpress/` packages, `@wordpress/i18n` for all user-visible strings
- Text domain: `kratt`
- No em dashes in prose; use semicolons or split into two sentences
- Escape all output; sanitize and validate all input

---

## Key Conventions

### Adding a built-in block transform

Add a static method to `BlockAttributeTransforms` and register it in `Plugin::init()`:

```php
add_filter( 'kratt_block_attribute_transform', [ BlockAttributeTransforms::class, 'my_method' ], 10, 2 );
```

The threshold for a built-in: the block is widely used, the mismatch between ability params
and block attributes is non-obvious, and there is no other way to resolve it.

### Adding core block metadata

Edit `src/data/core-blocks.json`. Add entries only when the auto-derived description is
inaccurate, a disambiguating hint is needed, or a realistic example improves AI output.
Do not add entries without meaningful hints or curated attribute descriptions.

### Catalog-gating for AI prompt data

Any data source passed to the AI prompt that can result in block insertion must be
filtered against the active (possibly `allowed_blocks`-filtered) catalog before the
prompt is built. This applies to the block catalog itself, the pattern catalog, and
anything similar added in the future.

The pattern catalog learned this the hard way: patterns were included in the prompt
without checking their block composition, so the AI could return a pattern containing
blocks outside the `allowed_blocks` list, bypassing the editor's block restrictions.
`PatternCatalog::filter_by_catalog()` is the reference implementation for the catalog
check; `PatternCatalog::select_for_prompt()` is the reference for relevance-based
capping before the prompt is built.

### Never log raw AI payloads by default

Never include raw AI request or response data in log messages by default. AI responses
can embed editor content (the user's post text), which must not end up in server logs
on production sites. Always gate raw payload logging behind `WP_DEBUG`:

```php
$msg = 'Kratt: something went wrong. JSON error: ' . json_last_error_msg();
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    $msg .= '. Raw response: ' . substr( $response, 0, 500 );
}
error_log( $msg );
```

The error type and metadata are safe to log unconditionally; the payload is not.

### Every AI entry point must mirror create_item() input handling

`ComposeController::create_item()` is the reference implementation for input handling on the compose path. Any other entry point into the AI pipeline — currently `compose_from_ability()`, and any future equivalent — must apply the same steps in the same order:

1. Cast and sanitize inputs (`sanitize_text_field()` for prompt, `wp_kses_post()` for `editor_content`)
2. Validate post context (verify the post exists and the user can edit it; derive `post_type` from the post object)
3. Cap `editor_content` via `kratt_editor_content_max_chars` (clamped to `>= 0`; treat `0` as empty)

This rule was earned twice: post context validation was missing from `compose_from_ability()` in one round, and sanitization + capping were missing in the next. When adding a new entry point, check it line-by-line against `create_item()`.

### Two block formats: never mix them

There are two distinct block array formats in this codebase:

- **AI response format** — produced by `json_decode()` on the AI output. Block names are
  under the `name` key. Used by `filter_unknown_blocks()` and `apply_block_attribute_transforms()`.
- **WordPress serialized format** — produced by `parse_blocks()`. Block names are under
  the `blockName` key. Used by `PatternCatalog::filter_by_catalog()` and `all_blocks_in_catalog()`.

Never pass `parse_blocks()` output to `filter_unknown_blocks()` or vice versa — the key
mismatch causes silent failures where every block is treated as unknown. When working
with parsed WP content, use `PatternCatalog::filter_by_catalog()`. When working with AI
response blocks, use `filter_unknown_blocks()`.

### Always type-guard WP_Block_Patterns_Registry output

WordPress does not enforce field types on pattern registration. Both `get_registered()`
and `get_all_registered()` return `mixed` for every field. Always apply explicit
`is_string()` / `is_array()` checks before using any field value:

```php
$content = $pattern['content'] ?? '';
if ( ! is_string( $content ) || '' === $content ) {
    // skip or return error
}
```

This rule was earned twice: once when `resolve_pattern()` lacked a content type check,
and again when `get_patterns()` and `filter_by_catalog()` were missing guards on
`description`, `name`, `categories`, and `keywords`. A missing guard causes a type
error or silent wrong behavior at runtime.

### Ability name matching

The normalization function strips all non-alphanumeric characters and lowercases. It
compares the ability namespace against each block's `namespace + slug` concatenated.
Example: `ootb-openstreetmap` → `ootbopenstreetmap` matches `ootb/openstreetmap` →
`ootb` + `openstreetmap` = `ootbopenstreetmap`.
