import type { AdminBootPayload } from "./types";

let cachedBoot: AdminBootPayload | null = null;
let sessionCsrf = "";
let bootstrapCsrf = "";

export function readBootPayload(): AdminBootPayload {
  if (cachedBoot) return cachedBoot;
  const el = document.getElementById("lumora-admin-boot");
  if (!el || !el.textContent) {
    throw new Error("Missing #lumora-admin-boot metadata payload.");
  }
  const parsed = JSON.parse(el.textContent) as AdminBootPayload;
  cachedBoot = parsed;
  bootstrapCsrf = parsed.bootstrapCsrf || "";
  return parsed;
}

export function setCsrfTokens(nextSessionCsrf?: string, nextBootstrapCsrf?: string): void {
  if (typeof nextSessionCsrf === "string" && nextSessionCsrf !== "") {
    sessionCsrf = nextSessionCsrf;
  }
  if (typeof nextBootstrapCsrf === "string" && nextBootstrapCsrf !== "") {
    bootstrapCsrf = nextBootstrapCsrf;
  }
}

export function clearSessionCsrf(): void {
  sessionCsrf = "";
}

export interface ApiResponse<T = Record<string, unknown>> {
  ok: boolean;
  error?: string;
  statusCode: number;
  data: T;
}

export async function apiGet<T = Record<string, unknown>>(
  action: string,
  params: Record<string, string | number | undefined> = {}
): Promise<T & { ok: boolean; error?: string }> {
  const boot = readBootPayload();
  const url = new URL(boot.apiUrl, window.location.origin);
  url.searchParams.set("action", action);
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== "") {
      url.searchParams.set(k, String(v));
    }
  }

  const res = await fetch(url.toString(), {
    method: "GET",
    credentials: "same-origin",
    headers: { Accept: "application/json" },
  });
  const payload = (await res.json()) as T & { ok: boolean; error?: string };
  if (!res.ok || payload.ok === false) {
    throw new Error(payload.error || `Request failed (${res.status})`);
  }
  return payload;
}

export async function apiPost<T = Record<string, unknown>>(
  action: string,
  body: Record<string, unknown> = {},
  useBootstrapCsrf = false
): Promise<T & { ok: boolean; error?: string }> {
  const boot = readBootPayload();
  const url = new URL(boot.apiUrl, window.location.origin);
  url.searchParams.set("action", action);

  const csrfToken = useBootstrapCsrf || !sessionCsrf ? bootstrapCsrf : sessionCsrf;
  const res = await fetch(url.toString(), {
    method: "POST",
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-Lumora-CSRF": csrfToken,
    },
    body: JSON.stringify(body),
  });

  const payload = (await res.json()) as T & {
    ok: boolean;
    error?: string;
    csrf_token?: string;
    bootstrap_csrf?: string;
  };
  if (payload.csrf_token || payload.bootstrap_csrf) {
    setCsrfTokens(payload.csrf_token, payload.bootstrap_csrf);
  }
  if (!res.ok || payload.ok === false) {
    throw new Error(payload.error || `Request failed (${res.status})`);
  }
  return payload;
}

export async function apiUpload<T = Record<string, unknown>>(
  action: string,
  formData: FormData
): Promise<T & { ok: boolean; error?: string }> {
  const boot = readBootPayload();
  const url = new URL(boot.apiUrl, window.location.origin);
  url.searchParams.set("action", action);

  const res = await fetch(url.toString(), {
    method: "POST",
    credentials: "same-origin",
    headers: {
      Accept: "application/json",
      "X-Lumora-CSRF": sessionCsrf || bootstrapCsrf,
    },
    body: formData,
  });

  const payload = (await res.json()) as T & { ok: boolean; error?: string };
  if (!res.ok || payload.ok === false) {
    throw new Error(payload.error || `Upload failed (${res.status})`);
  }
  return payload;
}

export function buildExportUrl(
  action: string,
  params: Record<string, string | number | undefined> = {}
): string {
  const boot = readBootPayload();
  const url = new URL(boot.apiUrl, window.location.origin);
  url.searchParams.set("action", action);
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== "") {
      url.searchParams.set(k, String(v));
    }
  }
  return url.toString();
}
