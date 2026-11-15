<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { ArrowRight, Eraser, Pencil, Plus, RotateCcw, Search, Trash2 } from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import type { AuthStatusResponse, NotFoundLogItem, RedirectItem } from "../lib/types";
import { formatDateTime } from "../lib/utils";
import { isEditor, notifyToast, setAdminBadges } from "../stores/admin";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";
import SwitchControl from "../components/ui/SwitchControl.vue";

const staticRoutes = ref<Record<string, { template?: string }>>({});
const fileRedirects = ref<Record<string, string>>({});
const sqliteRedirects = ref<RedirectItem[]>([]);
const notFoundLog = ref<NotFoundLogItem[]>([]);
const saving = ref(false);
const routeQuery = ref("");
const editingMode = ref<"create" | "edit" | "404">("create");

const deleteConfirmOpen = ref(false);
const deleteTarget = ref<RedirectItem | null>(null);
const clear404ConfirmOpen = ref(false);

const form = reactive({
  source_path: "",
  target_url: "",
  status_code: 301,
  is_active: true,
});

const filteredStaticRoutes = computed(() => {
  const q = routeQuery.value.trim().toLowerCase();
  const entries = Object.entries(staticRoutes.value);
  if (!q) return entries;
  return entries.filter(
    ([routePath, def]) =>
      routePath.toLowerCase().includes(q) || (def.template || "").toLowerCase().includes(q)
  );
});

const hasDraft = computed(() => form.source_path.trim() !== "" || form.target_url.trim() !== "");

function scrollMainTop() {
  document.querySelector("main")?.scrollTo({ top: 0, behavior: "smooth" });
}

function resetForm() {
  form.source_path = "";
  form.target_url = "";
  form.status_code = 301;
  form.is_active = true;
  editingMode.value = "create";
}

async function syncBadgesQuietly() {
  try {
    const auth = await apiGet<AuthStatusResponse>("auth.status");
    if (auth.badges) setAdminBadges(auth.badges);
  } catch {}
}

async function load() {
  try {
    const res = await apiGet<{
      static_routes: typeof staticRoutes.value;
      file_redirects: typeof fileRedirects.value;
      sqlite_redirects: RedirectItem[];
      not_found_log?: NotFoundLogItem[];
    }>("routing.list");
    staticRoutes.value = res.static_routes || {};
    fileRedirects.value = res.file_redirects || {};
    sqliteRedirects.value = res.sqlite_redirects || [];
    notFoundLog.value = res.not_found_log || [];
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load routing table.", "destructive");
  }
}

async function addRedirect() {
  saving.value = true;
  try {
    await apiPost("routing.save", form);
    resetForm();
    notifyToast("Redirect saved in SQLite Router.", "success");
    await load();
    void syncBadgesQuietly();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to save redirect.", "destructive");
  } finally {
    saving.value = false;
  }
}

function editRedirect(r: RedirectItem) {
  Object.assign(form, {
    source_path: r.source_path,
    target_url: r.target_url,
    status_code: r.status_code,
    is_active: Boolean(r.is_active),
  });
  editingMode.value = "edit";
  scrollMainTop();
}

function createRedirectFrom404(entry: NotFoundLogItem) {
  Object.assign(form, {
    source_path: entry.path,
    target_url: "/",
    status_code: 301,
    is_active: true,
  });
  editingMode.value = "404";
  scrollMainTop();
}

async function toggleActive(r: RedirectItem, val: boolean) {
  try {
    await apiPost("routing.toggle", { id: r.id, is_active: val });
    r.is_active = val ? 1 : 0;
    notifyToast(`Redirect ${r.source_path} ${val ? "enabled" : "paused"}.`);
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Toggle failed.", "destructive");
  }
}

function requestDeleteRedirect(r: RedirectItem) {
  deleteTarget.value = r;
  deleteConfirmOpen.value = true;
}

