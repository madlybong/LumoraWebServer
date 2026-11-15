<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import {
  ArrowLeft,
  ChevronLeft,
  ChevronRight,
  Download,
  ExternalLink,
  Mail,
  RefreshCw,
  Search as SearchIcon,
  Send,
  ShieldAlert,
  TrendingUp,
} from "lucide-vue-next";
import { apiGet, apiPost, buildExportUrl } from "../lib/api";
import type { AuthStatusResponse, InquiryItem } from "../lib/types";
import { formatDateTime } from "../lib/utils";
import { adminStore, isEditor, isOwner, navigateTo, navigateToSubRoute, notifyToast, setAdminBadges } from "../stores/admin";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";
import Textarea from "../components/ui/Textarea.vue";

const items = ref<InquiryItem[]>([]);
const channels = ref<string[]>([]);
const total = ref(0);
const limit = 25;
const offset = ref(0);
const loading = ref(false);
const detailLoading = ref(false);

const filterQ = ref("");
const filterStatus = ref("");
const filterChannel = ref("");
const filterSync = ref("");
const filterDateFrom = ref("");
const filterDateTo = ref("");

const selectedIds = ref<string[]>([]);
const bulkStatus = ref("in_review");
const bulkRedactConfirmOpen = ref(false);

const selected = ref<InquiryItem | null>(null);
const editStatus = ref("new");
const editNotes = ref("");
const saving = ref(false);
const redactConfirmOpen = ref(false);

const STATUS_TABS = [
  { value: "", label: "All" },
  { value: "new", label: "New" },
  { value: "in_review", label: "In Review" },
  { value: "responded", label: "Responded" },
  { value: "archived", label: "Archived" },
  { value: "spam", label: "Spam" },
] as const;

const ATTRIBUTION_LABELS: Record<string, string> = {
  utm_source: "UTM Source",
  utm_medium: "UTM Medium",
  utm_campaign: "UTM Campaign",
  utm_term: "UTM Term",
  utm_content: "UTM Content",
  referrer: "HTTP Referrer",
  landing_page: "Landing Page",
};

const inDetailWorkspace = computed(
  () => adminStore.currentRoute === "inquiries" && adminStore.subRoute.mode === "detail" && Boolean(adminStore.subRoute.param1)
);

const currentReceiptIndex = computed(() => {
  if (!selected.value) return -1;
  return items.value.findIndex((i) => i.receipt_id === selected.value?.receipt_id);
});

const prevReceiptId = computed(() => {
  const idx = currentReceiptIndex.value;
  return idx > 0 ? items.value[idx - 1]?.receipt_id || "" : "";
});

const nextReceiptId = computed(() => {
  const idx = currentReceiptIndex.value;
  return idx !== -1 && idx < items.value.length - 1 ? items.value[idx + 1]?.receipt_id || "" : "";
});

const csvHref = computed(() =>
  buildExportUrl("inquiries.export", {
    format: "csv",
    status: filterStatus.value,
    channel: filterChannel.value,
    sync_status: filterSync.value,
    date_from: filterDateFrom.value,
    date_to: filterDateTo.value,
    q: filterQ.value,
  })
);

const receiptJsonHref = computed(() =>
  selected.value
    ? buildExportUrl("inquiries.export", {
        format: "json",
        q: selected.value.receipt_id,
      })
    : "#"
);

const allVisibleSelected = computed(
  () => items.value.length > 0 && items.value.every((i) => selectedIds.value.includes(i.receipt_id))
);

