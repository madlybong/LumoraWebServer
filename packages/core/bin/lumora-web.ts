#!/usr/bin/env bun
import path from "node:path";
import { buildWebsiteDist } from "../src/cli/build-dist";
import { compileBrandCss } from "../src/cli/css-compiler";
import { runDevServer } from "../src/cli/dev";
import { flushOutbox } from "../src/cli/flush";
import { initWebsite } from "../src/cli/init";
import { resolvePackageVersion } from "../src/cli/paths";
import { syncWebsite } from "../src/cli/sync";
import { verifyWebsite } from "../src/cli/verify";

function parseArgs(argv: string[]): {
  command: string;
  cwd: string;
  port: number;
  forceStubs: boolean;
  withAgents: boolean;
  brandId?: string;
  brandName?: string;
  brandPrefix?: string;
  domain?: string;
  legalEntity?: string;
} {
  let command = "help";
  let cwd = process.cwd();
  let port = 8080;
  let forceStubs = false;
  let withAgents = false;
  let brandId: string | undefined;
  let brandName: string | undefined;
  let brandPrefix: string | undefined;
  let domain: string | undefined;
  let legalEntity: string | undefined;

  const positional: string[] = [];
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i]!;
    if (arg === "--cwd" && argv[i + 1]) {
      cwd = path.resolve(argv[++i]!);
    } else if (arg.startsWith("--cwd=")) {
      cwd = path.resolve(arg.slice("--cwd=".length));
    } else if (arg === "--port" && argv[i + 1]) {
      port = Number(argv[++i]!) || 8080;
    } else if (arg.startsWith("--port=")) {
      port = Number(arg.slice("--port=".length)) || 8080;
    } else if (arg === "--brand-id" && argv[i + 1]) {
      brandId = argv[++i]!;
    } else if (arg === "--brand-name" && argv[i + 1]) {
      brandName = argv[++i]!;
    } else if (arg === "--prefix" && argv[i + 1]) {
      brandPrefix = argv[++i]!;
    } else if (arg === "--domain" && argv[i + 1]) {
      domain = argv[++i]!;
    } else if (arg === "--entity" && argv[i + 1]) {
      legalEntity = argv[++i]!;
    } else if (arg === "--force-stubs") {
      forceStubs = true;
    } else if (arg === "--with-agents") {
      withAgents = true;
    } else if (arg === "-v" || arg === "--version") {
      command = "version";
    } else if (arg === "-h" || arg === "--help") {
      command = "help";
    } else if (!arg.startsWith("-")) {
      positional.push(arg);
    }
  }

  if (positional[0] && command === "help") {
    command = positional[0];
  }

  return { command, cwd, port, forceStubs, withAgents, brandId, brandName, brandPrefix, domain, legalEntity };
}

function printHelp(): void {
  const version = resolvePackageVersion();
  console.log(`
@astrake/lumora-webserver CLI v${version}

Usage:
  lumora-web <command> [options]

Commands:
  init      Scaffold a new Lumora PHP + Tailwind v4 website
  sync      Materialize locked PHP core & JS runtime and update lumora-lock.json
  css       Compile Tailwind CSS v4 + brand layers into public/assets/css/lumora.min.css
  dev       Run local PHP 8.2+ dev server + live Tailwind CSS watcher
  build     Sync runtime, compile CSS, and package zero-dependency dist/ bundle
  verify    Run the 12-Gate Automated Compliance & Build Verifier
  flush     Replay pending SQLite WAL outbox inquiries to the configured Hub webhook

Options:
  --cwd <dir>      Target website directory (defaults to current working directory)
  --port <port>    Port for 'dev' server (default: 8080)
  --force-stubs    Overwrite root index.php, router.php, and .htaccess during 'sync'
  --with-agents    Copy/refresh AGENTS.md and .cursorrules in the target website directory
  -v, --version    Show CLI version
  -h, --help       Show this help message
`);
}

async function main(): Promise<void> {
  const { command, cwd, port, forceStubs, withAgents, brandId, brandName, brandPrefix, domain, legalEntity } = parseArgs(
    process.argv.slice(2)
  );

  switch (command) {
    case "version":
      console.log(resolvePackageVersion());
      return;
    case "init":
      await initWebsite({ cwd, brandId, brandName, brandPrefix, domain, legalEntity, withAgents });
      return;
    case "sync":
      syncWebsite({ cwd, forceStubs, withAgents });
      return;
    case "css":
      await compileBrandCss({ cwd, minify: true });
      return;
    case "dev":
      await runDevServer({ cwd, port });
      return;
    case "build":
      await buildWebsiteDist({ cwd });
      return;
    case "verify": {
      const report = await verifyWebsite({ cwd });
      if (!report.passed) {
        process.exit(1);
      }
      return;
    }
    case "flush":
      flushOutbox({ cwd });
      return;
    case "help":
    default:
      printHelp();
      return;
  }
}

main().catch((err) => {
  console.error("[lumora-web] Error:", err instanceof Error ? err.message : err);
  process.exit(1);
});
