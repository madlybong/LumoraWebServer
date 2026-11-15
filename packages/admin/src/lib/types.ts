export type AdminRole = "viewer" | "editor" | "owner";
export type AdminTheme = "light" | "dark" | "system";

export interface AdminBranding {
  title: string;
  brandName: string;
  brandId: string;
  domain: string;
  logo: string;
  logoDark: string;
  defaultTheme: AdminTheme;
}

export interface AdminBootPayload {
  apiUrl: string;
  siteUrl: string;
  adminPath: string;
  bootstrapCsrf: string;
  branding: AdminBranding;
  features: Record<string, boolean>;
  legalTerritories: string[];
  require2fa: boolean;
}

export interface AdminUser {
  id: number;
  email: string;
  name: string;
  role: AdminRole;
  totp_enabled: boolean;
  is_active: boolean;
  last_login_at: string;
}

export interface AdminBadges {
  new_inquiries: number;
  pending_outbox: number;
  unresolved_404s: number;
}

export interface AuthStatusResponse {
  ok: boolean;
  authenticated: boolean;
  requires_2fa: boolean;
  requires_2fa_setup: boolean;
  has_owner: boolean;
  setup_available: boolean;
  csrf_token?: string;
  bootstrap_csrf: string;
  user: AdminUser | null;
  badges?: AdminBadges;
}

export interface InquiryItem {
  id: number;
  receipt_id: string;
  channel: string;
  channel_label: string;
  sla_window: string;
  full_name: string;
  organization: string;
  email: string;
  phone: string;
  subject: string;
  message?: string;
  details?: Record<string, unknown>;
  consultation_mode: string;
  facility_location: string;
  email_status: string;
  whatsapp_status: string;
  sync_status: string;
  hub_receipt: string;
  triage_status: string;
  internal_notes?: string;
  created_at: string;
  updated_at?: string;
}

export interface BlogPostItem {
  slug: string;
  title: string;
  summary: string;
  content?: string;
  author?: string;
  category?: string;
  tags?: string[];
  date?: string;
  image?: string;
  status?: string;
  reading_time?: string;
  meta_title?: string;
  meta_description?: string;
  og_image?: string;
  canonical_url?: string;
  source_origin?: "file" | "sqlite";
  has_file_origin?: boolean;
}

export interface DocsArticleItem {
  slug: string;
  title: string;
  summary: string;
  markdown?: string;
  file?: string;
  reading_time?: string;
  tags?: string[];
  status?: string;
  sort_order?: number;
  source_origin?: "file" | "sqlite";
  has_file_origin?: boolean;
}

export interface DocsTrackItem {
  track_id: string;
  title: string;
  description: string;
  articles: DocsArticleItem[];
  source_origin?: "file" | "sqlite";
  has_file_origin?: boolean;
}

export interface RedirectItem {
  id: number;
  source_path: string;
  target_url: string;
  status_code: number;
  is_active: number;
  hits?: number;
  last_hit_at?: string;
  created_at: string;
  updated_at: string;
}

export interface NotFoundLogItem {
  id: number;
  path: string;
  hits: number;
  referrer: string;
  user_agent_summary: string;
  first_seen_at: string;
  last_seen_at: string;
}

export interface MediaItem {
  id: number;
  filename: string;
  public_path: string;
  url: string;
  mime_type: string;
  size_bytes: number;
  width: number;
  height: number;
  alt_text: string;
  source_origin?: "upload" | "static";
  created_at: string;
}

export interface RevisionItem {
  id: number;
  entity_type: "blog" | "docs" | "legal";
  entity_key: string;
  title: string;
  snapshot: Record<string, unknown>;
  actor_email: string;
  created_at: string;
}

export interface ChannelCustomField {
  name: string;
  label: string;
  type: "string" | "enum";
  required: boolean;
  placeholder?: string;
  options?: string[];
}

export interface ChannelConfig {
  code: string;
  subject_placeholder: string;
  message_label: string;
  message_placeholder: string;
  button_text: string;
  fields: ChannelCustomField[];
}

export interface RouteSeoItem {
  route_path: string;
  meta_title: string;
  meta_description: string;
  og_image: string;
  robots: string;
  updated_at: string;
}

export interface LegalSection {
  id: string;
  title: string;
  body: string;
}

export interface LegalDocumentItem {
  slug: string;
  doc_no: string;
  title: string;
  lead: string;
  version: string;
  date: string;
  status: string;
  sections: LegalSection[];
  source_origin?: string;
}
