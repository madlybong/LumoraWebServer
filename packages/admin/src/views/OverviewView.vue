<script setup lang="ts">
import { onMounted, ref } from "vue";
import {
  ArrowRightLeft,
  BookOpen,
  Download,
  FileText,
  Image as ImageIcon,
  Inbox,
  RefreshCw,
  ShieldAlert,
  Upload,
} from "lucide-vue-next";
import { apiGet, apiPost, buildExportUrl } from "../lib/api";
import type { AdminBadges } from "../lib/types";
import { formatDateTime } from "../lib/utils";
import { isOwner, navigateTo, notifyToast, setAdminBadges } from "../stores/admin";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";

interface OverviewResponse {
  ok: boolean;
  stats: Record<string, number>;
  badges?: AdminBadges;
  audit: Array<{
    id: number;
    actor_email: string;
    action: string;
    target_type: string;
    target_id: string;
    created_at: string;
  }>;
  environment: Record<string, string | boolean>;
  features: Record<string, boolean>;
}

const loading = ref(true);
const importing = ref(false);
const data = ref<OverviewResponse | null>(null);
const pendingSnapshot = ref<Record<string, unknown> | null>(null);
const importConfirmOpen = ref(false);
const importSummary = ref("");

async function load() {
  loading.value = true;
  try {
    const res = await apiGet<OverviewResponse>("overview.get");
    data.value = res;
    if (res.badges) {
      setAdminBadges(res.badges);
    }
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load overview.", "destructive");
  } finally {
    loading.value = false;
  }
}

async function handleSnapshotFile(files: FileList | null) {
  const file = files?.[0];
  if (!file) return;
  try {
    const text = await file.text();
    const parsed = JSON.parse(text) as Record<string, unknown>;
    if (parsed.format !== "lumora-cms-snapshot-v1" || typeof parsed.tables !== "object" || !parsed.tables) {
      notifyToast("Invalid CMS snapshot JSON format.", "destructive");
      return;
    }
    const tables = parsed.tables as Record<string, unknown[]>;
    const posts = Array.isArray(tables.cms_blog_posts) ? tables.cms_blog_posts.length : 0;
    const docs = Array.isArray(tables.cms_docs_articles) ? tables.cms_docs_articles.length : 0;
    const redirects = Array.isArray(tables.cms_redirects) ? tables.cms_redirects.length : 0;
    const channels = Array.isArray(tables.cms_channels) ? tables.cms_channels.length : 0;
    importSummary.value = `Import ${posts} blog posts, ${docs} docs articles, ${redirects} redirects, and ${channels} channels from "${file.name}"? Matching slugs/keys will be updated cleanly without overwriting credentials or inquiry PII.`;
    pendingSnapshot.value = parsed;
    importConfirmOpen.value = true;
  } catch {
    notifyToast("Could not parse snapshot JSON file.", "destructive");
  }
}

