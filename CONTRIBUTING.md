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

## The AI prompt

`PromptBuilder::build()` assembles the system prompt from:

1. The formatted catalog (`BlockCatalog::format_for_prompt()`) — one entry per enabled block with title, hint, keywords, attributes, and example
2. The current editor content — passed as read-only context so the AI knows what is already in the document
3. Static rules — the response format spec, attribute population rules, and layout patterns for common compound structures (hero, FAQ, card grid, etc.)

The prompt instructs the AI to respond with raw JSON only. `Client::compose()` decodes the response and returns it directly to the REST layer.

## Test mode

Define `KRATT_TEST_MODE` as `true` (in `wp-config.php` or the test bootstrap) to skip the AI call entirely. `Client::compose()` returns a deterministic dummy response — a heading and a paragraph — so you can develop and test without an API key or network access.

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
