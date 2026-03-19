=== Kratt ===
Contributors: gsarig
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI block composer for WordPress. Describe content in plain language and Kratt inserts the right blocks.

== Description ==

Kratt adds a sidebar panel to the Block Editor. Type a plain-language description of what you want to build, and Kratt sends it to the AI along with a catalog of every block available on your site. The AI returns a structured block specification; Kratt turns that into real blocks and inserts them at the cursor position.

The catalog is built from the WordPress block registry at activation time. It tells the AI exactly which blocks exist on your site, what they do, and which attributes are safe to populate. Core blocks use hand-curated descriptions for accuracy; theme and plugin blocks are detected automatically from the registry.

= Features =

* Natural language composition — describe layouts, sections, or single blocks in plain English
* Aware of all site blocks — catalog is built from the live block registry, including theme and plugin blocks
* Cursor-aware insertion — blocks are inserted after the currently selected block, or at the end of the document
* Nested block support — containers (columns, groups, covers) are assembled with their inner blocks intact
* Context-aware prompting — the current editor content is sent as read-only context, so the AI knows what is already there
* Allowed blocks respected — if the editor or post type restricts which blocks can be used, the AI only picks from that subset
* Additional instructions — add site-wide or per-post-type AI instructions via the settings page or the `kratt_system_instructions` filter hook

= Requirements =

* WordPress 7.0 or later
* PHP 8.1 or later
* An AI provider plugin compatible with the WP AI Client

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` and activate it via **Plugins → Installed Plugins**.
2. Install and activate an AI provider plugin (e.g. AI Provider for Anthropic).
3. Add your API key to `wp-config.php` as a PHP constant (e.g. `define( 'ANTHROPIC_API_KEY', 'sk-ant-...' );`).
4. Open any post or page in the Block Editor and click the Kratt icon in the top toolbar.

== Frequently Asked Questions ==

= Does Kratt store my content or send it to a third party? =

Kratt sends your prompt and a summary of the current editor content to the AI provider you have configured. What the provider does with that data is governed by their terms of service. Kratt itself stores nothing beyond the block catalog in your WordPress database.

= Can it create any block, or only the ones it knows about? =

Only blocks in the catalog. The AI is instructed never to invent block names, and if it cannot fulfil a request with the available blocks it returns an error with a suggestion of what to try instead.

= The catalog is out of date after I installed a new plugin. What do I do? =

Click **Rescan Blocks** on the Settings → Kratt page. The catalog is also rebuilt automatically when any plugin or theme is activated.

== Changelog ==

= 0.1.0 =
* Initial release.
