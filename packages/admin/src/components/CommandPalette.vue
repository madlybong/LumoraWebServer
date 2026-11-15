<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { CornerDownLeft, Search } from "lucide-vue-next";
import type { AdminRole } from "../lib/types";
import { buildExportUrl } from "../lib/api";
import { adminStore, applyThemePreference, hasRole, isOwner, navigateTo } from "../stores/admin";
import ModalDialog from "./ui/ModalDialog.vue";

const open = defineModel<boolean>("open", { default: false });

interface PaletteAction {
  id: string;
  label: string;
  group: string;
  keywords: string;
  minRole: AdminRole;
  feature?: string;
  run: () => void;
}

const query = ref("");
const activeIdx = ref(0);

const allActions = computed<PaletteAction[]>(() => {
  const feats = adminStore.boot.features || {};
  const list: PaletteAction[] = [
    { id: "nav:overview", group: "Navigate", label: "Operations Overview", keywords: "dashboard home stats metrics", minRole: "viewer", run: () => navigateTo("overview") },
    { id: "nav:inquiries", group: "Navigate", label: "Inquiries Inbox", keywords: "crm leads contact messages", minRole: "viewer", run: () => navigateTo("inquiries") },
    { id: "nav:dispatch", group: "Navigate", label: "Hub & Edge Dispatch", keywords: "smtp email whatsapp webhook outbox", minRole: "viewer", run: () => navigateTo("dispatch") },
    { id: "nav:blog", group: "Navigate", label: "Blog Articles", keywords: "posts articles publishing seo", minRole: "viewer", feature: "blog_engine", run: () => navigateTo("blog") },
    { id: "nav:docs", group: "Navigate", label: "Documentation", keywords: "docs guides tracks markdown", minRole: "viewer", feature: "docs_engine", run: () => navigateTo("docs") },
    { id: "nav:media", group: "Navigate", label: "Media Library", keywords: "images uploads assets static", minRole: "viewer", run: () => navigateTo("media") },
    { id: "nav:routing", group: "Navigate", label: "Routes & 404 Redirects", keywords: "redirects 301 302 404 monitor", minRole: "viewer", run: () => navigateTo("routing") },
    { id: "nav:legal", group: "Navigate", label: "Legal Policies", keywords: "privacy terms sla compliance", minRole: "viewer", feature: "legal_registry", run: () => navigateTo("legal") },
    { id: "nav:channels", group: "Navigate", label: "Form Channels & Builder", keywords: "contact forms fields custom", minRole: "viewer", run: () => navigateTo("channels") },
    { id: "nav:releases", group: "Navigate", label: "Releases Manager", keywords: "artifacts downloads sha256", minRole: "viewer", feature: "release_server_only", run: () => navigateTo("releases") },
    { id: "nav:discovery", group: "Navigate", label: "Discovery, llms.txt & SEO", keywords: "robots sitemap security.txt route seo", minRole: "viewer", run: () => navigateTo("discovery") },
    { id: "nav:security", group: "Navigate", label: "Security & Audit Log", keywords: "sessions rate limits events", minRole: "viewer", run: () => navigateTo("security") },
    { id: "nav:users", group: "Navigate", label: "Users & RBAC", keywords: "operators roles invites accounts", minRole: "owner", run: () => navigateTo("users") },
    { id: "nav:profile", group: "Navigate", label: "Profile & 2FA", keywords: "totp password recovery codes sessions", minRole: "viewer", run: () => navigateTo("profile") },
    {
      id: "act:theme",
      group: "Quick Actions",
      label: adminStore.resolvedDark ? "Switch to Light Theme" : "Switch to Dark Theme",
      keywords: "dark light mode appearance",
      minRole: "viewer",
      run: () => applyThemePreference(adminStore.resolvedDark ? "light" : "dark"),
    },
    {
      id: "act:site",
      group: "Quick Actions",
      label: "Open Public Website in New Tab",
      keywords: "preview frontend site",
      minRole: "viewer",
      run: () => window.open(adminStore.boot.siteUrl, "_blank", "noopener,noreferrer"),
    },
  ];
  if (isOwner.value) {
    list.push({
      id: "act:export-cms",
      group: "Quick Actions",
      label: "Download Portable CMS Snapshot (.json)",
      keywords: "backup export json migrate",
      minRole: "owner",
      run: () => {
        window.location.href = buildExportUrl("system.export-cms");
      },
    });
  }
  return list.filter((item) => {
    if (!hasRole(item.minRole)) return false;
    if (item.feature === "blog_engine" && feats.blog_engine === false) return false;
    if (item.feature === "docs_engine" && feats.docs_engine === false) return false;
    if (item.feature === "legal_registry" && feats.legal_registry === false) return false;
    if (item.feature === "release_server_only" && !feats.release_server) return false;
    return true;
  });
});

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return allActions.value;
  return allActions.value.filter(
    (item) =>
      item.label.toLowerCase().includes(q) ||
      item.group.toLowerCase().includes(q) ||
      item.keywords.toLowerCase().includes(q)
  );
});

