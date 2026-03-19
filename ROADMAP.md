# Roadmap

Planned improvements, in rough priority order. Items are not committed to any release timeline.

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

## API

### Rate limiting on the compose endpoint
Any user with `edit_posts` can call `POST /kratt/v1/compose` without restriction. On multi-author sites this can run up API costs quickly. A simple per-user request counter stored in a transient (e.g. N requests per hour) would bound the exposure.

## Housekeeping

### Enable plugin-check in CI
The `plugin-check` job in `.github/workflows/ci.yml` is commented out because the plugin declares `Requires at least: 7.0` and the plugin-check action cannot activate it against the current stable WordPress. Uncomment the job once WordPress 7.0 is released.
