# Development Workflow — `@astrake/lumora-webserver`

## Prerequisites

- **Bun** `>= 1.3.14`
- **PHP** `>= 8.2` on `PATH` with `pdo_sqlite`, `sqlite3`, `mbstring`, `curl`, and `openssl` enabled

## Local Setup

```bash
bun install
bun run validate
```

## Main Commands

Start the reference starter website (`apps/starter`) on `http://127.0.0.1:8080`:

```bash
bun run dev
```

Run the CLI directly:

```bash
bun run lumora-web --help
```

Sync PHP core and JS runtime into `apps/starter`:

```bash
bun run sync:starter
```

Build `packages/core` and `apps/starter/dist`:

```bash
bun run build
```

Run the 12-Gate Compliance Verifier against `apps/starter`:

```bash
bun run verify:starter
```

Sync versions from `VERSION`:

```bash
bun run version:sync
```

Generate a changelog entry for the current version:

```bash
bun run changelog
```

Prepare a release (`version:sync` + `changelog` in one step):

```bash
bun run release:prep
```

## Repo Conventions

- **Bun workspace** with two active projects:
  - `packages/core`: Published `@astrake/lumora-webserver` npm package (TypeScript CLI/verifier + PHP 8.2+ runtime + shared client ES module + base CSS).
  - `apps/starter`: Reference enterprise brand website dogfooding all 16 PHP core modules and verified against all 12 Compliance Gates.
- Root `tools/` scripts orchestrate typechecking, building, version synchronization, changelog generation, and the unified validation pipeline.

## Verification Rule

Before closing work or pushing a release, always run the full CI parity pipeline:

```bash
bun run validate
```

Which executes in sequence:
1. `bun run ./tools/check.ts` (TypeScript strict checks across `packages/core` and `apps/starter`)
2. `bun test` (Unit & PHP/CLI integration tests)
3. `bun run ./tools/build.ts` (Builds `packages/core/dist` and `apps/starter/dist`)
4. `bun run verify:starter` (Runs all 12 Compliance Gates against `apps/starter`)
5. `bun run version:sync` (Synchronizes `VERSION` across all workspace `package.json` files)
