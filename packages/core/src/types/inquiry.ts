import { createHmac, timingSafeEqual } from "node:crypto";
import { z } from "zod";

export const LumoraInquiryRecordSchema = z.object({
  receipt_id: z.string().regex(/^[A-Z0-9]{2,8}-\d{8}-[A-F0-9]{4,8}$/),
  brand_id: z.string().min(2).max(64).optional(),
  channel: z.string().min(2).max(64),
  channel_label: z.string().min(2).max(160),
  sla_window: z.string().max(64).optional().default("1-2 Business Days"),
  full_name: z.string().min(2).max(120),
  organization: z.string().max(160).optional().default(""),
  email: z.string().email().max(160),
  phone: z.string().max(30).optional().default(""),
  subject: z.string().min(2).max(240),
  message: z.string().min(5).max(4000),
  consultation_mode: z.enum(["online", "onsite"]).optional().default("online"),
  facility_location: z.string().max(200).optional().default(""),
  details: z.record(z.string(), z.string()).optional().default({}),
  ip_hash: z.string().min(16).max(64),
  user_agent: z.string().max(300).optional().default(""),
  created_at: z.string(),
});

export const LumoraInquiryWirePayloadSchema = z.object({
  schema_version: z.literal("1.0"),
  brand_id: z.string().min(2).max(64),
  brand_name: z.string().min(2).max(120),
  domain: z.string().min(3).max(120),
  inquiry: LumoraInquiryRecordSchema,
});

export type LumoraInquiryRecord = z.infer<typeof LumoraInquiryRecordSchema>;
export type LumoraInquiryWirePayload = z.infer<typeof LumoraInquiryWirePayloadSchema>;

/**
 * Computes the canonical `X-Lumora-Signature` header (`sha256=<hex>`) for a raw JSON body and Unix timestamp.
 */
export function signLumoraWebhookPayload(rawBody: string, timestamp: string | number, secret: string): string {
  const ts = String(timestamp);
  return "sha256=" + createHmac("sha256", secret).update(`${ts}.${rawBody}`).digest("hex");
}

/**
 * Verifies an incoming `X-Lumora-Signature` and `X-Lumora-Timestamp` against replay (default 300s window) and tampering.
 */
export function verifyLumoraWebhookSignature(
  rawBody: string,
  timestamp: string,
  signatureHeader: string,
  secret: string,
  maxAgeSeconds = 300
): boolean {
  const tsNum = Number(timestamp);
  if (!secret || !Number.isFinite(tsNum) || Math.abs(Math.floor(Date.now() / 1000) - tsNum) > maxAgeSeconds) {
    return false;
  }
  const expected = signLumoraWebhookPayload(rawBody, timestamp, secret);
  const a = Buffer.from(expected, "utf8");
  const b = Buffer.from(signatureHeader, "utf8");
  return a.length === b.length && timingSafeEqual(a, b);
}
