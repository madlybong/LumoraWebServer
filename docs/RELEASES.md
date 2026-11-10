# Releases — `@astrake/lumora-webserver`

## Version source of truth

`@astrake/lumora-webserver` uses the root [`VERSION`](../VERSION) file as the single
centralized version source. All `package.json` files in the workspace are derived from it.

The following files are synchronized:

- [`package.json`](../package.json) — workspace root
- [`packages/core/package.json`](../packages/core/package.json) — published npm package (`@astrake/lumora-webserver`)
- [`apps/starter/package.json`](../apps/starter/package.json) — reference starter website

**Sync command:**

```bash
bun run version:sync
```

**Verify the repo is version-clean:**

```bash
bun run version:check
```

---

## Automated Release Flow

Releases are **fully automated** via the [`.github/workflows/release.yml`](../.github/workflows/release.yml) GitHub Actions workflow.

### Trigger: VERSION bump on `main`

The recommended release flow is:

1. Update the [`VERSION`](../VERSION) file (e.g., `0.1.0` → `0.2.0`).
2. Run `bun run release:prep` locally to sync versions and preview the changelog.
3. Run `bun run validate` to verify TypeScript, tests, builds, and all 12 Compliance Gates.
4. Commit: `git commit -am "chore(release): bump version to 0.2.0"`.
5. Push to `main`.

The workflow then automatically:

1. Detects the `VERSION` file change.
2. Sets up PHP 8.3, Bun 1.3.14, and Node 22 (`@astrake` scope).
3. Installs dependencies and syncs all package versions.
4. Builds `packages/core` and `apps/starter/dist`.
5. Runs TypeScript checks, unit/integration tests, and the 12-Gate Compliance Verifier (`bun run verify:starter`).
6. Generates and commits an updated `CHANGELOG.md`.
7. Creates a `vX.Y.Z` git tag.
8. Packs `packages/core` via `bun pm pack` (running `prepack` to bundle `README.md`, `CHANGELOG.md`, `llms.txt`, `llms-full.txt`, and `AGENTS.md`).
9. Creates a **GitHub Release** with the changelog excerpt and `.tgz` artifact attached.
10. Publishes `@astrake/lumora-webserver` to the public npm registry (`npm publish *.tgz --access public`). On the initial release, npm automatically creates the `@astrake/lumora-webserver` package under the `@astrake` organization.

### Trigger: Manual tag push or Workflow Dispatch

You can also trigger a release by pushing a `v*` tag manually or dispatching the workflow:

```bash
git tag v0.1.0
git push origin v0.1.0
# or via GitHub CLI:
gh workflow run release.yml -R madlybong/LumoraWebServer
```

---

## npm Registry Setup (`@astrake` Organization)

| Setting | Value |
|---------|-------|
| Registry | `https://registry.npmjs.org/` |
| Package | `@astrake/lumora-webserver` |
| Scope | `@astrake` |
| Access | `public` |

**Required GitHub secret:** `NPM_TOKEN` (same `@astrake` publish token used in `LumoraServer`)

Add it at: `https://github.com/madlybong/LumoraWebServer/settings/secrets/actions` (or via `gh secret set NPM_TOKEN -R madlybong/LumoraWebServer`).

> [!IMPORTANT]
> Keep `.npmrc` at the monorepo root only (never inside `packages/core/`). Inside GitHub Actions, `actions/setup-node@v4` configures `$NPM_CONFIG_USERCONFIG` with `scope: '@astrake'` and `NODE_AUTH_TOKEN: ${{ secrets.NPM_TOKEN }}`, and `npm publish *.tgz --access public` publishes the packed tarball without triggering npm workspace `ENOWORKSPACES` errors.

---

## Changelog Generation

The [`tools/changelog.ts`](../tools/changelog.ts) script generates changelog entries automatically:

```bash
bun run changelog
```

It reads git log since the last tag, groups commits by [Conventional Commits](https://www.conventionalcommits.org/) prefix,
and prepends a new `## [X.Y.Z] — YYYY-MM-DD` section to `CHANGELOG.md`.

**Commit prefix → section mapping:**

| Prefix | Section |
|--------|---------|
| `feat:` | Added |
| `fix:` | Fixed |
| `perf:`, `refactor:` | Changed |
| `docs:` | Documentation |
| `chore:`, `ci:`, `build:` | Maintenance |
| `BREAKING CHANGE` | ⚠ Breaking Changes |
