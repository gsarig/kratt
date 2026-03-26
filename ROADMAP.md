# Roadmap

Planned improvements, in rough priority order. Items are not committed to any release timeline.

## Content review

### Add a "Review" action that audits existing editor content

A dedicated review action — a separate button in the sidebar alongside the compose input — would let the user ask Kratt to read the current editor content and return a structured list of suggestions, issues, or improvements rather than new blocks to insert.

This reuses the same content-reading foundation as the compose flow but inverts the intent: instead of adding something new, the AI evaluates what is already there. Possible review categories include structural issues (heading hierarchy gaps, missing captions, empty blocks), accessibility concerns (images without alt text, insufficient heading contrast for screen readers), consistency problems (mixed tone, inconsistent button labels across a layout), and blocks that appear misconfigured given their context.

The output format is the open design question. The simplest version is a plain list of findings rendered in the sidebar. A more useful version would pair each finding with a one-click fix that triggers a targeted compose request to correct it. The latter requires a new response shape and tighter coordination between the sidebar UI and the REST layer, making it a larger cross-layer feature.

This overlaps with the content-aware insertion entry below: both depend on the AI reasoning about existing content rather than just generating new blocks. A shared "content analysis" step in the compose flow could serve both.

## Content-aware insertion

### Reason about existing content when deciding what to insert

The editor content is already sent to the AI on every request, so the AI can see what blocks are present. What is missing is explicit instruction to reason structurally about that content when generating new blocks.

The most immediate case is heading hierarchy: if the editor already contains an H2, the AI should inspect that context and decide whether a new heading should be another H2 or an H3, based on what the user asked for and where it fits in the document structure. This is a prompt-level improvement — a new rule in `PromptBuilder` — and requires no architecture change.

The more complex case is constraint-based warnings. A third-party plugin or theme might declare, via the `kratt_system_instructions` filter, that a specific block should appear only once per post. Today the AI would simply insert it again. The desired behaviour is for the AI to detect the conflict, skip the insertion, and surface a warning to the user before anything is added.

That requires a new response shape alongside the existing `blocks` and `error` keys: something like a `warning` field that the sidebar intercepts and presents as a confirmation step. The user would then choose to proceed or cancel. This touches the REST response format, the JS sidebar, and the system prompt, so it is a real cross-layer feature rather than a prompt tweak.

## Patterns

### Use patterns before assembling from blocks

When the user's request can be satisfied by an existing block pattern registered on the site, Kratt should prefer the pattern over assembling an equivalent structure from individual blocks. Patterns are pre-designed compositions that are often better structured and better styled than anything the AI would assemble from scratch, so using them when they are a good fit produces a more coherent result.

The implementation would involve including registered patterns in the prompt context alongside the block catalog, instructing the AI to prefer a pattern match when one is relevant, and inserting the pattern's block content rather than a block list when the AI selects one.

This also covers theme patterns, which encode design decisions (spacing, typography scale, color pairings) that the AI cannot reproduce by assembling raw blocks.

## Block catalog

### Enable / disable individual blocks
The `enabled` flag already exists on every catalog entry and `format_for_prompt()` already skips disabled blocks. What is missing is a UI to toggle it. The settings page block table should gain a toggle per row that saves the enabled state back to the stored catalog option.

### Rescan on plugin deactivation
`BlockCatalog::scan()` is triggered on `activated_plugin` and `after_switch_theme`, so the catalog stays fresh when new blocks are added. The reverse is not handled: deactivating a plugin that registered blocks leaves stale entries in the catalog until a manual rescan. Add a `deactivated_plugin` hook to cover this case.

## AI prompt

### Cap catalog size sent to the AI
On large sites, the full formatted catalog can be several thousand tokens per request. Possible mitigations:
- Allow hiding entire sources (e.g. exclude all core blocks if the site only uses custom ones)
- Apply a per-request relevance filter based on the prompt text

### Prompt caching
Every compose request sends the full system prompt (block catalog + rules) to the AI provider. Anthropic supports prompt caching, which bills cached input tokens at ~10% of the normal rate. The WP AI Client abstraction layer does not currently expose caching controls, so this requires either waiting for upstream support or bypassing `wp_ai_client_prompt` for a direct SDK call. Worth revisiting once the WP AI Client matures.

## Known limitations

### Focused review is not wired in the UI

`ReviewController` accepts a `focus` parameter that narrows the AI's review to a specific concern. The Review button in the sidebar is disabled whenever the textarea has text, so there is no way to trigger a focused review from the UI today. The API is ready; the sidebar just needs to stop disabling Review when text is present and forward the textarea value as `focus` rather than as a compose prompt.

### Editor content truncation in compose and review requests

Both `ComposeController` and `ReviewController` cap `editor_content` at 8000 characters before forwarding it to the AI. Content beyond that limit is silently dropped. For compose requests this is mostly harmless — the editor content is read-only context. For review requests it is a real problem: blocks beyond the cut-off are never seen by the AI, which will then produce findings only for the visible portion without any indication that the review is incomplete.

Options to consider:

- Refuse the request outright when content exceeds the limit and surface a clear message ("The editor content is too long to review in full — try selecting a range of blocks first").
- Truncate at a block boundary rather than mid-character, and inject a note into the prompt so the AI knows it is working with a partial view.
- Split into multiple requests and merge findings (complex, not recommended for a first pass).

The right trade-off depends on how common very long posts are in practice. Flagging as a known limitation until a decision is made.

## API

### Rate limiting on the compose endpoint
Any user with `edit_posts` can call `POST /kratt/v1/compose` without restriction. On multi-author sites this can run up API costs quickly. A simple per-user request counter stored in a transient (e.g. N requests per hour) would bound the exposure.

## Housekeeping

### Enable plugin-check in CI
The `plugin-check` job in `.github/workflows/ci.yml` is commented out because the plugin declares `Requires at least: 7.0` and the plugin-check action cannot activate it against the current stable WordPress. Uncomment the job once WordPress 7.0 is released.