async function executeDeleteRedirect() {
  if (!deleteTarget.value) return;
  saving.value = true;
  try {
    await apiPost("routing.delete", { id: deleteTarget.value.id });
    notifyToast("Redirect deleted.", "success");
    deleteConfirmOpen.value = false;
    deleteTarget.value = null;
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Delete failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

async function clear404Entry(path?: string) {
  saving.value = true;
  try {
    await apiPost("routing.404-clear", path ? { path } : {});
    notifyToast(path ? `Dismissed 404 entry for ${path}.` : "Cleared 404 log.", "success");
    clear404ConfirmOpen.value = false;
    await load();
    void syncBadgesQuietly();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Clear 404 failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="space-y-6">
    <div>
      <h2 class="text-xl font-semibold tracking-tight">Routes, 301/302 Redirects &amp; 404 Monitor</h2>
      <p class="text-sm text-muted-foreground">Manage dynamic HTTP redirects with hit telemetry alongside 404 recovery.</p>
    </div>

    <Card v-if="isEditor" class="overflow-hidden">
      <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 class="text-sm font-semibold">Create / Update Runtime Redirect</h3>
          <p class="text-xs text-muted-foreground">Matching source paths update in-place in the SQLite edge router.</p>
        </div>
        <Badge v-if="editingMode === 'edit'" variant="secondary">Editing Existing Rule</Badge>
        <Badge v-else-if="editingMode === '404'" variant="warning">Prefilled from 404 Log</Badge>
      </div>
      <form id="routing-rule-form" class="p-5 grid gap-4 sm:grid-cols-12 items-end" @submit.prevent="addRedirect">
        <div class="space-y-1 sm:col-span-4">
          <label for="rd-src" class="text-xs font-medium">Source Path</label>
          <Input id="rd-src" v-model="form.source_path" required placeholder="/old-page/" class="font-mono text-xs" />
        </div>
        <div class="space-y-1 sm:col-span-5">
          <label for="rd-tgt" class="text-xs font-medium">Target Path or URL</label>
          <Input id="rd-tgt" v-model="form.target_url" required placeholder="/new-page/ or https://..." class="font-mono text-xs" />
        </div>
        <div class="space-y-1 sm:col-span-3">
          <label for="rd-code" class="text-xs font-medium">HTTP Status</label>
          <select id="rd-code" v-model="form.status_code" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
            <option :value="301">301 (Permanent)</option>
            <option :value="302">302 (Temporary)</option>
          </select>
        </div>
      </form>
      <div class="px-5 py-3 border-t border-border bg-muted/30 flex flex-wrap items-center justify-between gap-3">
        <label for="rd-active" class="flex items-center gap-2 text-xs font-medium cursor-pointer">
          <SwitchControl id="rd-active" v-model="form.is_active" />
          <span>Rule Active Immediately</span>
        </label>
        <div class="flex items-center gap-2">
          <Button v-if="hasDraft || editingMode !== 'create'" type="button" variant="ghost" size="sm" @click="resetForm">
            <RotateCcw class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Reset</span>
          </Button>
          <Button type="submit" form="routing-rule-form" size="sm" :disabled="saving">
            <Plus class="h-4 w-4" aria-hidden="true" />
            <span>{{ saving ? "Saving..." : "Save Rule" }}</span>
          </Button>
        </div>
      </div>
    </Card>

    <Card class="overflow-hidden">
      <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 class="text-sm font-semibold">SQLite Dynamic Redirects</h3>
          <p class="text-xs text-muted-foreground">Live runtime rewrite rules with automatic hit counters.</p>
        </div>
        <Badge variant="secondary">{{ sqliteRedirects.length }} rules</Badge>
      </div>
      <div v-if="sqliteRedirects.length === 0" class="p-6 text-sm text-muted-foreground text-center">
        No dynamic SQLite redirects added yet.
      </div>
      <div v-else class="max-h-96 overflow-y-auto overscroll-contain lumora-scroll-area divide-y divide-border text-sm">
        <div
          v-for="r in sqliteRedirects"
          :key="r.id"
          class="px-5 py-3 flex flex-wrap items-center justify-between gap-3 hover:bg-muted/20 transition-colors"
        >
          <div class="font-mono text-xs flex flex-wrap items-center gap-2 min-w-0">
            <Badge :variant="r.status_code === 301 ? 'outline' : 'secondary'">{{ r.status_code }}</Badge>
            <strong class="text-foreground">{{ r.source_path }}</strong>
            <span class="text-muted-foreground truncate">→ {{ r.target_url }}</span>
            <Badge variant="secondary">{{ r.hits || 0 }} hits</Badge>
            <span v-if="r.last_hit_at" class="text-muted-foreground">last: {{ formatDateTime(r.last_hit_at) }}</span>
          </div>
          <div v-if="isEditor" class="flex items-center gap-2 shrink-0">
            <SwitchControl :model-value="Boolean(r.is_active)" :aria-label="`Toggle ${r.source_path}`" @update:model-value="toggleActive(r, $event)" />
            <Button variant="ghost" size="sm" :aria-label="`Edit ${r.source_path}`" @click="editRedirect(r)">
              <Pencil class="h-3.5 w-3.5" aria-hidden="true" />
            </Button>
            <Button variant="ghost" size="sm" :aria-label="`Delete ${r.source_path}`" @click="requestDeleteRedirect(r)">
              <Trash2 class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
            </Button>
          </div>
        </div>
      </div>
    </Card>

    <Card class="overflow-hidden">
      <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2.5">
          <div>
            <h3 class="text-sm font-semibold">404 Not Found Monitor</h3>
            <p class="text-xs text-muted-foreground">Unmatched public paths logged automatically (zero-write when no new paths arrive).</p>
          </div>
          <Badge :variant="notFoundLog.length > 0 ? 'warning' : 'success'">
            {{ notFoundLog.length }} unresolved
          </Badge>
        </div>
        <Button v-if="isEditor && notFoundLog.length > 0" variant="outline" size="sm" :disabled="saving" @click="clear404ConfirmOpen = true">
          <Eraser class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Clear Log</span>
        </Button>
      </div>
      <div v-if="notFoundLog.length === 0" class="p-6 text-sm text-muted-foreground text-center">
        No unresolved 404 requests logged.
      </div>
      <div v-else tabindex="0" role="region" aria-labelledby="cap-404" class="lumora-table-wrap max-h-96 overflow-y-auto overscroll-contain lumora-scroll-area border-0 rounded-none">
        <table class="w-full text-xs text-left">
          <caption id="cap-404" class="sr-only">404 Not Found telemetry</caption>
          <thead class="sticky top-0 z-10 border-b border-border bg-muted/95 backdrop-blur uppercase text-muted-foreground">
            <tr>
              <th scope="col" class="px-5 py-2.5">Path</th>
              <th scope="col" class="px-4 py-2.5">Hits</th>
              <th scope="col" class="px-4 py-2.5">Last Seen</th>
              <th v-if="isEditor" scope="col" class="px-5 py-2.5 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="entry in notFoundLog" :key="entry.path" class="hover:bg-muted/20 transition-colors">
              <td class="px-5 py-2.5 font-mono">{{ entry.path }}</td>
              <td class="px-4 py-2.5"><Badge variant="warning">{{ entry.hits }}</Badge></td>
              <td class="px-4 py-2.5 text-muted-foreground">{{ formatDateTime(entry.last_seen_at) }}</td>
              <td v-if="isEditor" class="px-5 py-2.5 text-right whitespace-nowrap space-x-1">
                <Button variant="outline" size="sm" @click="createRedirectFrom404(entry)">
                  <ArrowRight class="h-3 w-3" aria-hidden="true" />
                  <span>Create 301 Redirect</span>
                </Button>
                <Button variant="ghost" size="sm" :aria-label="`Dismiss 404 entry ${entry.path}`" @click="clear404Entry(entry.path)">
                  <Trash2 class="h-3.5 w-3.5 text-muted-foreground" aria-hidden="true" />
                </Button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>

    <div class="grid gap-4 lg:grid-cols-2 items-start">
      <Card class="overflow-hidden">
        <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2">
            <h3 class="text-sm font-semibold">Declared Routes (<code class="font-mono text-xs">content/routes.php</code>)</h3>
            <Badge variant="outline">{{ Object.keys(staticRoutes).length }}</Badge>
          </div>
          <div class="relative w-48">
            <Search class="h-3.5 w-3.5 text-muted-foreground absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true" />
            <label for="route-filter" class="sr-only">Filter declared routes</label>
            <Input id="route-filter" v-model="routeQuery" type="search" placeholder="Filter routes..." class="h-8 pl-8 text-xs" />
          </div>
        </div>
        <div v-if="filteredStaticRoutes.length === 0" class="p-5 text-xs text-muted-foreground">
          No declared routes match your filter.
        </div>
        <div v-else class="max-h-80 overflow-y-auto overscroll-contain lumora-scroll-area divide-y divide-border text-xs font-mono">
          <div v-for="[routePath, def] in filteredStaticRoutes" :key="routePath" class="px-5 py-2.5 flex justify-between gap-3">
            <span class="truncate">{{ routePath }}</span>
            <span class="text-muted-foreground shrink-0">{{ def.template }}</span>
          </div>
        </div>
      </Card>

      <Card class="overflow-hidden">
        <div class="px-5 py-4 border-b border-border flex items-center justify-between gap-2">
          <h3 class="text-sm font-semibold">Static File Redirects</h3>
          <Badge variant="outline">{{ Object.keys(fileRedirects).length }}</Badge>
        </div>
        <div v-if="Object.keys(fileRedirects).length === 0" class="p-5 text-xs text-muted-foreground">None configured.</div>
        <div v-else class="max-h-80 overflow-y-auto overscroll-contain lumora-scroll-area divide-y divide-border text-xs font-mono">
          <div v-for="(target, src) in fileRedirects" :key="src" class="px-5 py-2.5 flex justify-between gap-3">
            <span class="truncate">{{ src }}</span>
            <span class="text-muted-foreground truncate">→ {{ target }}</span>
          </div>
        </div>
      </Card>
    </div>

    <ConfirmDialog
      v-model:open="deleteConfirmOpen"
      title="Delete Dynamic Redirect?"
      :description="`Delete SQLite redirect ${deleteTarget?.source_path} → ${deleteTarget?.target_url}?`"
      confirm-label="Delete Redirect"
      variant="destructive"
      :loading="saving"
      @confirm="executeDeleteRedirect"
    />

    <ConfirmDialog
      v-model:open="clear404ConfirmOpen"
      title="Clear All 404 Monitor Logs?"
      description="Delete all accumulated unresolved 404 entries from SQLite?"
      confirm-label="Clear 404 Log"
      variant="destructive"
      :loading="saving"
      @confirm="clear404Entry()"
    />
  </div>
</template>
