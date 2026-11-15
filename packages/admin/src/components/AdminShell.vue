<script setup lang="ts">
import { computed, ref, watch } from "vue";
import {
  ArrowRightLeft,
  BookOpen,
  ChevronRight,
  Command,
  ExternalLink,
  FileText,
  Globe,
  Image as ImageIcon,
  Inbox,
  LayoutDashboard,
  LogOut,
  Menu,
  Moon,
  Package,
  PanelLeftClose,
  PanelLeftOpen,
  Radio,
  Scale,
  Search,
  Send,
  Shield,
  Sun,
  UserCog,
  Users,
  X,
} from "lucide-vue-next";
import type { AdminRole } from "../lib/types";
import {
  adminStore,
  applyThemePreference,
  hasRole,
  isStudioWorkspace,
  navigateTo,
  signOut,
  toggleSidebarCollapse,
} from "../stores/admin";
import CommandPalette from "./CommandPalette.vue";
import Badge from "./ui/Badge.vue";
import Button from "./ui/Button.vue";

interface NavItem {
  id: string;
  label: string;
  icon: unknown;
  minRole: AdminRole;
  badgeCount?: number;
}

interface NavGroup {
  id: string;
  label: string;
  items: NavItem[];
}

const mobileOpen = ref(false);
const paletteOpen = ref(false);
const mainScrollRef = ref<HTMLElement | null>(null);

const navGroups = computed<NavGroup[]>(() => {
  const feats = adminStore.boot.features || {};
  const badges = adminStore.badges;

  const rawGroups: NavGroup[] = [
    {
      id: "ops",
      label: "Operations & CRM",
      items: [
        { id: "overview", label: "Overview", icon: LayoutDashboard, minRole: "viewer" },
        { id: "inquiries", label: "Inquiries Inbox", icon: Inbox, minRole: "viewer", badgeCount: badges.new_inquiries },
        { id: "dispatch", label: "Hub & Edge Dispatch", icon: Send, minRole: "viewer", badgeCount: badges.pending_outbox },
        { id: "channels", label: "Form Channels", icon: Radio, minRole: "viewer" },
      ],
    },
    {
      id: "studio",
      label: "Content Studio",
      items: [
        ...(feats.blog_engine !== false ? [{ id: "blog", label: "Blog Articles", icon: FileText, minRole: "viewer" as AdminRole }] : []),
        ...(feats.docs_engine !== false ? [{ id: "docs", label: "Documentation", icon: BookOpen, minRole: "viewer" as AdminRole }] : []),
        { id: "media", label: "Media Library", icon: ImageIcon, minRole: "viewer" },
        ...(feats.legal_registry !== false ? [{ id: "legal", label: "Legal Policies", icon: Scale, minRole: "viewer" as AdminRole }] : []),
      ],
    },
    {
      id: "traffic",
      label: "Traffic & Distribution",
      items: [
        { id: "routing", label: "Routes & 404 Monitor", icon: ArrowRightLeft, minRole: "viewer", badgeCount: badges.unresolved_404s },
        { id: "discovery", label: "Discovery & SEO", icon: Globe, minRole: "viewer" },
        ...(feats.release_server ? [{ id: "releases", label: "Releases", icon: Package, minRole: "viewer" as AdminRole }] : []),
      ],
    },
    {
      id: "security",
      label: "Security & Access",
      items: [
        { id: "security", label: "Security & Audit", icon: Shield, minRole: "viewer" },
        { id: "users", label: "Users & RBAC", icon: Users, minRole: "owner" },
        { id: "profile", label: "Profile & 2FA", icon: UserCog, minRole: "viewer" },
      ],
    },
  ];

  return rawGroups
    .map((group) => ({
      ...group,
      items: group.items.filter((i) => hasRole(i.minRole)),
    }))
    .filter((group) => group.items.length > 0);
});

