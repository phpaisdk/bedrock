# Changelog

Notable changes to `aisdk/bedrock`. Release notes are also published at https://github.com/phpaisdk/bedrock/releases.

## v0.11.0 — 2026-10-31

- Requires `aisdk/core ^0.11.0` and `aisdk/openai-compatible ^0.11.0`. Upgrade SDK packages together; no other changes in this package.

## v0.10.2 — 2026-10-29

- Include cache reads and writes in prompt usage, retain cache usage across Anthropic streaming events, and preserve provider totals.

## v0.10.1 — 2026-10-29

- Raise SDK exceptions instead of native PHP ones.

## v0.10.0 — 2026-10-29

### What's new

- Unset `maxTokens` and `temperature` are omitted so the provider's own defaults apply.
- Works with the 0.10 SDK, including the new agent, failover and caching APIs in `aisdk/core`.

### Compatibility

- Works with `aisdk/core ^0.10.0` and `aisdk/openai-compatible ^0.10.0` where applicable. Upgrade SDK packages together.

## Earlier releases

See the [GitHub releases](https://github.com/phpaisdk/bedrock/releases) for versions before 0.10.0.
