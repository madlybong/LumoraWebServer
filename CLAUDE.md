# Claude Code Instructions — `@astrake/lumora-webserver`

## Build, Verify & Test Commands
- **Validate (Full CI Parity)**: `bun run validate` (Must pass before committing or releasing)
- **Typecheck**: `bun run check`
- **Test**: `bun test`
- **Build**: `bun run build`
- **12-Gate Verifier**: `bun run verify:starter` (or `bunx lumora-web verify` inside a consumer website)

## Architectural Guidelines
- **Headless PHP 8.2+ Micro-Kernel**: Keep 100% of brand configuration parameterized in `lumora.config.php`, `content/routes.php`, `content/channels.php`, and `content/legal/{slug}.php`. Never hardcode brand strings in `packages/core/runtime/php/core/`.
- **Read-Only `core/` in Consumer Websites**: Never hand-edit `<website>/core/*.php` or `<website>/public/assets/js/lumora-core.js` in consumer repos; they are materialized by `lumora-web sync` and verified by SHA-256 hashes in `lumora-lock.json` (Gate 01).
- **Direct-Access Guard**: Every PHP file (except `index.php` and `router.php`) must begin with `<?php`, `declare(strict_types=1);`, and the `if (!defined('LUMORA_BOOTSTRAPPED'))` 403 guard (Gates 02 & 03).
- **Zero Third-Party Leaks & A11y**: Never reference external font/script CDNs or trackers (Gate 05). Every `<img>` tag in `templates/**/*.php` must declare explicit `width`, `height`, and `alt` attributes (Gate 06).

## Code Style
- **TypeScript**: Strict mode. Never use `as any` to bypass type errors.
- **PHP**: PHP 8.2+ `declare(strict_types=1);`.
- **Commit Style**: Use Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:`).

## Release Flow
- Do not manually publish from a dirty tree.
- Update `VERSION`, run `bun run release:prep`, run `bun run validate`, and commit `chore(release): bump version to X.Y.Z`.
- Read `AGENTS.md` and `llms-full.txt` for the complete helper API and 12-Gate reference.
