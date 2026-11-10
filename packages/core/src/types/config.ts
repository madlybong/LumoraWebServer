import { z } from "zod";

export const LumoraAddressSchema = z.object({
  street: z.string().default(""),
  locality: z.string().default(""),
  region: z.string().default(""),
  postal_code: z.string().default(""),
  country: z.string().default("IN"),
});

export const LumoraBrandConfigSchema = z.object({
  id: z.string().min(2).max(64),
  prefix: z.string().min(2).max(8),
  name: z.string().min(2).max(120),
  legal_entity: z.string().min(2).max(160),
  domain: z.string().min(3).max(120),
  origin: z.string().url(),
  version: z.string().default("0.1.0"),
  locale: z.string().default("en-IN"),
  theme_color: z.string().default("#0a0a0a"),
  color_scheme: z.enum(["light", "dark"]).default("light"),
  category: z.string().default("BusinessApplication"),
  slogan: z.string().default(""),
  description: z.string().optional(),
  contact_email: z.string().email().optional(),
  security_email: z.string().email().optional(),
  address: LumoraAddressSchema.optional(),
});

export const LumoraSecurityConfigSchema = z.object({
  canonical_https_redirect: z.boolean().default(true),
  strip_www_redirect: z.boolean().default(true),
  trust_proxy_headers: z.boolean().default(false),
  csp_style_unsafe_inline: z.boolean().default(true),
  csp_media_self: z.boolean().default(false),
  rate_limit_max_attempts: z.number().int().min(1).max(100).default(5),
  rate_limit_window_sec: z.number().int().min(60).max(86400).default(900),
  csrf_ttl_sec: z.number().int().min(300).max(86400).default(7200),
});

export const LumoraAssetsConfigSchema = z.object({
  compiled_css: z.string().default("/public/assets/css/lumora.min.css"),
  core_js: z.string().default("/public/assets/js/lumora-core.js"),
  fonts: z.array(z.string()).default([]),
  vendor_js: z.array(z.string()).default([]),
});

export const LumoraHubSyncConfigSchema = z.object({
  enabled: z.boolean().default(false),
  endpoint: z.string().default(""),
  shared_secret: z.string().default(""),
  timeout_ms: z.number().int().min(500).max(10000).default(1500),
  fallback_notify: z.boolean().default(true),
});

export const LumoraLegalConfigSchema = z.object({
  territories: z.array(z.enum(["IN", "EU", "UK", "US", "GLOBAL"])).default(["IN", "EU", "GLOBAL"]),
  entity_type: z.string().default("Proprietorship"),
  grievance_officer: z.string().default("Grievance & Data Protection Officer"),
  grievance_email: z.string().optional(),
  effective_date: z.string().default("2026-11-10"),
  retention_days_pii: z.number().int().min(1).max(730).default(90),
  retention_days_logs: z.number().int().min(30).max(1095).default(180),
  governing_law: z.string().optional(),
});

export const LumoraFeaturesConfigSchema = z.object({
  docs_engine: z.boolean().default(false),
  blog_engine: z.boolean().default(false),
  release_server: z.boolean().default(false),
});

export const LumoraWebConfigSchema = z.object({
  brand: LumoraBrandConfigSchema,
  security: LumoraSecurityConfigSchema.optional(),
  assets: LumoraAssetsConfigSchema.optional(),
  hub: LumoraHubSyncConfigSchema.optional(),
  legal: LumoraLegalConfigSchema.optional(),
  features: LumoraFeaturesConfigSchema.optional(),
});

export type LumoraWebConfig = z.infer<typeof LumoraWebConfigSchema>;

export interface LumoraLockfile {
  framework: "@astrake/lumora-webserver";
  version: string;
  core_sha256: Record<string, string>;
  sri: Record<string, string>;
}