const breadcrumb = computed(() => {
  for (const g of navGroups.value) {
    const found = g.items.find((i) => i.id === adminStore.currentRoute);
    if (found) {
      const sub = adminStore.subRoute;
      let subLabel = "";
      if (sub.mode === "new") {
        subLabel = sub.param1 ? `New (${sub.param1})` : "Create New";
      } else if (sub.mode === "edit") {
        subLabel = sub.param2 ? `${sub.param1} / ${sub.param2}` : sub.param1 || "Edit";
      } else if (sub.mode === "detail") {
        subLabel = sub.param1 || "Detail";
      }
      return {
        group: g.label,
        routeId: found.id,
        routeLabel: found.label,
        subLabel,
      };
    }
  }
  return {
    group: "Console",
    routeId: "overview",
    routeLabel: "Overview",
    subLabel: "",
  };
});

const userInitials = computed(() => {
  const name = (adminStore.user?.name || adminStore.user?.email || "OP").trim();
  const parts = name.split(/\s+/).filter(Boolean);
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase();
  }
  return name.slice(0, 2).toUpperCase();
});

const activeLogo = computed(() => {
  if (adminStore.resolvedDark && adminStore.boot.branding.logoDark) {
    return adminStore.boot.branding.logoDark;
  }
  return adminStore.boot.branding.logo || "";
});

function pickNav(id: string) {
  navigateTo(id);
  mobileOpen.value = false;
}

function toggleTheme() {
  applyThemePreference(adminStore.resolvedDark ? "light" : "dark");
}

watch(
  () => [adminStore.currentRoute, adminStore.subRoute.mode, adminStore.subRoute.param1, adminStore.subRoute.param2],
  () => {
    mainScrollRef.value?.scrollTo({ top: 0 });
  }
);
</script>

