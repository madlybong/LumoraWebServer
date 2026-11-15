import { computed, reactive } from "vue";
import { apiGet, apiPost, clearSessionCsrf, readBootPayload, setCsrfTokens } from "../lib/api";
import type { AdminBadges, AdminRole, AdminTheme, AdminUser, AuthStatusResponse } from "../lib/types";

export interface ToastItem {
  id: number;
  title: string;
  tone: "default" | "destructive" | "success";
}

const ROLE_WEIGHT: Record<AdminRole, number> = {
  viewer: 1,
  editor: 2,
  owner: 3,
};

let toastSeq = 1;

export interface SubRouteState {
  mode: "list" | "new" | "edit" | "detail";
  param1?: string;
  param2?: string;
}

const KNOWN_ROUTES = new Set([
  "overview",
  "inquiries",
  "dispatch",
  "channels",
  "blog",
  "docs",
  "media",
  "legal",
  "routing",
  "discovery",
  "releases",
  "security",
  "users",
  "profile",
]);

function loadSidebarCollapsed(): boolean {
  try {
    return localStorage.getItem("lumora_admin_sidebar_collapsed") === "1";
  } catch {
    return false;
  }
}

export const adminStore = reactive({
  boot: readBootPayload(),
  loadingAuth: true,
  authenticated: false,
  requires2fa: false,
  requires2faSetup: false,
  hasOwner: true,
  setupAvailable: false,
  user: null as AdminUser | null,
  currentRoute: "overview",
  subRoute: { mode: "list" } as SubRouteState,
  sidebarCollapsed: loadSidebarCollapsed(),
  theme: "system" as AdminTheme,
  resolvedDark: false,
  toasts: [] as ToastItem[],
  badges: {
    new_inquiries: 0,
    pending_outbox: 0,
    unresolved_404s: 0,
  } as AdminBadges,
});

export const isStudioWorkspace = computed(() => adminStore.subRoute.mode !== "list");

export function toggleSidebarCollapse(): void {
  adminStore.sidebarCollapsed = !adminStore.sidebarCollapsed;
  try {
    localStorage.setItem("lumora_admin_sidebar_collapsed", adminStore.sidebarCollapsed ? "1" : "0");
  } catch {}
}

export function setAdminBadges(badges?: Partial<AdminBadges>): void {
  if (!badges) return;
  if (typeof badges.new_inquiries === "number") {
    adminStore.badges.new_inquiries = badges.new_inquiries;
  }
  if (typeof badges.pending_outbox === "number") {
    adminStore.badges.pending_outbox = badges.pending_outbox;
  }
  if (typeof badges.unresolved_404s === "number") {
    adminStore.badges.unresolved_404s = badges.unresolved_404s;
  }
}

export function notifyToast(title: string, tone: ToastItem["tone"] = "default"): void {
  const id = toastSeq++;
  adminStore.toasts.push({ id, title, tone });
  window.setTimeout(() => {
    const idx = adminStore.toasts.findIndex((t) => t.id === id);
    if (idx !== -1) adminStore.toasts.splice(idx, 1);
  }, 4200);
}

export function hasRole(minRole: AdminRole): boolean {
  if (!adminStore.user) return false;
  return (ROLE_WEIGHT[adminStore.user.role] ?? 0) >= ROLE_WEIGHT[minRole];
}

export const isEditor = computed(() => hasRole("editor"));
export const isOwner = computed(() => hasRole("owner"));

export function applyThemePreference(theme: AdminTheme): void {
  adminStore.theme = theme;
  try {
    localStorage.setItem("lumora_admin_theme", theme);
  } catch {}
  syncDarkClass();
}

export function syncDarkClass(): void {
  const prefersDark =
    typeof window !== "undefined" &&
    window.matchMedia &&
    window.matchMedia("(prefers-color-scheme: dark)").matches;
  const dark =
    adminStore.theme === "dark" || (adminStore.theme === "system" && prefersDark);
  adminStore.resolvedDark = dark;
  document.documentElement.classList.toggle("dark", dark);
  document.documentElement.dataset.theme = dark ? "dark" : "light";
}

