<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { LogOut, RefreshCw, Search, ShieldCheck, Unlock } from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import { formatDateTime } from "../lib/utils";
import { isOwner, notifyToast } from "../stores/admin";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";

interface SecurityResponse {
  rate_limits: Array<{ ip_hash: string; event_type: string; hits: number; last_hit: string }>;
  admin_audit: Array<{ id: number; actor_email: string; action: string; target_type: string; target_id: string; created_at: string }>;
  security_events: Array<{ timestamp?: string; event?: string; detail?: string; ip_hash?: string }>;
  active_sessions: number;
}

const data = ref<SecurityResponse | null>(null);
const loading = ref(false);
const revokeConfirmOpen = ref(false);
const revoking = ref(false);
const auditSearch = ref("");

const filteredAudit = computed(() => {
  const rows = data.value?.admin_audit || [];
  const q = auditSearch.value.trim().toLowerCase();
  if (!q) return rows;
  return rows.filter(
    (a) =>
      a.actor_email.toLowerCase().includes(q) ||
      a.action.toLowerCase().includes(q) ||
      a.target_type.toLowerCase().includes(q) ||
      a.target_id.toLowerCase().includes(q)
  );
});

async function load() {
  loading.value = true;
  try {
    data.value = await apiGet<SecurityResponse>("security.events");
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load security telemetry.", "destructive");
  } finally {
    loading.value = false;
  }
}

async function unblockIp(ipHash: string) {
  try {
    await apiPost("security.unblock-ip", { ip_hash: ipHash });
    notifyToast("Rate-limit counters cleared for IP hash.", "success");
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Unblock failed.", "destructive");
  }
}

function revokeOthers() {
  revokeConfirmOpen.value = true;
}