<template>
  <div class="h-dvh w-full overflow-hidden flex bg-background text-foreground">
    <!-- Mobile Drawer Backdrop -->
    <div
      v-if="mobileOpen"
      class="fixed inset-0 z-40 bg-black/60 backdrop-blur-[1px] lg:hidden"
      aria-hidden="true"
      @click="mobileOpen = false"
    />

    <!-- Sidebar Rail / Drawer -->
    <aside
      :class="[
        'fixed inset-y-0 left-0 z-50 flex flex-col border-r border-border bg-card transition-[width,transform] duration-200 lg:static lg:translate-x-0 h-full shrink-0',
        mobileOpen ? 'translate-x-0 w-64' : '-translate-x-full lg:translate-x-0',
        adminStore.sidebarCollapsed ? 'lg:w-16' : 'lg:w-64',
      ]"
    >
      <!-- Pinned Top Brand Header -->
      <div
        :class="[
          'h-14 shrink-0 border-b border-border flex items-center gap-2.5',
          adminStore.sidebarCollapsed ? 'lg:justify-center lg:px-2 px-4 justify-between' : 'px-4 justify-between',
        ]"
      >
        <button
          type="button"
          class="flex items-center gap-2.5 min-w-0 text-left cursor-pointer"
          :title="adminStore.boot.branding.title"
          @click="pickNav('overview')"
        >
          <img
            v-if="activeLogo"
            :src="activeLogo"
            :alt="adminStore.boot.branding.brandName"
            width="28"
            height="28"
            class="h-7 w-7 rounded object-contain shrink-0"
          />
          <div
            v-else
            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground text-xs font-bold"
          >
            {{ adminStore.boot.branding.brandName.slice(0, 2).toUpperCase() }}
          </div>
          <div v-if="!adminStore.sidebarCollapsed" class="min-w-0 lg:block">
            <div class="truncate text-sm font-semibold leading-tight">{{ adminStore.boot.branding.title }}</div>
            <div class="truncate text-[11px] text-muted-foreground leading-tight">{{ adminStore.boot.branding.domain }}</div>
          </div>
        </button>

        <Button
          variant="ghost"
          size="icon"
          class="lg:hidden h-8 w-8 shrink-0"
          aria-label="Close navigation menu"
          @click="mobileOpen = false"
        >
          <X class="h-4 w-4" aria-hidden="true" />
        </Button>
      </div>

      <!-- Central Independent Scrollable Navigation -->
      <nav aria-label="Admin navigation" class="flex-1 min-h-0 overflow-y-auto overscroll-contain lumora-scroll-area py-3 px-2 space-y-4">
        <div v-for="group in navGroups" :key="group.id" class="space-y-1">
          <div
            v-if="!adminStore.sidebarCollapsed"
            class="px-2.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground"
          >
            {{ group.label }}
          </div>
          <div v-else class="hidden lg:block mx-2 border-t border-border/70 my-1.5" />

          <button
            v-for="item in group.items"
            :key="item.id"
            type="button"
            :title="item.label"
            :aria-current="adminStore.currentRoute === item.id ? 'page' : undefined"
            :class="[
              'relative w-full flex items-center rounded-md text-sm font-medium transition-colors cursor-pointer',
              adminStore.sidebarCollapsed
                ? 'lg:justify-center lg:px-0 lg:h-9 px-2.5 py-1.5 justify-between gap-2'
                : 'justify-between gap-2 px-2.5 py-1.5 text-left',
              adminStore.currentRoute === item.id
                ? 'bg-primary text-primary-foreground shadow-xs'
                : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
            ]"
            @click="pickNav(item.id)"
          >
            <span class="flex items-center gap-2.5 min-w-0">
              <component :is="item.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
              <span v-if="!adminStore.sidebarCollapsed" class="truncate">{{ item.label }}</span>
            </span>

            <span
              v-if="item.badgeCount && item.badgeCount > 0 && !adminStore.sidebarCollapsed"
              :class="[
                'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold leading-none',
                adminStore.currentRoute === item.id
                  ? 'bg-primary-foreground text-primary'
                  : 'bg-muted text-foreground',
              ]"
            >
              {{ item.badgeCount }}
            </span>

            <span
              v-else-if="item.badgeCount && item.badgeCount > 0 && adminStore.sidebarCollapsed"
              class="hidden lg:block absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-amber-500 ring-2 ring-card"
            />
          </button>
        </div>
      </nav>

      <!-- Pinned Bottom Operator Card -->
      <div class="shrink-0 border-t border-border p-2.5 bg-card/90 space-y-2">
        <button
          v-if="!adminStore.sidebarCollapsed"
          type="button"
          class="w-full flex items-center justify-between gap-2 rounded-md p-1.5 hover:bg-accent/60 transition-colors text-left cursor-pointer"
          title="Open Profile & 2FA Settings"
          @click="pickNav('profile')"
        >
          <div class="flex items-center gap-2 min-w-0">
            <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-foreground">
              {{ userInitials }}
            </div>
            <div class="min-w-0 text-xs">
              <div class="truncate font-medium">{{ adminStore.user?.name }}</div>
              <div class="truncate text-[11px] text-muted-foreground">{{ adminStore.user?.email }}</div>
            </div>
          </div>
          <Badge variant="outline" class="shrink-0 text-[10px]">{{ adminStore.user?.role }}</Badge>
        </button>

        <div
          :class="[
            'flex items-center gap-1.5',
            adminStore.sidebarCollapsed ? 'lg:flex-col' : '',
          ]"
        >
          <Button
            v-if="adminStore.sidebarCollapsed"
            variant="ghost"
            size="icon"
            class="hidden lg:inline-flex h-8 w-8"
            title="Profile & 2FA"
            aria-label="Open profile and two-factor settings"
            @click="pickNav('profile')"
          >
            <UserCog class="h-4 w-4" aria-hidden="true" />
          </Button>
          <Button
            variant="outline"
            :size="adminStore.sidebarCollapsed ? 'icon' : 'sm'"
            :class="adminStore.sidebarCollapsed ? 'lg:h-8 lg:w-8 flex-1 lg:flex-none' : 'flex-1 h-8'"
            title="Sign out"
            aria-label="Sign out"
            @click="signOut"
          >
            <LogOut class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span v-if="!adminStore.sidebarCollapsed">Sign out</span>
          </Button>
        </div>
      </div>
    </aside>

    <!-- Main Workspace Column -->
    <div class="flex-1 flex flex-col min-w-0 h-full overflow-hidden">
      <!-- Sticky Unified Desktop & Mobile Top App Bar -->
      <header class="h-14 shrink-0 border-b border-border bg-card/85 backdrop-blur px-4 lg:px-6 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2 min-w-0">
          <Button
            variant="ghost"
            size="icon"
            class="lg:hidden h-8 w-8 shrink-0"
            aria-label="Open navigation menu"
            @click="mobileOpen = true"
          >
            <Menu class="h-4 w-4" aria-hidden="true" />
          </Button>

          <Button
            variant="ghost"
            size="icon"
            class="hidden lg:inline-flex h-8 w-8 shrink-0 text-muted-foreground hover:text-foreground"
            :title="adminStore.sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
            aria-label="Toggle sidebar width"
            @click="toggleSidebarCollapse"
          >
            <PanelLeftOpen v-if="adminStore.sidebarCollapsed" class="h-4 w-4" aria-hidden="true" />
            <PanelLeftClose v-else class="h-4 w-4" aria-hidden="true" />
          </Button>

          <nav aria-label="Breadcrumb" class="flex items-center gap-1.5 text-sm min-w-0">
            <span class="hidden sm:inline text-xs text-muted-foreground truncate">{{ breadcrumb.group }}</span>
            <ChevronRight class="hidden sm:inline h-3.5 w-3.5 text-muted-foreground shrink-0" aria-hidden="true" />
            <button
              type="button"
              :class="[
                'truncate transition-colors cursor-pointer',
                breadcrumb.subLabel ? 'text-muted-foreground hover:text-foreground font-medium' : 'font-semibold text-foreground',
              ]"
              @click="navigateTo(breadcrumb.routeId)"
            >
              {{ breadcrumb.routeLabel }}
            </button>
            <template v-if="breadcrumb.subLabel">
              <ChevronRight class="h-3.5 w-3.5 text-muted-foreground shrink-0" aria-hidden="true" />
              <span class="truncate font-semibold font-mono text-xs bg-muted px-2 py-0.5 rounded max-w-52 sm:max-w-xs">
                {{ breadcrumb.subLabel }}
              </span>
            </template>
          </nav>
        </div>

        <div class="flex items-center gap-2 shrink-0">
          <button
            type="button"
            class="inline-flex h-8 items-center gap-2 rounded-md border border-input bg-background px-2.5 text-xs text-muted-foreground hover:bg-accent hover:text-accent-foreground cursor-pointer"
            aria-label="Open command palette"
            @click="paletteOpen = true"
          >
            <Search class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            <span class="hidden md:inline">Quick jump...</span>
            <kbd class="hidden sm:inline-flex items-center gap-0.5 rounded border border-border px-1 text-[10px] font-mono">
              <Command class="h-2.5 w-2.5" aria-hidden="true" />K
            </kbd>
          </button>

          <Button
            variant="outline"
            size="sm"
            class="h-8 gap-1.5 px-2.5"
            aria-label="Toggle color theme"
            @click="toggleTheme"
          >
            <Sun v-if="adminStore.resolvedDark" class="h-3.5 w-3.5" aria-hidden="true" />
            <Moon v-else class="h-3.5 w-3.5" aria-hidden="true" />
            <span class="hidden sm:inline text-xs">{{ adminStore.resolvedDark ? "Light" : "Dark" }}</span>
          </Button>

          <a
            :href="adminStore.boot.siteUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex h-8 items-center gap-1.5 rounded-md border border-input bg-background px-2.5 text-xs font-medium hover:bg-accent"
            title="Open public website in a new tab"
          >
            <span class="hidden sm:inline">Live Site</span>
            <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
          </a>
        </div>
      </header>

      <!-- Independent Main Content Scroll Canvas -->
      <main ref="mainScrollRef" class="flex-1 min-w-0 min-h-0 overflow-y-auto overscroll-contain lumora-scroll-area p-4 sm:p-6 lg:p-8">
        <div :class="[isStudioWorkspace ? 'max-w-[1600px]' : 'max-w-7xl', 'mx-auto w-full']">
          <slot />
        </div>
      </main>
    </div>

    <!-- Live Toast Stack -->
    <div aria-live="polite" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-sm">
      <div
        v-for="t in adminStore.toasts"
        :key="t.id"
        role="status"
        :class="[
          'rounded-md border px-4 py-2.5 text-sm shadow-lg',
          t.tone === 'destructive'
            ? 'border-destructive bg-destructive text-destructive-foreground'
            : t.tone === 'success'
              ? 'border-emerald-500/40 bg-card text-card-foreground'
              : 'border-border bg-card text-card-foreground',
        ]"
      >
        {{ t.title }}
      </div>
    </div>

    <CommandPalette v-model:open="paletteOpen" />
  </div>
</template>
