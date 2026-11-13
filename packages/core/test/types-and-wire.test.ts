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
  test("validates LumoraWebConfigSchema with globally neutral defaults and optional legal_entity", () => {
    const parsed = LumoraWebConfigSchema.parse({
      brand: {
        id: "acme",
        prefix: "ACM",
        name: "Acme Platform",
        domain: "acme.example.com",
        origin: "https://acme.example.com",
      },
    });
    expect(parsed.brand.id).toBe("acme");
    expect(parsed.brand.legal_entity).toBeUndefined();
    expect(parsed.brand.locale).toBe("en");
    expect(parsed.brand.schema_org_type).toBe("Organization");
    expect(parsed.brand.framework_label).toBe("lumora-webserver");
    expect(parsed.brand.expose_legal_entity_in_schema).toBe(false);
    expect(parsed.brand.expose_legal_entity_in_llms).toBe(false);
  });

  test("validates LumoraLegalConfigSchema and LumoraDiscoveryConfigSchema defaults", () => {
    const parsed = LumoraWebConfigSchema.parse({
      brand: {
        id: "creator",
        prefix: "CRT",
        name: "Alex Rivera",
        domain: "alex.example.com",
        origin: "https://alex.example.com",
        schema_org_type: "Person",
      },
      legal: {},
      discovery: {},
      features: {},
    });
    expect(parsed.legal?.territories).toEqual(["GLOBAL"]);
    expect(parsed.legal?.entity_type).toBe("");
    expect(parsed.legal?.grievance_officer).toBe("");
    expect(parsed.legal?.retention_days_pii).toBe(90);
    expect(parsed.discovery?.health).toBe(true);
    expect(parsed.features?.legal_registry).toBe(true);
    expect(parsed.features?.inquiry_engine).toBe(true);
  });

  test("validates LumoraInquiryWirePayloadSchema v1.0 and v1.1 with attribution", () => {
    const wire = LumoraInquiryWirePayloadSchema.parse({
      schema_version: "1.1",
      brand_id: "acme",
      brand_name: "Acme",
      domain: "acme.example.com",
      inquiry: {
        receipt_id: "ACM-20261110-8F3A",
        channel: "general",
        channel_label: "General Inquiry",
        full_name: "Alex Rivera",
        email: "alex@example.com",
        subject: "Platform Inquiry",
        message: "We would like to discuss an installation.",
        details: {
          _attribution: {
            utm_source: "newsletter",
            cta_id: "hero-primary",
          },
        },
        attribution: {
          utm_source: "newsletter",
          landing_page: "/pricing/",
          cta_id: "hero-primary",
        },
        ip_hash: "a1b2c3d4e5f60718293a4b5c6d7e8f90",
        created_at: "2026-11-10T12:00:00Z",
      },
    });
    expect(wire.schema_version).toBe("1.1");
    expect(wire.inquiry.receipt_id).toBe("ACM-20261110-8F3A");
    expect(wire.inquiry.consultation_mode).toBe("online");
    expect(wire.inquiry.attribution?.utm_source).toBe("newsletter");
  });

  test("TypeScript and PHP HubClient produce identical HMAC-SHA256 signatures under Lumora\\Core and legacy alias", () => {
    const secret = "lumora_shared_hmac_secret_key_for_wire_test";
    const ts = String(Math.floor(Date.now() / 1000));
    const body = JSON.stringify({
      schema_version: "1.0",
      brand_id: "acme",
      inquiry: { receipt_id: "ACM-20261110-ABCD" },
    });

    const tsSig = signLumoraWebhookPayload(body, ts, secret);
    expect(verifyLumoraWebhookSignature(body, ts, tsSig, secret)).toBe(true);
    expect(verifyLumoraWebhookSignature(body, ts, tsSig, "wrong_secret")).toBe(false);
    expect(verifyLumoraWebhookSignature(body, String(Number(ts) - 1000), tsSig, secret)).toBe(false);

    const hubClientPhp = toPosixPath(path.join(resolveRuntimeDir(), "php", "core", "hub-client.php"));
    const phpRes = evalPhpJson<{ sig: string; verified: boolean; alias_works: boolean }>(
      `
      define('LUMORA_BOOTSTRAPPED', true);
      require_once ${JSON.stringify(hubClientPhp)};
      $body = ${JSON.stringify(body)};
      $ts = ${JSON.stringify(ts)};
      $secret = ${JSON.stringify(secret)};
      $sig = \\Lumora\\Core\\HubClient::signPayload($body, $ts, $secret);
      $verified = \\Lumora\\Core\\HubClient::verifySignature($body, $ts, $sig, $secret);
      $aliasWorks = class_exists(\\Astrake\\Lumora\\Core\\HubClient::class)
        && \\Astrake\\Lumora\\Core\\HubClient::verifySignature($body, $ts, $sig, $secret);
      echo json_encode(['sig' => $sig, 'verified' => $verified, 'alias_works' => $aliasWorks]);
      `,
      process.cwd()
    );

    expect(phpRes.sig).toBe(tsSig);
    expect(phpRes.verified).toBe(true);
    expect(phpRes.alias_works).toBe(true);
  });
});