async function executeRevokeOthers() {
  revoking.value = true;
  try {
    await apiPost("security.revoke-all-sessions", {});
    notifyToast("All other admin sessions revoked.", "success");
    revokeConfirmOpen.value = false;
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to revoke sessions.", "destructive");
  } finally {
    revoking.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Security, Rate Limits &amp; Audit</h2>
        <p class="text-sm text-muted-foreground">Active sessions, brute-force throttle windows, and immutable action log.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <Button v-if="isOwner" variant="destructive" size="sm" @click="revokeOthers">
          <LogOut class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Revoke Other Sessions</span>
        </Button>
        <Button variant="outline" size="sm" :disabled="loading" @click="load">
          <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Refresh</span>
        </Button>
      </div>
    </div>

    <div v-if="data" class="grid gap-4 lg:grid-cols-2 items-start">
      <Card class="overflow-hidden">
        <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-sm font-semibold">Active Rate-Limit Buckets (24h)</h3>
          <Badge variant="outline">Active Sessions: {{ data.active_sessions }}</Badge>
        </div>
        <div v-if="data.rate_limits.length === 0" class="p-6 text-sm text-muted-foreground flex items-center gap-2">
          <ShieldCheck class="h-4 w-4 text-emerald-600 shrink-0" aria-hidden="true" />
          <span>No rate-limit pressure recorded.</span>
        </div>
        <div v-else tabindex="0" role="region" aria-labelledby="rl-cap" class="lumora-table-wrap max-h-72 overflow-y-auto overscroll-contain lumora-scroll-area border-0 rounded-none">
          <table class="w-full text-xs text-left">
            <caption id="rl-cap" class="sr-only">Rate-limit hits table</caption>
            <thead class="sticky top-0 z-10 border-b border-border bg-muted/95 backdrop-blur uppercase text-muted-foreground">
              <tr>
                <th scope="col" class="px-5 py-2.5">IP Hash</th>
                <th scope="col" class="px-3 py-2.5">Event</th>
                <th scope="col" class="px-3 py-2.5">Hits</th>
                <th scope="col" class="px-5 py-2.5 text-right">Action</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border">
              <tr v-for="rl in data.rate_limits" :key="rl.ip_hash + rl.event_type" class="hover:bg-muted/20">
                <td class="px-5 py-2 font-mono">{{ rl.ip_hash.slice(0, 14) }}…</td>
                <td class="px-3 py-2">{{ rl.event_type }}</td>
                <td class="px-3 py-2 font-semibold">{{ rl.hits }}</td>
                <td class="px-5 py-2 text-right">
                  <Button v-if="isOwner" variant="ghost" size="sm" @click="unblockIp(rl.ip_hash)">
                    <Unlock class="h-3.5 w-3.5" aria-hidden="true" />
                    <span>Clear</span>
                  </Button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <Card class="overflow-hidden">
        <div class="px-5 py-4 border-b border-border flex items-center justify-between gap-2">
          <h3 class="text-sm font-semibold">Recent Security Events</h3>
          <Badge variant="outline">{{ data.security_events.length }} events</Badge>
        </div>
        <div v-if="data.security_events.length === 0" class="p-6 text-sm text-muted-foreground">
          No security alerts recorded in <code class="font-mono text-xs">security-audit.jsonl</code>.
        </div>
        <ul v-else class="max-h-72 overflow-y-auto overscroll-contain lumora-scroll-area divide-y divide-border text-xs">
          <li v-for="(ev, i) in data.security_events" :key="i" class="px-5 py-3 space-y-0.5 hover:bg-muted/20">
            <div class="flex flex-wrap justify-between gap-2">
              <span class="font-mono font-medium">{{ ev.event }}</span>
              <span class="text-muted-foreground">{{ formatDateTime(ev.timestamp) }}</span>
            </div>
            <div class="text-muted-foreground">{{ ev.detail }}</div>
          </li>
        </ul>
      </Card>
    </div>

    <Card v-if="data" class="overflow-hidden">
      <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <h3 class="text-sm font-semibold">Admin Mutation Audit Trail</h3>
          <Badge variant="secondary">{{ filteredAudit.length }}</Badge>
        </div>
        <div class="relative w-full sm:w-64">
          <Search class="h-3.5 w-3.5 text-muted-foreground absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true" />
          <label for="audit-search" class="sr-only">Filter audit trail by actor or action</label>
          <Input id="audit-search" v-model="auditSearch" type="search" placeholder="Filter actor, action, target..." class="h-8 pl-8 text-xs" />
        </div>
      </div>
      <div v-if="filteredAudit.length === 0" class="p-6 text-sm text-muted-foreground text-center">
        No audit entries match your filter.
      </div>
      <div v-else tabindex="0" role="region" aria-labelledby="audit-cap" class="lumora-table-wrap max-h-96 overflow-y-auto overscroll-contain lumora-scroll-area border-0 rounded-none">
        <table class="w-full text-xs text-left">
          <caption id="audit-cap" class="sr-only">Admin audit trail</caption>
          <thead class="sticky top-0 z-10 border-b border-border bg-muted/95 backdrop-blur uppercase text-muted-foreground">
            <tr>
              <th scope="col" class="px-5 py-2.5">Actor</th>
              <th scope="col" class="px-3 py-2.5">Action</th>
              <th scope="col" class="px-3 py-2.5">Target</th>
              <th scope="col" class="px-5 py-2.5 text-right">Timestamp</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="a in filteredAudit" :key="a.id" class="hover:bg-muted/20">
              <td class="px-5 py-2.5">{{ a.actor_email }}</td>
              <td class="px-3 py-2.5 font-mono">{{ a.action }}</td>
              <td class="px-3 py-2.5 text-muted-foreground">{{ a.target_type }} {{ a.target_id }}</td>
              <td class="px-5 py-2.5 text-right text-muted-foreground whitespace-nowrap">{{ formatDateTime(a.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>

    <ConfirmDialog
      v-model:open="revokeConfirmOpen"
      title="Revoke Other Sessions"
      description="Invalidate every other admin session across this deployment? Active operators will need to sign in again."
      confirm-label="Revoke Sessions"
      variant="destructive"
      :loading="revoking"
      @confirm="executeRevokeOthers"
    />
  </div>
</template>