watch(query, () => {
  activeIdx.value = 0;
});

watch(open, (val) => {
  if (val) {
    query.value = "";
    activeIdx.value = 0;
  }
});

function handleGlobalKey(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
    e.preventDefault();
    open.value = !open.value;
  }
}

onMounted(() => {
  window.addEventListener("keydown", handleGlobalKey);
});

onBeforeUnmount(() => {
  window.removeEventListener("keydown", handleGlobalKey);
});

function onInputKeydown(e: KeyboardEvent) {
  if (e.isComposing) return;
  const len = filtered.value.length;
  if (!len) return;
  if (e.key === "ArrowDown") {
    e.preventDefault();
    activeIdx.value = (activeIdx.value + 1) % len;
  } else if (e.key === "ArrowUp") {
    e.preventDefault();
    activeIdx.value = (activeIdx.value - 1 + len) % len;
  } else if (e.key === "Enter") {
    e.preventDefault();
    const chosen = filtered.value[activeIdx.value];
    if (chosen) execute(chosen);
  }
}

function execute(item: PaletteAction) {
  open.value = false;
  item.run();
}
</script>

<template>
  <ModalDialog
    v-model:open="open"
    title="Command Palette"
    description="Jump to any module or trigger quick console actions."
    size="lg"
  >
    <div class="space-y-3">
      <div class="sticky top-0 z-10 bg-card pb-2">
        <div class="relative">
          <Search class="h-4 w-4 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true" />
          <label for="cmd-palette-input" class="sr-only">Search commands and pages</label>
          <input
            id="cmd-palette-input"
            v-model="query"
            type="search"
            autofocus
            autocomplete="off"
            placeholder="Type a page or action (e.g. blog, 404, export, theme)..."
            class="flex h-10 w-full rounded-md border border-input bg-background pl-9 pr-3 text-sm shadow-xs placeholder:text-muted-foreground"
            @keydown="onInputKeydown"
          />
        </div>
      </div>

      <div v-if="filtered.length === 0" class="py-8 text-center text-sm text-muted-foreground">
        No matching commands.
      </div>
      <div v-else class="divide-y divide-border rounded-md border border-border">
        <button
          v-for="(item, idx) in filtered"
          :key="item.id"
          type="button"
          :class="[
            'w-full flex items-center justify-between gap-3 px-3 py-2.5 text-left text-sm cursor-pointer',
            idx === activeIdx ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/50',
          ]"
          @mouseenter="activeIdx = idx"
          @click="execute(item)"
        >
          <div>
            <div class="font-medium">{{ item.label }}</div>
            <div class="text-[11px] text-muted-foreground">{{ item.group }}</div>
          </div>
          <CornerDownLeft v-if="idx === activeIdx" class="h-3.5 w-3.5 text-muted-foreground shrink-0" aria-hidden="true" />
        </button>
      </div>
    </div>

    <template #footer>
      <div class="w-full flex items-center justify-between text-[11px] text-muted-foreground font-mono">
        <span>{{ filtered.length }} command(s)</span>
        <span>↑↓ Navigate · ↵ Open · Esc Close</span>
      </div>
    </template>
  </ModalDialog>
</template>
