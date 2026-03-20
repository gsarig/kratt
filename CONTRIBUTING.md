# Contributing to Kratt

## Architecture overview

```
kratt.php                   Plugin entry point — defines constants, loads autoloader, boots Plugin
includes/
  Plugin.php                Registers hooks (REST routes, editor assets, admin menu, catalog scans)
  AI/
    Client.php              Calls wp_ai_client_prompt(), parses JSON response
    PromptBuilder.php       Assembles the system prompt from the catalog and editor context
  Catalog/
    BlockCatalog.php        Scans the registry, stores the catalog, filters and formats it
    CoreBlocksRepository.php Loads the hand-curated core block data from src/data/core-blocks.json
  Editor/
    Sidebar.php             Enqueues the block editor sidebar (JS/CSS)
  REST/
    ComposeController.php   POST /kratt/v1/compose — receives prompt, calls Client::compose()
    CatalogController.php   GET/POST /kratt/v1/catalog — returns or rescans the stored catalog
  Settings/
    Settings.php            Thin wrapper around WordPress options for catalog storage
  Abilities/
    InsertBlockAbility.php  Registers the kratt/insert-block ability via the WordPress Abilities API
src/
  index.js                  Sidebar UI (React/Gutenberg)
  data/
    core-blocks.json        Hand-curated metadata for common core blocks (see below)
tests/
  phpunit/                  PHPUnit test suite (unit + integration)
```

## The block catalog

The catalog is the list of blocks the AI is allowed to use. It is built by `BlockCatalog::scan()` and stored in a WordPress option.

### Two data sources

**1. The WordPress block registry** (`WP_Block_Type_Registry`)

Every block registered on the site appears here: core blocks, theme blocks, plugin blocks. The registry provides the block's title, description, keywords, attributes, and whether it is dynamic. This is the authoritative source for which blocks actually exist.

**2. `src/data/core-blocks.json`**

A hand-curated file covering the most commonly used core blocks. For each block it provides:

- A concise `description` written for an AI audience (not the editor UI)
- A `hint` — plain-English guidance on when to prefer this block over alternatives (e.g. "use `core/cover` for hero sections, not `core/group` with a background")
- A curated `attributes` list with human-readable `description` fields for each attribute
- An `example` showing a realistic attribute payload

This file is not exhaustive. It covers roughly 30 blocks. The remaining ~80 core blocks (FSE/template blocks, navigation internals, widget wrappers, etc.) come from the registry with auto-derived metadata.

### How the two sources are merged

`BlockCatalog::scan()` iterates every registered block. If the block name appears in `core-blocks.json`, the hand-curated data takes precedence. If not, metadata is derived directly from `WP_Block_Type`. Both paths produce a catalog entry with the same shape:

```json
{
  "name": "core/paragraph",
  "source": "core",
  "enabled": true,
  "title": "Paragraph",
  "description": "Basic text block for body content.",
  "hint": "Use for any body text...",
  "keywords": ["text", "body", "content"],
  "attributes": { "content": { "type": "string", "description": "..." } },
  "example": { "content": "Sample text." }
}
```

Theme and plugin blocks are classified as `"source": "custom"` and tagged `[CUSTOM]` in the AI prompt so the model knows to prefer them when they match the user's intent.

### When to extend `core-blocks.json`

Add or update an entry when:

- The auto-derived description is inaccurate or too generic for the AI to make good decisions
- A block is commonly confused with another and needs a disambiguating hint
- The attribute list from the registry is noisy (many internal attributes the AI should ignore)
- You want to provide a realistic `example` to steer the AI toward well-formed output

Do not add every block. Entries without meaningful hints or curated attribute descriptions add noise without improving AI output.

## Ability schema integration

Kratt can read attribute documentation directly from registered WordPress abilities. When Kratt finds an ability whose name matches a block in the catalog, it enriches that block's catalog entry with descriptions from the ability's `input_schema`. This lets the AI reliably populate non-text attributes (coordinates, zoom levels, enums, etc.) that would otherwise be left empty.

No special metadata is required in the ability registration. Kratt resolves the association automatically from the ability's name.

### How it works

`BlockCatalog::enrich_from_abilities()` runs at the end of every catalog scan. It:

1. Fires `wp_abilities_api_init` if it hasn't run yet, so all registered abilities are available.
2. Iterates every `WP_Ability` instance returned by `wp_get_abilities()`.
3. Resolves which block each ability belongs to by normalizing names (see below). If the match is ambiguous or absent, the ability is skipped.
4. Iterates the ability's `input_schema` properties, converts param names from `snake_case` to `camelCase`, and adds descriptions to matching block attributes. Params with descriptions but no matching block attribute are added as virtual attributes — the AI reads their descriptions and follows them.
5. Sets a hint on the block if it doesn't already have one, telling the AI that the listed attributes are safe to set.

The attribute filter in `format_for_prompt()` includes non-string attributes that have a description, making ability-documented attrs visible in the AI prompt.

### Name matching