async function executeImport() {
  if (!pendingSnapshot.value) return;
  importing.value = true;
  try {
    const res = await apiPost<{ ok: boolean; counts: Record<string, number> }>("system.import-cms", pendingSnapshot.value);
    const total = Object.values(res.counts || {}).reduce((acc, v) => acc + v, 0);
    notifyToast(`Snapshot imported cleanly (${total} items upserted).`, "success");
    importConfirmOpen.value = false;
    pendingSnapshot.value = null;
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Snapshot import failed.", "destructive");
  } finally {
    importing.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Operations Overview</h2>
        <p class="text-sm text-muted-foreground">Live micro-kernel status, SQLite hybrid metrics, and audit log.</p>
      </div>
      <Button variant="outline" size="sm" :disabled="loading" @click="load">
        <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
        <span>Refresh</span>
      </Button>
    </div>

    <div v-if="data" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Card class="p-4 flex flex-col justify-between gap-3 hover:border-foreground/20 transition-colors">
        <div class="flex items-center justify-between text-xs text-muted-foreground">
          <span class="font-medium">New Inquiries</span>
          <span class="flex h-7 w-7 items-center justify-center rounded-md bg-muted text-foreground">
            <Inbox class="h-3.5 w-3.5" aria-hidden="true" />
          </span>
        </div>
        <div>
          <div class="text-2xl font-bold tracking-tight">{{ data.stats.new_inquiries }}</div>
          <button type="button" class="mt-1 text-xs text-muted-foreground hover:text-foreground underline cursor-pointer" @click="navigateTo('inquiries')">
            {{ data.stats.total_inquiries }} total · View inbox
          </button>
        </div>
      </Card>

      <Card class="p-4 flex flex-col justify-between gap-3 hover:border-foreground/20 transition-colors">
        <div class="flex items-center justify-between text-xs text-muted-foreground">
          <span class="font-medium">Published &amp; Draft Content</span>
          <span class="flex h-7 w-7 items-center justify-center rounded-md bg-muted text-foreground">
            <FileText class="h-3.5 w-3.5" aria-hidden="true" />
          </span>
        </div>
        <div>
          <div class="text-2xl font-bold tracking-tight">{{ data.stats.blog_posts + data.stats.docs_articles }}</div>
          <div class="mt-1 flex gap-3 text-xs text-muted-foreground">
            <button type="button" class="hover:text-foreground underline cursor-pointer" @click="navigateTo('blog')">
              {{ data.stats.blog_posts }} blog
            </button>
            <button type="button" class="hover:text-foreground underline cursor-pointer" @click="navigateTo('docs')">
              {{ data.stats.docs_articles }} docs
            </button>
          </div>
        </div>
      </Card>

      <Card class="p-4 flex flex-col justify-between gap-3 hover:border-foreground/20 transition-colors">
        <div class="flex items-center justify-between text-xs text-muted-foreground">
          <span class="font-medium">Media, Redirects &amp; 404s</span>
          <span class="flex h-7 w-7 items-center justify-center rounded-md bg-muted text-foreground">
            <ImageIcon class="h-3.5 w-3.5" aria-hidden="true" />
          </span>
        </div>
        <div>
          <div class="text-2xl font-bold tracking-tight">{{ data.stats.media_count }} assets</div>
          <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
            <button type="button" class="hover:text-foreground underline cursor-pointer" @click="navigateTo('routing')">
              <ArrowRightLeft class="inline h-3 w-3 mr-0.5" aria-hidden="true" />{{ data.stats.active_redirects }} rules · {{ data.stats.unresolved_404s || 0 }} 404s
            </button>
            <button type="button" class="hover:text-foreground underline cursor-pointer" @click="navigateTo('media')">Library</button>
          </div>
        </div>
      </Card>

      <Card class="p-4 flex flex-col justify-between gap-3 hover:border-foreground/20 transition-colors">
        <div class="flex items-center justify-between text-xs text-muted-foreground">
          <span class="font-medium">Outbox &amp; Auth Alerts (24h)</span>
          <span class="flex h-7 w-7 items-center justify-center rounded-md bg-muted text-foreground">
            <ShieldAlert class="h-3.5 w-3.5" aria-hidden="true" />
          </span>
        </div>
        <div>
          <div class="text-2xl font-bold tracking-tight">{{ data.stats.pending_outbox }}</div>
          <div class="mt-1 text-xs text-muted-foreground">
            Failed auth attempts: <strong class="text-foreground">{{ data.stats.failed_auth_24h }}</strong>
          </div>
        </div>
      </Card>
    </div>

    <div v-if="data" class="grid gap-5 lg:grid-cols-3 items-start">
      <Card class="lg:col-span-2 overflow-hidden">
        <div class="px-5 py-4 border-b border-border flex items-center justify-between gap-2">
          <div>
            <h3 class="text-sm font-semibold">Recent Admin Activity</h3>
            <p class="text-xs text-muted-foreground">Latest operator mutations across SQLite and CMS modules.</p>
          </div>
          <Button variant="ghost" size="sm" @click="navigateTo('security')">View Security Log</Button>
        </div>
        <div v-if="data.audit.length === 0" class="p-8 text-sm text-muted-foreground text-center">
          No audit actions recorded yet.
        </div>
        <ul v-else class="max-h-96 overflow-y-auto overscroll-contain lumora-scroll-area divide-y divide-border text-sm">
          <li
            v-for="entry in data.audit"
            :key="entry.id"
            class="px-5 py-3 flex flex-wrap items-center justify-between gap-2 hover:bg-muted/20 transition-colors"
          >
            <div class="min-w-0">
              <span class="font-mono text-xs font-medium">{{ entry.action }}</span>
              <span class="text-muted-foreground mx-1.5">·</span>
              <span class="text-xs text-muted-foreground">{{ entry.actor_email }}</span>
              <span v-if="entry.target_id" class="text-xs text-muted-foreground ml-1.5 font-mono">({{ entry.target_id }})</span>
            </div>
            <span class="text-xs text-muted-foreground whitespace-nowrap">{{ formatDateTime(entry.created_at) }}</span>
          </li>
        </ul>
      </Card>

      <div class="space-y-4">
        <Card class="overflow-hidden">
          <div class="px-5 py-4 border-b border-border flex items-center gap-2">
            <BookOpen class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
            <h3 class="text-sm font-semibold">Runtime Capabilities</h3>
          </div>
          <dl class="p-5 space-y-2.5 text-xs">
            <div class="flex items-center justify-between gap-2">
              <dt class="text-muted-foreground">PHP Runtime</dt>
              <dd class="font-mono">{{ data.environment.php_version }}</dd>
            </div>
            <div class="flex items-center justify-between gap-2">
              <dt class="text-muted-foreground">SQLite WAL</dt>
              <dd><Badge :variant="data.environment.sqlite_wal ? 'success' : 'destructive'">{{ data.environment.sqlite_wal ? "Ready" : "Off" }}</Badge></dd>
            </div>
            <div class="flex items-center justify-between gap-2">
              <dt class="text-muted-foreground">OpenSSL AES-GCM</dt>
              <dd><Badge :variant="data.environment.openssl ? 'success' : 'warning'">{{ data.environment.openssl ? "Active" : "Missing" }}</Badge></dd>
            </div>
            <div class="flex items-center justify-between gap-2">
              <dt class="text-muted-foreground">Password KDF</dt>
              <dd class="font-mono">{{ data.environment.argon2id ? "Argon2id" : "Bcrypt" }}</dd>
            </div>
            <div class="flex items-center justify-between gap-2">
              <dt class="text-muted-foreground">Admin Mount Path</dt>
              <dd class="font-mono">{{ data.environment.admin_path }}</dd>
            </div>
          </dl>
        </Card>

        <Card v-if="isOwner" class="overflow-hidden">
          <div class="px-5 py-4 border-b border-border">
            <h3 class="text-sm font-semibold">Portable CMS Snapshot</h3>
            <p class="text-xs text-muted-foreground mt-0.5">
              Export or restore CMS content, routes, discovery overrides, and channels (excludes PII and secrets).
            </p>
          </div>
          <div class="px-5 py-3.5 bg-muted/30 flex flex-wrap gap-2">
            <a
              :href="buildExportUrl('system.export-cms')"
              class="inline-flex h-8 items-center gap-1.5 rounded-md border border-input bg-background px-3 text-xs font-medium hover:bg-accent"
            >
              <Download class="h-3.5 w-3.5" aria-hidden="true" />
              <span>Export JSON</span>
            </a>
            <label class="inline-flex h-8 items-center gap-1.5 rounded-md border border-input bg-background px-3 text-xs font-medium hover:bg-accent cursor-pointer">
              <Upload class="h-3.5 w-3.5" aria-hidden="true" />
              <span>{{ importing ? "Importing..." : "Import JSON" }}</span>
              <input
                type="file"
                accept="application/json,.json"
                class="sr-only"
                :disabled="importing"
                @change="handleSnapshotFile(($event.target as HTMLInputElement).files); (($event.target as HTMLInputElement).value = '')"
              />
            </label>
          </div>
        </Card>
      </div>
    </div>

    <ConfirmDialog
      v-model:open="importConfirmOpen"
      title="Import CMS Snapshot?"
      :description="importSummary"
      confirm-label="Import Snapshot"
      :loading="importing"
      @confirm="executeImport"
    />
  </div>
</template>