function humanizeFieldKey(key: string): string {
  return key
    .replace(/^_+/, "")
    .replace(/[_-]+/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDetailValue(val: unknown): string {
  if (val === null || val === undefined || val === "") return "—";
  if (Array.isArray(val)) {
    return val.map((item) => formatDetailValue(item)).join(", ");
  }
  if (typeof val === "object") {
    const entries = Object.entries(val as Record<string, unknown>).filter(
      ([, v]) => v !== null && v !== undefined && v !== ""
    );
    if (entries.length === 0) return "—";
    return entries.map(([k, v]) => `${humanizeFieldKey(k)}: ${formatDetailValue(v)}`).join(" · ");
  }
  return String(val);
}

const detailEntries = computed(() => {
  if (!selected.value?.details || typeof selected.value.details !== "object") return [];
  return Object.entries(selected.value.details)
    .filter(([key, val]) => key !== "_attribution" && val !== "" && val !== null && val !== undefined)
    .map(([key, val]) => ({
      key,
      label: humanizeFieldKey(key),
      value: formatDetailValue(val),
    }));
});

const hasAttributionObject = computed(() => {
  const attr = selected.value?.details?._attribution;
  return Boolean(attr && typeof attr === "object" && !Array.isArray(attr));
});

const attributionEntries = computed(() => {
  const raw = selected.value?.details?._attribution;
  if (!raw || typeof raw !== "object" || Array.isArray(raw)) return [];
  return Object.entries(raw as Record<string, unknown>)
    .filter(([, val]) => val !== "" && val !== null && val !== undefined)
    .map(([key, val]) => ({
      key,
      label: ATTRIBUTION_LABELS[key] || humanizeFieldKey(key),
      value: formatDetailValue(val),
    }));
});

const mailtoHref = computed(() => {
  if (!selected.value?.email) return "";
  return `mailto:${encodeURIComponent(selected.value.email)}?subject=${encodeURIComponent(
    "Re: " + (selected.value.subject || "Your inquiry")
  )}`;
});

async function syncBadgesQuietly() {
  try {
    const res = await apiGet<AuthStatusResponse>("auth.status");
    if (res.badges) setAdminBadges(res.badges);
  } catch {
    // Ignore background badge sync error
  }
}

async function load(resetOffset = false) {
  if (resetOffset) offset.value = 0;
  loading.value = true;
  try {
    const res = await apiGet<{ items: InquiryItem[]; total: number; channels?: string[] }>("inquiries.list", {
      status: filterStatus.value,
      channel: filterChannel.value,
      sync_status: filterSync.value,
      date_from: filterDateFrom.value,
      date_to: filterDateTo.value,
      q: filterQ.value,
      limit,
      offset: offset.value,
    });
    items.value = res.items || [];
    total.value = res.total || 0;
    if (Array.isArray(res.channels)) {
      channels.value = res.channels;
    }
    const visibleSet = new Set(items.value.map((i) => i.receipt_id));
    selectedIds.value = selectedIds.value.filter((id) => visibleSet.has(id));
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load inquiries.", "destructive");
  } finally {
    loading.value = false;
  }
}

function pickStatusTab(val: string) {
  filterStatus.value = val;
  void load(true);
}

function resetFilters() {
  filterQ.value = "";
  filterStatus.value = "";
  filterChannel.value = "";
  filterSync.value = "";
  filterDateFrom.value = "";
  filterDateTo.value = "";
  void load(true);
}

function toggleSelectAll() {
  if (allVisibleSelected.value) {
    selectedIds.value = [];
  } else {
    selectedIds.value = items.value.map((i) => i.receipt_id);
  }
}

function toggleRow(receiptId: string) {
  if (selectedIds.value.includes(receiptId)) {
    selectedIds.value = selectedIds.value.filter((id) => id !== receiptId);
  } else {
    selectedIds.value = [...selectedIds.value, receiptId];
  }
}

async function runBulk(operation: "status" | "retry_dispatch" | "redact") {
  if (selectedIds.value.length === 0) return;
  saving.value = true;
  try {
    const res = await apiPost<{ ok: boolean; affected: number; synced?: number }>("inquiries.bulk", {
      operation,
      receipt_ids: selectedIds.value,
      triage_status: bulkStatus.value,
      include_edge: true,
    });
    if (operation === "retry_dispatch") {
      notifyToast(`Bulk retry completed: ${res.synced || 0}/${res.affected} synced.`, "success");
    } else if (operation === "redact") {
      notifyToast(`Redacted PII across ${res.affected} inquiries.`, "success");
      bulkRedactConfirmOpen.value = false;
    } else {
      notifyToast(`Updated ${res.affected} inquiries to ${bulkStatus.value}.`, "success");
    }
    selectedIds.value = [];
    await load();
    void syncBadgesQuietly();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Bulk operation failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

async function loadReceiptDetail(receiptId: string) {
  detailLoading.value = true;
  try {
    const res = await apiGet<{ item: InquiryItem }>("inquiries.get", { receipt_id: receiptId });
    selected.value = res.item;
    editStatus.value = res.item.triage_status || "new";
    editNotes.value = res.item.internal_notes || "";
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Could not load inquiry details.", "destructive");
    selected.value = null;
  } finally {
    detailLoading.value = false;
  }
}

function openDetail(receiptId: string) {
  navigateToSubRoute("inquiries", "detail", receiptId);
}

watch(
  () => [adminStore.subRoute.mode, adminStore.subRoute.param1],
  ([mode, receiptId]) => {
    if (adminStore.currentRoute !== "inquiries") return;
    if (mode === "detail" && receiptId) {
      void loadReceiptDetail(receiptId);
    } else {
      selected.value = null;
    }
  },
  { immediate: true }
);

async function saveTriage() {
  if (!selected.value) return;
  saving.value = true;
  try {
    await apiPost("inquiries.update", {
      receipt_id: selected.value.receipt_id,
      triage_status: editStatus.value,
      internal_notes: editNotes.value,
    });
    notifyToast("Inquiry triage updated.", "success");
    await loadReceiptDetail(selected.value.receipt_id);
    await load();
    void syncBadgesQuietly();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Update failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

async function retryDispatch() {
  if (!selected.value) return;
  saving.value = true;
  try {
    const res = await apiPost<{ synced?: boolean; email_status?: string }>("inquiries.retry-dispatch", {
      receipt_id: selected.value.receipt_id,
      include_edge: true,
    });
    notifyToast(`Retry completed (Hub: ${res.synced ? "synced" : "failed"}, Email: ${res.email_status || "skipped"}).`);
    await loadReceiptDetail(selected.value.receipt_id);
    await load();
    void syncBadgesQuietly();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Retry failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

async function executeRedactPii() {
  if (!selected.value) return;
  saving.value = true;
  try {
    await apiPost("inquiries.redact", { receipt_id: selected.value.receipt_id });
    notifyToast("PII redacted in SQLite WAL.", "success");
    redactConfirmOpen.value = false;
    await loadReceiptDetail(selected.value.receipt_id);
    await load();
    void syncBadgesQuietly();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Redaction failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

function statusVariant(st: string) {
  if (st === "responded") return "success";
  if (st === "spam") return "destructive";
  if (st === "in_review") return "warning";
  return "secondary";
}

function syncVariant(st?: string) {
  if (st === "synced" || st === "sent") return "success";
  if (st === "failed") return "destructive";
  if (st === "queued") return "warning";
  return "outline";
}

onMounted(() => load());
</script>

<template>
  <div>
  <!-- INBOX DIRECTORY VIEW -->
  <div v-if="!inDetailWorkspace" class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Inquiries Inbox</h2>
        <p class="text-sm text-muted-foreground">{{ total }} submission(s) matching current filters.</p>
      </div>
      <div class="flex items-center gap-2">
        <a
          v-if="isEditor"
          :href="csvHref"
          class="inline-flex h-8 items-center gap-1.5 rounded-md border border-input bg-background px-3 text-xs font-medium hover:bg-accent"
        >
          <Download class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Export CSV</span>
        </a>
        <Button variant="outline" size="sm" :disabled="loading" @click="load(false)">
          <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Refresh</span>
        </Button>
      </div>
    </div>

    <!-- Quick Status Tabs -->
    <div class="flex flex-wrap items-center gap-1.5 border-b border-border pb-3">
      <button
        v-for="tab in STATUS_TABS"
        :key="tab.value || 'all'"
        type="button"
        :class="[
          'rounded-md px-3 py-1.5 text-xs font-medium transition-colors cursor-pointer',
          filterStatus === tab.value
            ? 'bg-primary text-primary-foreground shadow-xs'
            : 'bg-card border border-border text-muted-foreground hover:text-foreground hover:bg-muted/40',
        ]"
        @click="pickStatusTab(tab.value)"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Balanced Filter Toolbar -->
    <Card class="p-4">
      <div role="search" aria-label="Filter inquiries">
        <form class="space-y-3" @submit.prevent="load(true)">
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">
            <div class="sm:col-span-2 space-y-1">
              <label for="inq-search" class="text-xs font-medium">Search</label>
              <Input id="inq-search" v-model="filterQ" type="search" name="q" placeholder="Receipt ID, email, name, subject..." />
            </div>
            <div class="space-y-1">
              <label for="inq-channel" class="text-xs font-medium">Channel</label>
              <select id="inq-channel" v-model="filterChannel" name="channel" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
                <option value="">All channels</option>
                <option v-for="ch in channels" :key="ch" :value="ch">{{ ch }}</option>
              </select>
            </div>
            <div class="space-y-1">
              <label for="inq-sync" class="text-xs font-medium">Sync State</label>
              <select id="inq-sync" v-model="filterSync" name="sync_status" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
                <option value="">Any sync</option>
                <option value="synced">Synced</option>
                <option value="queued">Queued</option>
                <option value="failed">Failed</option>
                <option value="skipped">Skipped</option>
              </select>
            </div>
            <div class="space-y-1">
              <label for="inq-from" class="text-xs font-medium">From Date</label>
              <Input id="inq-from" v-model="filterDateFrom" type="date" name="date_from" />
            </div>
            <div class="space-y-1">
              <label for="inq-to" class="text-xs font-medium">To Date</label>
              <Input id="inq-to" v-model="filterDateTo" type="date" name="date_to" />
            </div>
          </div>

          <div class="flex flex-wrap items-center justify-end gap-2 pt-2 border-t border-border">
            <Button
              v-if="filterQ || filterStatus || filterChannel || filterSync || filterDateFrom || filterDateTo"
              type="button"
              variant="ghost"
              size="sm"
              @click="resetFilters"
            >
              Reset Filters
            </Button>
            <Button type="submit" size="sm">
              <SearchIcon class="h-3.5 w-3.5" aria-hidden="true" />
              <span>Apply Filters</span>
            </Button>
          </div>
        </form>
      </div>
    </Card>

    <Card v-if="isEditor && selectedIds.length > 0" class="p-3 flex flex-wrap items-center justify-between gap-3 bg-muted/40">
      <div class="text-xs font-medium">{{ selectedIds.length }} selected</div>
      <div class="flex flex-wrap items-center gap-2">
        <label for="bulk-triage-select" class="sr-only">Bulk triage status</label>
        <select id="bulk-triage-select" v-model="bulkStatus" class="h-8 rounded-md border border-input bg-background px-2 text-xs">
          <option value="new">New</option>
          <option value="in_review">In Review</option>
          <option value="responded">Responded</option>
          <option value="archived">Archived</option>
          <option value="spam">Spam</option>
        </select>
        <Button size="sm" variant="outline" :disabled="saving" @click="runBulk('status')">Apply Status</Button>
        <Button size="sm" variant="outline" :disabled="saving" @click="runBulk('retry_dispatch')">
          <Send class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Retry Dispatch</span>
        </Button>
        <Button v-if="isOwner" size="sm" variant="destructive" :disabled="saving" @click="bulkRedactConfirmOpen = true">
          <ShieldAlert class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Redact PII</span>
        </Button>
      </div>
    </Card>

    <Card class="overflow-hidden">
      <div tabindex="0" role="region" aria-labelledby="inquiries-caption" class="lumora-table-wrap max-h-[65vh]">
        <table class="w-full text-sm text-left border-collapse">
          <caption id="inquiries-caption" class="sr-only">Inquiry submissions table</caption>
          <thead class="sticky top-0 bg-muted/90 backdrop-blur border-b border-border text-xs uppercase text-muted-foreground">
            <tr>
              <th v-if="isEditor" scope="col" class="px-3 py-2.5 w-10">
                <input
                  type="checkbox"
                  aria-label="Select all visible inquiries"
                  :checked="allVisibleSelected"
                  class="rounded border-input cursor-pointer"
                  @change="toggleSelectAll"
                />
              </th>
              <th scope="col" class="px-4 py-2.5 font-medium">Receipt</th>
              <th scope="col" class="px-4 py-2.5 font-medium">Sender</th>
              <th scope="col" class="px-4 py-2.5 font-medium">Channel</th>
              <th scope="col" class="px-4 py-2.5 font-medium">Subject</th>
              <th scope="col" class="px-4 py-2.5 font-medium">Triage</th>
              <th scope="col" class="px-4 py-2.5 font-medium">Sync</th>
              <th scope="col" class="px-4 py-2.5 font-medium">Received</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-if="items.length === 0">
              <td :colspan="isEditor ? 8 : 7" class="px-4 py-8 text-center text-muted-foreground">No inquiries match the current filter.</td>
            </tr>
            <tr v-for="item in items" :key="item.receipt_id" class="hover:bg-muted/40">
              <td v-if="isEditor" class="px-3 py-3">
                <input
                  type="checkbox"
                  :aria-label="`Select receipt ${item.receipt_id}`"
                  :checked="selectedIds.includes(item.receipt_id)"
                  class="rounded border-input cursor-pointer"
                  @change="toggleRow(item.receipt_id)"
                />
              </td>
              <th scope="row" class="px-4 py-3 font-mono text-xs font-medium whitespace-nowrap">
                <button type="button" class="underline underline-offset-2 cursor-pointer" @click="openDetail(item.receipt_id)">
                  {{ item.receipt_id }}
                </button>
              </th>
              <td class="px-4 py-3">
                <div class="font-medium">{{ item.full_name }}</div>
                <div class="text-xs text-muted-foreground">{{ item.email }}</div>
              </td>
              <td class="px-4 py-3 text-xs">{{ item.channel_label || item.channel }}</td>
              <td class="px-4 py-3 max-w-xs truncate">
                <button type="button" class="text-left hover:underline cursor-pointer truncate max-w-full" @click="openDetail(item.receipt_id)">
                  {{ item.subject }}
                </button>
              </td>
              <td class="px-4 py-3">
                <Badge :variant="statusVariant(item.triage_status)">{{ item.triage_status }}</Badge>
              </td>
              <td class="px-4 py-3">
                <Badge :variant="syncVariant(item.sync_status)" class="font-mono text-[11px]">{{ item.sync_status }}</Badge>
              </td>
              <td class="px-4 py-3 text-xs text-muted-foreground whitespace-nowrap">{{ formatDateTime(item.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="total > limit" class="flex items-center justify-between border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
        <span>Showing {{ offset + 1 }}–{{ Math.min(offset + limit, total) }} of {{ total }}</span>
        <div class="flex gap-2">
          <Button variant="outline" size="sm" :disabled="offset === 0 || loading" @click="offset = Math.max(0, offset - limit); void load(false)">
            Previous
          </Button>
          <Button variant="outline" size="sm" :disabled="offset + limit >= total || loading" @click="offset += limit; void load(false)">
            Next
          </Button>
        </div>
      </div>
    </Card>
  </div>

  <!-- FULL-PAGE RECEIPT DETAIL & TRIAGE WORKSPACE -->
  <div v-else class="space-y-6">
    <!-- Sticky Top Action Bar -->
    <div class="sticky -top-4 sm:-top-6 lg:-top-8 z-20 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-3 bg-background/95 backdrop-blur border-b border-border flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-3 min-w-0">
        <Button variant="outline" size="sm" @click="navigateTo('inquiries')">
          <ArrowLeft class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Back to Inbox</span>
        </Button>
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <h2 class="text-base sm:text-lg font-semibold font-mono tracking-tight truncate">
              {{ selected?.receipt_id || adminStore.subRoute.param1 }}
            </h2>
            <Badge v-if="selected" :variant="statusVariant(selected.triage_status)">
              {{ selected.triage_status }}
            </Badge>
          </div>
          <div v-if="selected" class="text-xs text-muted-foreground truncate">
            {{ selected.channel_label || selected.channel }} · SLA: {{ selected.sla_window }} · Received {{ formatDateTime(selected.created_at) }}
          </div>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <div v-if="prevReceiptId || nextReceiptId" class="inline-flex items-center rounded-md border border-border overflow-hidden">
          <Button
            variant="ghost"
            size="sm"
            class="rounded-none h-8 px-2 border-r border-border"
            :disabled="!prevReceiptId"
            title="Previous receipt"
            aria-label="Previous receipt"
            @click="prevReceiptId && openDetail(prevReceiptId)"
          >
            <ChevronLeft class="h-3.5 w-3.5" aria-hidden="true" />
          </Button>
          <Button
            variant="ghost"
            size="sm"
            class="rounded-none h-8 px-2"
            :disabled="!nextReceiptId"
            title="Next receipt"
            aria-label="Next receipt"
            @click="nextReceiptId && openDetail(nextReceiptId)"
          >
            <ChevronRight class="h-3.5 w-3.5" aria-hidden="true" />
          </Button>
        </div>

        <a
          v-if="isEditor && selected"
          :href="receiptJsonHref"
          class="inline-flex h-8 items-center gap-1.5 rounded-md border border-input bg-background px-3 text-xs font-medium hover:bg-accent"
        >
          <Download class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Export JSON</span>
        </a>
        <a
          v-if="mailtoHref"
          :href="mailtoHref"
          class="inline-flex h-8 items-center gap-1.5 rounded-md border border-input bg-background px-3 text-xs font-medium hover:bg-accent"
        >
          <Mail class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Reply via Email</span>
        </a>
        <Button
          v-if="isOwner && selected"
          variant="destructive"
          size="sm"
          :disabled="saving"
          @click="redactConfirmOpen = true"
        >
          <ShieldAlert class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Redact PII</span>
        </Button>
      </div>
    </div>

    <div v-if="detailLoading && !selected" class="py-12 text-center text-sm text-muted-foreground">
      Loading inquiry receipt...
    </div>

    <div v-else-if="!selected" class="py-12 text-center space-y-3">
      <p class="text-sm text-muted-foreground">Inquiry receipt not found.</p>
      <Button variant="outline" size="sm" @click="navigateTo('inquiries')">Return to Inbox</Button>
    </div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
      <!-- Left Submission Content Column -->
      <div class="lg:col-span-8 space-y-5 min-w-0">
        <!-- Submitter Identity Card -->
        <Card class="p-5 space-y-4">
          <div class="flex items-center justify-between border-b border-border pb-2.5">
            <h3 class="text-sm font-semibold">Submitter Identity &amp; Context</h3>
            <span class="text-xs font-mono text-muted-foreground">ID #{{ selected.id }}</span>
          </div>
          <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 text-xs">
            <div class="space-y-0.5">
              <dt class="text-muted-foreground">Full Name</dt>
              <dd class="text-sm font-semibold">{{ selected.full_name }}</dd>
            </div>
            <div class="space-y-0.5 min-w-0">
              <dt class="text-muted-foreground">Email Address</dt>
              <dd class="text-sm font-medium truncate">
                <a v-if="mailtoHref" :href="mailtoHref" class="underline underline-offset-2">{{ selected.email }}</a>
                <span v-else>{{ selected.email || "—" }}</span>
              </dd>
            </div>
            <div class="space-y-0.5">
              <dt class="text-muted-foreground">Phone Number</dt>
              <dd class="text-sm font-mono">{{ selected.phone || "—" }}</dd>
            </div>
            <div class="space-y-0.5">
              <dt class="text-muted-foreground">Organization</dt>
              <dd class="font-medium">{{ selected.organization || "—" }}</dd>
            </div>
            <div class="space-y-0.5">
              <dt class="text-muted-foreground">Preferred Mode</dt>
              <dd class="font-medium capitalize">{{ selected.consultation_mode || "—" }}</dd>
            </div>
            <div class="space-y-0.5">
              <dt class="text-muted-foreground">Facility / Location</dt>
              <dd class="font-medium">{{ selected.facility_location || "—" }}</dd>
            </div>
          </dl>
        </Card>

        <!-- Inquiry Message Body Card -->
        <Card class="p-5 space-y-3">
          <h3 class="text-sm font-semibold border-b border-border pb-2.5">Subject &amp; Message</h3>
          <div class="space-y-1">
            <div class="text-xs text-muted-foreground">Subject</div>
            <div class="text-base font-semibold">{{ selected.subject }}</div>
          </div>
          <div class="space-y-1">
            <div class="text-xs text-muted-foreground">Submitted Message Body</div>
            <div class="whitespace-pre-wrap rounded-md border border-border bg-muted/20 p-4 text-sm leading-relaxed">
              {{ selected.message || "(No message body)" }}
            </div>
          </div>
        </Card>

        <!-- Channel Custom Fields Card (never prints [object Object]) -->
        <Card v-if="detailEntries.length > 0" class="p-5 space-y-3">
          <h3 class="text-sm font-semibold border-b border-border pb-2.5">Channel Custom Fields</h3>
          <dl class="grid gap-3 sm:grid-cols-2 text-xs">
            <div v-for="entry in detailEntries" :key="entry.key" class="rounded-md border border-border bg-muted/20 p-3 space-y-0.5">
              <dt class="text-muted-foreground font-medium">{{ entry.label }}</dt>
              <dd class="text-sm font-medium break-words">{{ entry.value }}</dd>
            </div>
          </dl>
        </Card>

        <!-- Traffic & Attribution Card (parsed cleanly from _attribution) -->
        <Card v-if="hasAttributionObject" class="p-5 space-y-3">
          <div class="flex items-center justify-between border-b border-border pb-2.5">
            <div class="flex items-center gap-2">
              <TrendingUp class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
              <h3 class="text-sm font-semibold">Traffic &amp; Attribution</h3>
            </div>
            <Badge variant="outline" class="font-mono text-[10px]">_attribution</Badge>
          </div>

          <div v-if="attributionEntries.length === 0" class="text-xs text-muted-foreground py-2">
            Direct visit — no UTM campaign parameters or external referrer captured.
          </div>
          <dl v-else class="grid gap-3 sm:grid-cols-2 text-xs">
            <div v-for="attr in attributionEntries" :key="attr.key" class="rounded-md border border-border bg-muted/20 p-3 space-y-0.5 min-w-0">
              <dt class="text-muted-foreground font-mono text-[11px]">{{ attr.label }}</dt>
              <dd class="font-medium break-all flex items-center gap-1">
                <span>{{ attr.value }}</span>
                <ExternalLink v-if="attr.value.startsWith('http')" class="h-3 w-3 shrink-0 text-muted-foreground" aria-hidden="true" />
              </dd>
            </div>
          </dl>
        </Card>
      </div>

      <!-- Right Sticky Triage & Sync Inspector -->
      <div class="lg:col-span-4 space-y-5 lg:sticky lg:top-16 min-w-0">
        <Card class="p-5 space-y-4">
          <h3 class="text-sm font-semibold border-b border-border pb-2.5">Triage &amp; Internal Notes</h3>
          <form v-if="isEditor" class="space-y-4" @submit.prevent="saveTriage">
            <div class="space-y-1">
              <label for="detail-triage" class="text-xs font-medium">Triage Status</label>
              <select id="detail-triage" v-model="editStatus" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
                <option value="new">New</option>
                <option value="in_review">In Review</option>
                <option value="responded">Responded</option>
                <option value="archived">Archived</option>
                <option value="spam">Spam</option>
              </select>
            </div>
            <div class="space-y-1">
              <label for="detail-notes" class="text-xs font-medium">Internal Operator Notes</label>
              <Textarea id="detail-notes" v-model="editNotes" rows="5" placeholder="Record handoff notes, qualification context, or follow-up dates..." />
            </div>
            <Button type="submit" size="sm" class="w-full" :disabled="saving">
              {{ saving ? "Saving..." : "Save Triage Decision" }}
            </Button>
          </form>
          <div v-else class="space-y-2 text-xs">
            <div><span class="text-muted-foreground">Status:</span> <Badge :variant="statusVariant(selected.triage_status)">{{ selected.triage_status }}</Badge></div>
            <div><span class="text-muted-foreground">Notes:</span> {{ selected.internal_notes || "None" }}</div>
          </div>
        </Card>

        <Card class="p-5 space-y-4">
          <h3 class="text-sm font-semibold border-b border-border pb-2.5">Hub &amp; Edge Dispatch</h3>
          <dl class="space-y-2.5 text-xs">
            <div class="flex items-center justify-between">
              <dt class="text-muted-foreground">Hub Sync Status</dt>
              <dd><Badge :variant="syncVariant(selected.sync_status)" class="font-mono">{{ selected.sync_status || "skipped" }}</Badge></dd>
            </div>
            <div class="flex items-center justify-between">
              <dt class="text-muted-foreground">Email Alert</dt>
              <dd><Badge :variant="syncVariant(selected.email_status)" class="font-mono">{{ selected.email_status || "skipped" }}</Badge></dd>
            </div>
            <div class="flex items-center justify-between">
              <dt class="text-muted-foreground">WhatsApp Alert</dt>
              <dd><Badge :variant="syncVariant(selected.whatsapp_status)" class="font-mono">{{ selected.whatsapp_status || "skipped" }}</Badge></dd>
            </div>
            <div v-if="selected.hub_receipt" class="flex items-center justify-between gap-2">
              <dt class="text-muted-foreground">Hub Receipt</dt>
              <dd class="font-mono truncate">{{ selected.hub_receipt }}</dd>
            </div>
            <div v-if="selected.updated_at" class="flex items-center justify-between">
              <dt class="text-muted-foreground">Last Updated</dt>
              <dd class="text-muted-foreground">{{ formatDateTime(selected.updated_at) }}</dd>
            </div>
          </dl>
          <div v-if="isEditor" class="pt-2 border-t border-border">
            <Button variant="outline" size="sm" class="w-full" :disabled="saving" @click="retryDispatch">
              <Send class="h-3.5 w-3.5" aria-hidden="true" />
              <span>Retry Hub &amp; Edge Dispatch</span>
            </Button>
          </div>
        </Card>
      </div>
    </div>

    <ConfirmDialog
      v-model:open="redactConfirmOpen"
      title="Permanently Redact PII?"
      :description="`Redact personal details for inquiry ${selected?.receipt_id}? This action overwrites stored name, email, phone, and message in SQLite.`"
      confirm-label="Redact PII"
      variant="destructive"
      :loading="saving"
      @confirm="executeRedactPii"
    />
  </div>

  <ConfirmDialog
    v-model:open="bulkRedactConfirmOpen"
    title="Bulk Redact PII?"
    :description="`Permanently redact PII across ${selectedIds.length} selected inquiries in SQLite?`"
    confirm-label="Redact Selected"
    variant="destructive"
    :loading="saving"
    @confirm="runBulk('redact')"
  />
  </div>
</template>