The ability namespace (everything before the first `/`) is normalized by stripping all non-alphanumeric characters and lowercasing. Each block name (`namespace/slug`) is normalized the same way by concatenating its two parts. If exactly one block matches, the ability is associated with it. Zero or multiple matches mean the association is ambiguous and the ability is skipped.

Example: ability `ootb-openstreetmap/add-map-to-post` has namespace `ootb-openstreetmap`, which normalizes to `ootbopenstreetmap`. Block `ootb/openstreetmap` normalizes to the same string — unambiguous match.

### Making your block ability-aware

Simply register a WordPress ability using standard practices, with a well-named namespace that matches your block name. Kratt picks it up automatically:

```php
wp_register_ability(
    'my-plugin/add-my-block',  // namespace "my-plugin" matches block "my/plugin"
    [
        'label'            => __( 'Add My Block', 'my-plugin' ),
        'description'      => __( 'Inserts a my/plugin block into a post.', 'my-plugin' ),
        'execute_callback' => 'my_plugin_execute',
        'input_schema'     => [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'description' => '...' ],
                'zoom'    => [ 'type' => 'integer', 'description' => 'Zoom level (2-18).' ],
            ],
        ],
        'permission_callback' => fn() => current_user_can( 'edit_posts' ),
    ]
);
```

After installing or updating a plugin that registers an ability, trigger a catalog rescan via **Settings → Kratt → Rescan Blocks**. The catalog is also rebuilt automatically when any plugin or theme is activated.

### What gets included in the prompt

Only block attributes that exist in the block's registered schema are enriched — Kratt never invents attribute names. An attribute appears in the AI prompt when it meets any of these conditions:

- Its type is `string` (always included).
- It has an `enum` (shown as pipe-separated values).
- It has a non-empty `description` (ability-backed attributes always have descriptions).

Attributes that are integers, booleans, arrays, or objects without descriptions remain hidden from the AI, so the model cannot accidentally invent values for them.

## Block attribute transforms

Ability input_schema params do not always map 1:1 to block attributes. Virtual params like `lat`/`lng` may need converting to a different format (`bounds`), or a block may require companion attributes to be set alongside the primary one (e.g. `showDefaultBounds: false`). Kratt handles this with a WordPress filter applied after the AI response is decoded and validated.

### The filter

```php
apply_filters( 'kratt_block_attribute_transform', array $attributes, string $block_name )
```

Runs once per block in the AI response (including inner blocks), after unknown blocks are removed. Return the modified attributes array. The filter is also applied to the dummy response in test mode, so the full pipeline can be exercised without a live AI call.

### Built-in handlers

`BlockAttributeTransforms` ships handlers for blocks where the mismatch between ability params and block attributes is non-obvious. Currently:

- **`ootb/openstreetmap`** — converts `lat`/`lng` to `bounds: [[lat, lng]]` and sets `showDefaultBounds: false`. When `lat`/`lng` are absent (the AI omits them when placing markers, per the ability docs), derives `bounds` from the first marker instead.

### Adding your own handler

```php
add_filter(
    'kratt_block_attribute_transform',
    function ( array $attributes, string $block_name ): array {
        if ( 'my-plugin/my-block' !== $block_name ) {
            return $attributes;
        }
        // transform attributes here
        return $attributes;
    },
    10,
    2
);
```

The threshold for a built-in handler is: the block is widely used, the mismatch is non-obvious, and there is no other way to resolve it. Plugin authors can ship their own handler without any coordination with Kratt.

## The AI prompt

`PromptBuilder::build()` assembles the system prompt from:

1. The formatted catalog (`BlockCatalog::format_for_prompt()`) — one entry per enabled block with title, hint, keywords, attributes, and example
2. The current editor content — passed as read-only context so the AI knows what is already in the document
3. Static rules — the response format spec, attribute population rules, and layout patterns for common compound structures (hero, FAQ, card grid, etc.)

The prompt instructs the AI to respond with raw JSON only. `Client::compose()` decodes the response and returns it directly to the REST layer.

## Test mode

Define `KRATT_TEST_MODE` as `true` (in `wp-config.php` or the test bootstrap) to skip the AI call entirely. `Client::compose()` returns a deterministic dummy response — a heading and a paragraph — so you can develop and test without an API key or network access.

The dummy response passes through the full `apply_block_attribute_transforms()` pipeline, so registered transforms are exercised even in test mode.

To override which blocks the dummy returns — for example, to test a specific block's transform without touching the plugin — use the `kratt_dummy_response` filter:

```php
add_filter( 'kratt_dummy_response', function( array $blocks, string $prompt ): array {
    return [
        [
            'name'       => 'ootb/openstreetmap',
            'attributes' => [ 'markers' => [ [ 'lat' => 38.9519, 'lng' => 20.7322 ] ] ],
        ],
    ];
}, 10, 2 );
```

## Running tests

Install dependencies and run the full suite:

```bash
make test
```

Static analysis and coding standards only:

```bash
make lint
```

PHPUnit only (requires a WordPress test database):

```bash
bash bin/install-wp-tests.sh wordpress_test wordpress wordpress 127.0.0.1 latest
make phpunit
```

See the `Makefile` for Docker variants (`make test-docker`) if you prefer a containerised environment.
