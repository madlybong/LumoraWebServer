import path from "node:path";
import { evalPhpJson, toPosixPath } from "./paths";

export interface FlushOutboxOptions {
  cwd?: string;
  limit?: number;
  silent?: boolean;
}

export interface FlushOutboxResult {
  attempted: number;
  synced: number;
  failed: number;
}

export function flushOutbox(options: FlushOutboxOptions = {}): FlushOutboxResult {
  const siteRoot = path.resolve(options.cwd ?? process.cwd());
  const rootPosix = toPosixPath(siteRoot);
  const bootstrapPosix = toPosixPath(path.join(siteRoot, "core", "bootstrap.php"));
  const limit = Math.max(1, Math.min(100, options.limit ?? 25));

  const script = `
    define('LUMORA_BOOTSTRAPPED', true);
    require_once ${JSON.stringify(bootstrapPosix)};
    $cfg = \\Astrake\\Lumora\\Core\\Bootstrap::init(${JSON.stringify(rootPosix)});
    $res = \\Astrake\\Lumora\\Core\\HubClient::flushPendingOutbox($cfg, ${JSON.stringify(rootPosix)}, ${limit});
    echo json_encode($res);
  `;

  const result = evalPhpJson<FlushOutboxResult>(script, siteRoot);
  if (!options.silent) {
    console.log(
      `[lumora-web flush] Outbox replay complete: attempted=${result.attempted}, synced=${result.synced}, failed=${result.failed}`
    );
  }
  return result;
}
