import { describe, expect, test } from "bun:test";
import path from "node:path";
import { evalPhpJson, resolveRuntimeDir, toPosixPath } from "../src/cli/paths";
import {
  LumoraInquiryWirePayloadSchema,
  LumoraWebConfigSchema,
  signLumoraWebhookPayload,
  verifyLumoraWebhookSignature,
} from "../src/types/index";

describe("TypeScript Schemas & Cross-Language HMAC Wire Protocol", () => {
  test("validates LumoraWebConfigSchema with defaults", () => {
    const parsed = LumoraWebConfigSchema.parse({
      brand: {
        id: "auruvi",
        prefix: "AUR",
        name: "Auruvi HMS",
        legal_entity: "Astrake India",
        domain: "auruvi.com",
        origin: "https://auruvi.com",
      },
    });
    expect(parsed.brand.id).toBe("auruvi");
    expect(parsed.brand.locale).toBe("en-IN");
  });

  test("validates LumoraInquiryWirePayloadSchema", () => {
    const wire = LumoraInquiryWirePayloadSchema.parse({
      schema_version: "1.0",
      brand_id: "astrake",
      brand_name: "Astrake",
      domain: "astrake.com",
      inquiry: {
        receipt_id: "AST-20261110-8F3A",
        channel: "general",
        channel_label: "Business & Project Inquiry",
        full_name: "Aarav Sen",
        email: "aarav@astrake.com",
        subject: "Platform Inquiry",
        message: "We would like to discuss an enterprise deployment.",
        ip_hash: "a1b2c3d4e5f60718293a4b5c6d7e8f90",
        created_at: "2026-11-10T12:00:00Z",
      },
    });
    expect(wire.inquiry.receipt_id).toBe("AST-20261110-8F3A");
    expect(wire.inquiry.consultation_mode).toBe("online");
  });

  test("TypeScript and PHP HubClient produce identical HMAC-SHA256 signatures", () => {
    const secret = "lumora_shared_hmac_secret_key_for_wire_test";
    const ts = String(Math.floor(Date.now() / 1000));
    const body = JSON.stringify({
      schema_version: "1.0",
      brand_id: "astrake",
      inquiry: { receipt_id: "AST-20261110-ABCD" },
    });

    const tsSig = signLumoraWebhookPayload(body, ts, secret);
    expect(verifyLumoraWebhookSignature(body, ts, tsSig, secret)).toBe(true);
    expect(verifyLumoraWebhookSignature(body, ts, tsSig, "wrong_secret")).toBe(false);
    expect(verifyLumoraWebhookSignature(body, String(Number(ts) - 1000), tsSig, secret)).toBe(false);

    const hubClientPhp = toPosixPath(path.join(resolveRuntimeDir(), "php", "core", "hub-client.php"));
    const phpRes = evalPhpJson<{ sig: string; verified: boolean }>(
      `
      define('LUMORA_BOOTSTRAPPED', true);
      require_once ${JSON.stringify(hubClientPhp)};
      $body = ${JSON.stringify(body)};
      $ts = ${JSON.stringify(ts)};
      $secret = ${JSON.stringify(secret)};
      $sig = \\Astrake\\Lumora\\Core\\HubClient::signPayload($body, $ts, $secret);
      $verified = \\Astrake\\Lumora\\Core\\HubClient::verifySignature($body, $ts, $sig, $secret);
      echo json_encode(['sig' => $sig, 'verified' => $verified]);
      `,
      process.cwd()
    );

    expect(phpRes.sig).toBe(tsSig);
    expect(phpRes.verified).toBe(true);
  });
});
