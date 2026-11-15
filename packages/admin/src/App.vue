<script setup lang="ts">
import { computed, onMounted } from "vue";
import AdminShell from "./components/AdminShell.vue";
import { adminStore, initThemeWatcher, refreshAuthStatus, syncRouteFromHash } from "./stores/admin";
import LoginView from "./views/LoginView.vue";
import OverviewView from "./views/OverviewView.vue";
import InquiriesView from "./views/InquiriesView.vue";
import DispatchView from "./views/DispatchView.vue";
import BlogView from "./views/BlogView.vue";
import DocsView from "./views/DocsView.vue";
import MediaView from "./views/MediaView.vue";
import RoutingView from "./views/RoutingView.vue";
import LegalView from "./views/LegalView.vue";
import ChannelsView from "./views/ChannelsView.vue";
import ReleasesView from "./views/ReleasesView.vue";
import DiscoveryView from "./views/DiscoveryView.vue";
import SecurityView from "./views/SecurityView.vue";
import UsersView from "./views/UsersView.vue";
import ProfileView from "./views/ProfileView.vue";

const activeView = computed(() => {
  if (adminStore.requires2faSetup) return ProfileView;
  switch (adminStore.currentRoute) {
    case "inquiries":
      return InquiriesView;
    case "dispatch":
      return DispatchView;
    case "blog":
      return BlogView;
    case "docs":
      return DocsView;
    case "media":
      return MediaView;
    case "routing":
      return RoutingView;
    case "legal":
      return LegalView;
    case "channels":
      return ChannelsView;
    case "releases":
      return ReleasesView;
    case "discovery":
      return DiscoveryView;
    case "security":
      return SecurityView;
    case "users":
      return UsersView;
    case "profile":
      return ProfileView;
    default:
      return OverviewView;
  }
});

onMounted(async () => {
  initThemeWatcher();
  syncRouteFromHash();
  window.addEventListener("hashchange", syncRouteFromHash);
  await refreshAuthStatus();
});
</script>

<template>
  <div v-if="adminStore.loadingAuth" class="min-h-screen flex items-center justify-center text-sm text-muted-foreground">
    Loading console...
  </div>
  <LoginView v-else-if="!adminStore.authenticated" />
  <AdminShell v-else>
    <component :is="activeView" :key="adminStore.currentRoute" />
  </AdminShell>
</template>
