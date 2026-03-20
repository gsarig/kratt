# Copilot Instructions

## Project overview

Kratt is a WordPress plugin that adds an AI-powered block composer to the Block Editor.
Users describe what they want to build in plain language; Kratt calls the WP AI Client,
interprets the response, and inserts the appropriate blocks at the cursor position.

Two distinct layers:
- **PHP** — REST endpoints, AI client, block catalog, ability integration, settings
- **JavaScript** — Gutenberg sidebar UI (React/`@wordpress/` packages)

## Coding standards

- **CONTRIBUTING.md** is the authoritative source of coding rules for this project. All review
  comments must be consistent with it.
- PHP follows **WordPress Coding Standards** (enforced by PHPCS).
- PHP static analysis runs at **PHPStan level 6** — no errors allowed.
- JavaScript uses ES modules and `@wordpress/` packages; all user-visible strings use
  `@wordpress/i18n` (`__`, `_n`, `sprintf`) with the `kratt` text domain.
- All output must be escaped; all input must be sanitised and validated.

## What to focus on

Review for **correctness, security, and reliability** — in that order.

- Correctness: bugs, wrong return types, broken filter contracts, incorrect block attribute shapes
- Security: unvalidated input, data reaching the AI prompt without sanitisation, missing capability checks
- Reliability: unhandled AI response shapes, filter callbacks that may return unexpected types

## What to skip

- **Style**: indentation, quote style, naming conventions — enforced by PHPCS and ESLint. Do not comment on them.
- **Configurability**: do not suggest making intentional hardcoded values configurable unless there is a concrete correctness or security reason.
- **Speculative edge cases**: only flag an edge case if it is realistically reachable. Do not flag structurally unreachable paths.
- **Suggestions**: if a comment uses hedging language ("consider", "could", "might"), only raise it if it addresses a real defect.
- **Version numbers**: bumped in a dedicated release commit just before tagging. Do not flag version fields as inconsistent during feature branch reviews.

## Public API — breaking change alert

The following are part of the plugin's public API. Any change to them is a **breaking change** and must be flagged explicitly:

- PHP filter hooks: `kratt_block_attribute_transform`, `kratt_dummy_response`, `kratt_system_instructions`
- REST endpoints: `POST /kratt/v1/compose`, `GET /kratt/v1/catalog`, `POST /kratt/v1/catalog/rescan`

## Intentional design decisions

### Virtual attributes in the block catalog

Ability `input_schema` params that have no matching block attribute are added to the catalog
as virtual, prompt-only attributes. The AI reads their descriptions and sets them; a
`kratt_block_attribute_transform` handler then converts them to the real block attribute
format before the block reaches the editor. This is intentional — do not flag virtual
attributes as invented or undocumented.

### kratt_block_attribute_transform filter

Built-in handlers (e.g. for `ootb/openstreetmap`) convert AI-output attributes that
originate from ability params into the shape the block actually expects. The filter runs
after `filter_unknown_blocks()` and applies recursively to inner blocks. Returning a
non-array from a handler falls back to the original attributes — this is a deliberate
safety contract, not a silent failure.

### KRATT_TEST_MODE and kratt_dummy_response

When `KRATT_TEST_MODE` is `true`, `Client::compose()` returns a deterministic dummy
response instead of calling the AI. The `kratt_dummy_response` filter lets developers
override which blocks the dummy returns — for example, to test a specific block's
transform end-to-end without an API call. This is the intended testing pattern.

### Ability-to-block name matching

Kratt resolves which block an ability belongs to by normalising both the ability namespace
and block name (strip non-alphanumeric chars, lowercase, concatenate namespace+slug for
blocks). Exact match = associated; zero or multiple matches = skipped. This is
intentional — ambiguous matches are always skipped rather than guessed.
