# Kratt

An Experimental WordPress AI block composer. Describe the content you want and Kratt inserts the right blocks, without requiring you to know block names or navigate the inserter.

*Read more: [Experimenting with AI-Assisted Block Composition in WordPress 7.0](https://www.gsarigiannidis.gr/kratt-ai-assisted-block-composition-in-wordpress/)*

[![Kratt demo](https://raw.githubusercontent.com/gsarig/kratt/main/.github/assets/kratt.gif)](https://www.youtube.com/watch?v=k9c3CU4qR9A)

*Click the image to watch the full demo on YouTube.*

> [!NOTE]
> **Kratt** is a household spirit from Estonian and Finnish mythology. According to legend, a Kratt is assembled from whatever scraps are at hand: straw, sticks, old tools. It is brought to life by making a pact with the devil. Once animated, it becomes an obedient servant that fetches things, carries loads, and does work on your behalf, tirelessly and unseen.

---

## How it works

Kratt adds a sidebar panel to the Block Editor. Type a plain-language description of what you want to build, and Kratt sends it to the AI along with a catalog of every block available on your site. The AI returns a structured block specification; Kratt turns that into real blocks and inserts them at the cursor position.

The catalog is built from the WordPress block registry at activation time. It tells the AI exactly which blocks exist on your site, what they do, and which attributes are safe to populate. Core blocks use hand-curated descriptions for accuracy; theme and plugin blocks are detected automatically from the registry.

---

## Features

- **Natural language composition** — describe layouts, sections, or single blocks in plain English
- **Aware of all site blocks** — catalog is built from the live block registry, including theme and plugin blocks
- **Cursor-aware insertion** — blocks are inserted after the currently selected block, or at the end of the document
- **Nested block support** — containers (columns, groups, covers) are assembled with their inner blocks intact
- **Context-aware prompting** — the current editor content is sent as read-only context, so the AI knows what's already there
- **Allowed blocks respected** — if the editor or post type restricts which blocks can be used, the AI only picks from that subset
- **Collapsible message history** — recent messages are always visible; older ones are grouped under a toggle
- **Test mode** — a `KRATT_TEST_MODE` constant lets you develop and style without burning API tokens

---

## Requirements

- WordPress 7.0 or later
- PHP 8.1 or later
- An AI provider plugin (see [Installation](#installation))

---

## Installation

### 1. Install Kratt

Download the latest release zip from the [Releases page](https://github.com/gsarig/kratt/releases), upload it to `wp-content/plugins/`, and activate it via **Plugins → Installed Plugins**.

### 2. Install an AI provider plugin

Kratt uses the WP AI Client, which is part of WordPress 7.0. To provide an actual AI model, you need a provider plugin. Available options:

- [AI Provider for Anthropic](https://wordpress.org/plugins/ai-provider-for-anthropic/)
- [AI Provider for Google](https://wordpress.org/plugins/ai-provider-for-google/)
- [AI Provider for OpenAI](https://wordpress.org/plugins/ai-provider-for-openai/)

Install and activate one of these.

### 3. Add your API key

Each provider plugin expects your API key as a PHP constant in `wp-config.php`. For Anthropic:

```php
define( 'ANTHROPIC_API_KEY', 'sk-ant-...' );
```

The provider plugin will pick this up automatically. No admin UI is needed.

### 4. Verify

After activation, open any post or page in the Block Editor. You should see a **Kratt** panel in the sidebar (the robot icon in the top toolbar). If you see an error notice instead, check that a provider plugin is active and the API key is set.

---

## Usage

Open a post or page in the Block Editor and click the Kratt icon in the top toolbar to open the sidebar.

Type what you want to build in the text area and press **Enter** (or click **Generate**). Examples:

- *"Add a hero section with a heading and a call-to-action button"*
- *"Create an FAQ with three questions about shipping"*
- *"Add a two-column layout with an image on the left and text on the right"*
- *"Insert the OpenStreetMap block"*

Kratt inserts the generated blocks after the currently selected block. If nothing is selected, blocks are added at the end of the document.

Shift+Enter adds a new line in the text area without submitting.

---

## Settings

Go to **Settings → Kratt** to see the block catalog and manage it.

### Block catalog

The catalog is the list of blocks Kratt knows about. It is built from the live WordPress block registry and stored in the database. The settings page shows:

- **Custom blocks** — blocks registered by themes or plugins, listed first
- **Core blocks** — all standard WordPress blocks, collapsed by default to keep the page manageable

Each entry shows the block's title, slug (e.g. `core/paragraph`), and description.

### Rescanning

The catalog is built automatically when Kratt is activated, and again whenever a plugin or theme is activated or switched. You can also trigger a manual rescan at any time by clicking **Rescan Blocks** on the settings page. This is useful after installing a new block plugin without deactivating and reactivating it.

---

## Constants

These PHP constants can be defined in `wp-config.php` to configure Kratt's behaviour.

### `ANTHROPIC_API_KEY`

Not a Kratt constant; it belongs to the AI provider plugin. It tells the provider which API key to use.

```php
define( 'ANTHROPIC_API_KEY', 'sk-ant-...' );
```

### `KRATT_TEST_MODE`

When set to `true`, Kratt skips the AI call entirely and returns a dummy response instead. Useful during theme development or UI work when you do not want to spend API credits.

```php
define( 'KRATT_TEST_MODE', true );
```

The dummy response always inserts a heading and a paragraph so you can verify that the sidebar, styling, and block insertion work correctly.

---

## FAQ

**Does Kratt store my content or send it to a third party?**

Kratt sends your prompt and a summary of the current editor content to the AI provider you have configured (e.g. Anthropic). What the provider does with that data is governed by their terms of service. Kratt itself stores nothing beyond the block catalog in your WordPress database.

**Can it create any block, or only the ones it knows about?**

Only blocks in the catalog. The AI is instructed never to invent block names, and if it cannot fulfil a request with the available blocks it returns an error with a suggestion of what to try instead.

**Why does my custom block appear in the catalog but produce an empty result?**

The AI fills in attributes it can generate with confidence: heading text, paragraph content, button labels. For everything else (media IDs, coordinate pairs, complex objects), it intentionally leaves attributes empty and lets WordPress use the block's registered defaults. An empty but valid block is always preferable to a block with invented attribute values that fail validation.

**The catalog is out of date after I installed a new plugin. What do I do?**

Click **Rescan Blocks** on the Settings → Kratt page. The catalog is also rebuilt automatically when any plugin or theme is activated.

**Can I restrict which blocks the AI can use?**

Not directly via Kratt. However, if your post type or editor setup restricts `allowedBlockTypes`, Kratt reads that setting and passes only the permitted blocks to the AI. Blocks outside that list will not be suggested.

**Where do I set the AI model or temperature?**

Those settings are controlled by the provider plugin, not Kratt. Kratt simply calls `wp_ai_client_prompt()` and lets the provider handle model selection and generation parameters.

---

## Filter hooks

### `kratt_system_instructions`

```php
apply_filters( 'kratt_system_instructions', string $instructions, array $context )
```

Filters the additional instructions appended to the Kratt AI system prompt on every compose request. The `$instructions` parameter starts as the value saved in **Settings → Kratt**; the filter lets you override or extend it per post type, post ID, or any other condition. `$context` contains `post_id` (int, 0 for unsaved posts) and `post_type` (string).

```php
add_filter(
    'kratt_system_instructions',
    function ( string $instructions, array $context ): string {
        if ( 'product' === $context['post_type'] ) {
            return $instructions . ' Always include a core/button with a "Buy now" label.';
        }
        return $instructions;
    },
    10,
    2
);
```

---

### `kratt_dummy_response`

```php
apply_filters( 'kratt_dummy_response', array $blocks, string $prompt )
```

Only fires when `KRATT_TEST_MODE` is `true`. Lets you override the blocks returned by the dummy response without editing the plugin — useful for testing how a specific block and its transforms behave end-to-end.

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

---

### `kratt_block_attribute_transform`

```php
// Parameters: array $attributes, string $block_name
// Returns:    array $attributes (modified)
apply_filters( 'kratt_block_attribute_transform', $attributes, $block_name );
```

Runs on every block in the AI response before it reaches the editor. Use this to convert AI-output attributes into the format the block actually expects — for example, mapping virtual ability params to a different attribute shape, or setting companion attributes that must change together.

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

Kratt ships a built-in handler for `ootb/openstreetmap`. See `CONTRIBUTING.md` for details on when and how to write your own.

---

## REST API

Kratt exposes two REST endpoints, both requiring authentication (`edit_posts` capability).

### `POST /wp-json/kratt/v1/compose`

Generates blocks from a natural language prompt.

**Request body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `prompt` | string | yes | The user's natural language instruction |
| `editor_content` | string | no | Serialized current editor content (read-only context) |
| `allowed_blocks` | string[] | no | List of block slugs permitted in the editor |

**Success response:**

```json
{
  "blocks": [
    { "name": "core/heading", "attributes": { "level": 2, "content": "Hello" } },
    { "name": "core/paragraph", "attributes": { "content": "World." } }
  ]
}
```

**Error response:**

```json
{
  "error": "No suitable block exists for that request.",
  "suggestion": "Try describing the content differently, or use a core/group to assemble it manually."
}
```

### `GET /wp-json/kratt/v1/catalog`

Returns the stored block catalog as JSON.

### `POST /wp-json/kratt/v1/catalog/rescan`

Triggers a rescan of the block registry and saves the result. Returns a message with the new block count.

---

## Abilities API

Kratt both registers and consumes the WordPress Abilities API.

### Registering an ability

Kratt registers a `kratt/insert-block` ability on the `wp_abilities_api_init` hook. This makes Kratt's block insertion capability discoverable by other plugins and by future WordPress tooling that queries what AI-related actions a site supports.

### Reading abilities to enrich the block catalog

When Kratt scans the block catalog, it also reads every registered ability and looks for ones that declare a `block_name` in their `meta`. When found, Kratt uses the ability's `input_schema` to add attribute documentation to that block's catalog entry.

This matters because the AI can only reliably populate attributes it has clear documentation for. Without ability metadata, non-text attributes (coordinates, zoom levels, provider enums, etc.) are hidden from the AI and left at their block defaults. With it, the AI can set those attributes correctly from the user's natural language description.

For example, the Out of the Box OpenStreetMap plugin registers an ability for its block. When Kratt scans the catalog on a site that has the plugin installed, the map block gains documented attributes:

- `zoom` (integer): Initial zoom level (2-18).
- `bounds` (array): Map centre as [[lat, lng]], e.g. [[37.97, 23.72]] for Athens.
- `provider` (openstreetmap|mapbox): Tile provider.
- `mapType` (marker|polygon|polyline): Map type.
- and more.

The AI can then insert a map pointing at a specific location when asked, rather than inserting an empty block with no centre set.

**For plugin authors:** to make your block's attributes AI-readable, simply register a WordPress ability using standard practices with a namespace that matches your block name. No special metadata is required — Kratt resolves the association automatically. See `CONTRIBUTING.md` for details.

---

## Name

**Kratt** is a household spirit from Estonian and Finnish mythology, which I came across watching a [weird Estonian film](https://en.wikipedia.org/wiki/November_(2017_film)) and found the concept fascinating. According to legend, a Kratt is assembled from whatever scraps are at hand: straw, sticks, old tools. It is brought to life by making a pact with the devil. Once animated, it becomes an obedient servant that fetches things, carries loads, and does work on your behalf, tirelessly and unseen.