export function initThemeWatcher(): void {
  let saved: string | null = null;
  try {
    saved = localStorage.getItem("lumora_admin_theme");
  } catch {}
  const initial =
    saved === "light" || saved === "dark" || saved === "system"
      ? saved
      : adminStore.boot.branding.defaultTheme || "system";
  adminStore.theme = initial;
  syncDarkClass();
  if (window.matchMedia) {
    window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", () => {
      if (adminStore.theme === "system") syncDarkClass();
    });
  }
}

function safeDecode(segment: string): string {
  try {
    return decodeURIComponent(segment);
  } catch {
    return segment;
  }
}

export function syncRouteFromHash(): void {
  const raw = window.location.hash.replace(/^#\/?/, "").split("?")[0].trim();
  const segments = raw
    .split("/")
    .map((s) => s.trim())
    .filter(Boolean);

  const root = segments[0] && KNOWN_ROUTES.has(segments[0]) ? segments[0] : "overview";
  adminStore.currentRoute = root;

  if (segments.length <= 1) {
    adminStore.subRoute = { mode: "list" };
    return;
  }

  if (root === "inquiries" && segments[1]) {
    adminStore.subRoute = { mode: "detail", param1: safeDecode(segments[1]) };
    return;
  }

  if (segments[1] === "new") {
    adminStore.subRoute = {
      mode: "new",
      param1: segments[2] ? safeDecode(segments[2]) : undefined,
    };
    return;
  }

  if (segments[1] === "edit" && segments[2]) {
    adminStore.subRoute = {
      mode: "edit",
      param1: safeDecode(segments[2]),
      param2: segments[3] ? safeDecode(segments[3]) : undefined,
    };
    return;
  }

  adminStore.subRoute = { mode: "list" };
}

export function navigateTo(route: string): void {
  const clean = route.replace(/^\/+/, "").split("/")[0] || "overview";
  const target = KNOWN_ROUTES.has(clean) ? clean : "overview";
  adminStore.currentRoute = target;
  adminStore.subRoute = { mode: "list" };
  window.location.hash = `#/${target}`;
}

export function navigateToSubRoute(
  route: string,
  mode: SubRouteState["mode"],
  param1?: string,
  param2?: string
): void {
  const clean = route.replace(/^\/+/, "").split("/")[0] || "overview";
  const target = KNOWN_ROUTES.has(clean) ? clean : "overview";
  adminStore.currentRoute = target;
  adminStore.subRoute = { mode, param1, param2 };

  if (mode === "list") {
    window.location.hash = `#/${target}`;
    return;
  }
  if (mode === "detail" && param1) {
    window.location.hash = `#/${target}/${encodeURIComponent(param1)}`;
    return;
  }
  if (mode === "new") {
    window.location.hash = param1
      ? `#/${target}/new/${encodeURIComponent(param1)}`
      : `#/${target}/new`;
    return;
  }
  if (mode === "edit" && param1) {
    const parts = [target, "edit", encodeURIComponent(param1)];
    if (param2) parts.push(encodeURIComponent(param2));
    window.location.hash = `#/${parts.join("/")}`;
    return;
  }
  window.location.hash = `#/${target}`;
}

export async function refreshAuthStatus(): Promise<void> {
  adminStore.loadingAuth = true;
  try {
    const res = await apiGet<AuthStatusResponse>("auth.status");
    adminStore.authenticated = res.authenticated;
    adminStore.requires2fa = res.requires_2fa;
    adminStore.requires2faSetup = res.requires_2fa_setup;
    adminStore.hasOwner = res.has_owner;
    adminStore.setupAvailable = res.setup_available;
    adminStore.user = res.user;
    if (res.badges) {
      setAdminBadges(res.badges);
    }
    setCsrfTokens(res.csrf_token, res.bootstrap_csrf);
  } catch {
    adminStore.authenticated = false;
    adminStore.user = null;
  } finally {
    adminStore.loadingAuth = false;
  }
}

export async function signOut(): Promise<void> {
  try {
    const res = await apiPost<{ bootstrap_csrf?: string }>("auth.logout", {}, false);
    setCsrfTokens("", res.bootstrap_csrf);
  } catch {
    // Clear client state even if session already expired
  }
  clearSessionCsrf();
  adminStore.authenticated = false;
  adminStore.requires2fa = false;
  adminStore.requires2faSetup = false;
  adminStore.user = null;
  notifyToast("Signed out safely.");
}
