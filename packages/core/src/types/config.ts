import { z } from "zod";

export const LumoraAddressSchema = z.object({
  street: z.string().default(""),
  locality: z.string().default(""),
  region: z.string().default(""),
  postal_code: z.string().default(""),
  country: z.string().default(""),
});

export const LumoraContactPointSchema = z.object({
  url: z.string(),
  contact_type: z.string(),
  email: z.string().optional(),
  available_language: z.array(z.string()).default(["English"]),
});

export const LumoraBrandConfigSchema = z.object({
  id: z.string().min(2).max(64),
  prefix: z.string().min(2).max(8),
  name: z.string().min(2).max(120),
  legal_entity: z.string().max(160).optional(),
  domain: z.string().min(3).max(120),
  origin: z.string().url(),
  version: z.string().default("0.1.2"),
  locale: z.string().default("en"),
  theme_color: z.string().default("#0a0a0a"),
  color_scheme: z.enum(["light", "dark"]).default("light"),
  category: z.string().default("BusinessApplication"),
  operating_system: z.string().optional(),
  schema_org_type: z
    .enum(["Organization", "Person", "LocalBusiness", "Corporation", "ProfessionalService"])
    .default("Organization"),
  framework_label: z.string().default("lumora-webserver"),
  expose_legal_entity_in_schema: z.boolean().default(false),
  expose_legal_entity_in_llms: z.boolean().default(false),
  same_as: z.array(z.string().url()).default([]),
  contact_points: z.array(LumoraContactPointSchema).optional(),
  primary_offering_schema: z.boolean().default(false),
  emit_brand_node: z.boolean().optional(),
  logo: z.string().optional(),
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
  compat_constants: z.array(z.string().regex(/^[A-Z][A-Z0-9_]{2,63}$/)).default([]),
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
  territories: z.array(z.enum(["IN", "EU", "UK", "US", "GLOBAL"])).default(["GLOBAL"]),
  entity_type: z.string().default(""),
  grievance_officer: z.string().default(""),
  grievance_email: z.string().optional(),
  registered_address: z.string().optional(),
  effective_date: z.string().default("2026-11-10"),
  retention_days_pii: z.number().int().min(0).max(3650).default(90),
  retention_days_logs: z.number().int().min(0).max(3650).default(180),
  governing_law: z.string().optional(),
  slugs: z.array(z.string().regex(/^[a-z0-9-]+$/)).optional(),
  include_default_slugs: z.boolean().default(true),
});

export const LumoraDiscoveryConfigSchema = z.object({
  health: z.boolean().default(true),
  robots_txt: z.boolean().default(true),
  sitemap_xml: z.boolean().default(true),
  llms_txt: z.boolean().default(true),
  security_txt: z.boolean().optional(),
  webmanifest: z.boolean().default(true),
  security_txt_contact: z.string().optional(),
  security_txt_policy: z.string().optional(),
});

export const LumoraFeaturesConfigSchema = z.object({
  docs_engine: z.boolean().default(false),
  blog_engine: z.boolean().default(false),
  release_server: z.boolean().default(false),
  legal_registry: z.boolean().default(true),
  inquiry_engine: z.boolean().default(true),
});

export const LumoraWebConfigSchema = z.object({
  brand: LumoraBrandConfigSchema,
  security: LumoraSecurityConfigSchema.optional(),
  assets: LumoraAssetsConfigSchema.optional(),
  hub: LumoraHubSyncConfigSchema.optional(),
  legal: LumoraLegalConfigSchema.optional(),
  discovery: LumoraDiscoveryConfigSchema.optional(),
  features: LumoraFeaturesConfigSchema.optional(),
});

export type LumoraWebConfig = z.infer<typeof LumoraWebConfigSchema>;

export interface LumoraLockfile {
  framework: "@astrake/lumora-webserver";
  version: string;
  core_sha256: Record<string, string>;
  sri: Record<string, string>;
}
