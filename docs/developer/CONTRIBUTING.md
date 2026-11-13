# Contributing Guide — `LumoraWebServer`

This guide covers developing, extending, testing, and releasing `@astrake/lumora-webserver` inside the `LumoraWebServer` monorepo.

---

## 1. Development Environment Setup

### Prerequisites
- **Bun `1.3+`** (`bun -v`)
- **PHP `8.2+`** (`php -v`) with `pdo_sqlite`, `curl`, and `mbstring` extensions enabled

### Initial Setup
```bash
bun install
bun run validate
```

---

## 2. Monorepo Structure & Responsibilities

| Directory | Package / Purpose |
| :--- | :--- |
| `packages/core/runtime/php/core/` | The 13 headless PHP 8.2+ runtime classes (`Lumora\Core`, with legacy `Astrake\Lumora\Core` alias). |
| `packages/core/runtime/php/stubs/` | Canonical root stubs (`index.stub.php`, `router.stub.php`, `htaccess.stub.conf`, `nginx.stub.conf`). |
| `packages/core/runtime/css/` | `lumora-base.css` (Tailwind CSS v4 entry + `@theme` structural primitives + accessibility rules). |
| `packages/core/runtime/js/` | `lumora-core.js` (Client ES module for motion tiering, mobile navigation, and form validation). |
| `packages/core/src/types/` | Zod schemas (`LumoraWebConfigSchema`, `LumoraInquiryWirePayloadSchema`) and HMAC signature helpers. |
| `packages/core/src/cli/` | TypeScript implementation of `lumora-web` commands (`init`, `sync`, `css`, `dev`, `build`, `verify`, `flush`). |
| `apps/starter/` | Dogfooding reference website (`lumora-web-starter`) exercising all framework features and all 12 compliance gates. |
| `tools/` | Workspace automation (`build.ts`, `check.ts`, `version.ts`, `changelog.ts`, `validate.ts`). |

---

## 3. Coding Conventions & Architectural Invariants

1. **Strict PHP 8.2+ & Bootstrap Isolation:**
   - Every PHP file in `packages/core/runtime/php/core/` and `apps/starter/` must declare `declare(strict_types=1);` immediately after `<?php`.
   - Every PHP file outside `index.stub.php` and `router.stub.php` must enforce the `LUMORA_BOOTSTRAPPED` guard (`if (!defined('LUMORA_BOOTSTRAPPED')) { http_response_code(403); exit('Forbidden'); }`).
   - When a core PHP file defines both classes in `Lumora\Core` and global procedural helpers (`e()`, `u()`, `lumora_csrf_token()`, etc.), wrap both blocks in bracketed namespace declarations (`namespace Lumora\Core { ... }` and `namespace { ... }`) and provide a backward-compatible `class_alias(\Lumora\Core\<Class>::class, \Astrake\Lumora\Core\<Class>::class)`.
2. **Zero Brand Hardcoding in `packages/core/runtime/`:**
   - Never hardcode brand-specific routes, inquiry fields, or entity details in `packages/core/runtime/`. Everything must be parameterized via `lumora.config.php`, `content/routes.php`, `content/channels.php`, and `content/legal/{slug}.php`.
3. **Syncing Changes to `apps/starter`:**
   - Whenever you modify any file in `packages/core/runtime/`, run `bun run build` (or `bun run validate`) so `lumora-web sync` updates `apps/starter/core/`, `apps/starter/public/assets/js/lumora-core.js`, and `apps/starter/lumora-lock.json`.

---

## 4. Release & Versioning Workflow

1. Update the version string in the root `VERSION` file (or pass `--set <semver>` to `tools/version.ts`):
   ```bash
   bun run ./tools/version.ts --set 0.2.0
   ```
2. Generate or update `CHANGELOG.md`:
   ```bash
   bun run changelog
   ```
3. Run the full validation pipeline (`check` → `test` → `build` → `verify:starter` → `version:sync`):
   ```bash
   bun run validate
   ```
4. Publish `packages/core` (`prepack` automatically copies root `README.md`, `CHANGELOG.md`, `llms.txt`, `llms-full.txt`, and `AGENTS.md` into `packages/core/` for NPM packaging, and `postpack` cleans them up):
   ```bash
   cd packages/core
   bun publish --access public
   ```
