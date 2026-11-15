<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import {
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  Eye,
  Pencil,
  Plus,
  RotateCcw,
  Save,
  Trash2,
} from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import type { ChannelConfig, ChannelCustomField } from "../lib/types";
import { adminStore, isOwner, navigateTo, navigateToSubRoute, notifyToast } from "../stores/admin";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";
import SwitchControl from "../components/ui/SwitchControl.vue";

interface MergedChannelRecord extends Partial<ChannelConfig> {
  label?: string;
  heading?: string;
  description?: string;
  sla?: string;
}

const channels = ref<Record<string, MergedChannelRecord>>({});
const overrides = ref<Array<Record<string, unknown>>>([]);
const defaultChannel = ref("general");
const loading = ref(false);
const saving = ref(false);
const hydratedRouteKey = ref("");

const deleteConfirmOpen = ref(false);
const deleteTargetKey = ref("");

const form = reactive({
  channel_key: "",
  label: "",
  description: "",
  sla_window: "1-2 Business Days",
  is_active: true,
  sort_order: 100,
  code: "",
  subject_placeholder: "",
  message_label: "",
  message_placeholder: "",
  button_text: "",
  fields: [] as Array<ChannelCustomField & { optionsStr: string }>,
});

const inStudio = computed(
  () => adminStore.currentRoute === "channels" && (adminStore.subRoute.mode === "new" || adminStore.subRoute.mode === "edit")
);
const isCreateMode = computed(() => adminStore.currentRoute === "channels" && adminStore.subRoute.mode === "new");

const currentOverrideRecord = computed(() => {
  if (isCreateMode.value || !form.channel_key) return null;
  return overrides.value.find((o) => String(o.channel_key) === form.channel_key) || null;
});

function isOverridden(key: string): boolean {
  return overrides.value.some((o) => String(o.channel_key) === key);
}

function populateNew() {
  Object.assign(form, {
    channel_key: "",
    label: "",
    description: "",
    sla_window: "1-2 Business Days",
    is_active: true,
    sort_order: 100,
    code: "",
    subject_placeholder: "",
    message_label: "",
    message_placeholder: "",
    button_text: "",
    fields: [],
  });
}

function populateFromMerged(key: string, ch: MergedChannelRecord, ov?: Record<string, unknown>) {
  let ovCfg: Partial<ChannelConfig> = {};
  if (ov?.config_json) {
    try {
      ovCfg = JSON.parse(String(ov.config_json || "{}")) as Partial<ChannelConfig>;
    } catch {
      ovCfg = {};
    }
  }
  const sourceFields = Array.isArray(ovCfg.fields) ? ovCfg.fields : ch.fields;
  const fields = Array.isArray(sourceFields)
    ? sourceFields.map((f) => ({
        name: f.name || "",
        label: f.label || "",
        type: (f.type === "enum" ? "enum" : "string") as "string" | "enum",
        required: Boolean(f.required),
        placeholder: f.placeholder || "",
        optionsStr: Array.isArray(f.options) ? f.options.join(", ") : "",
      }))
    : [];

  Object.assign(form, {
    channel_key: key,
    label: String(ov?.label ?? ch.label ?? key),
    description: String(ov?.description ?? ch.heading ?? ch.description ?? ""),
    sla_window: String(ov?.sla_window ?? ch.sla ?? "1-2 Business Days"),
    is_active: ov ? Boolean(Number(ov.is_active ?? 1)) : true,
    sort_order: Number(ov?.sort_order ?? 100),
    code: ovCfg.code || ch.code || "",
    subject_placeholder: ovCfg.subject_placeholder || ch.subject_placeholder || "",
    message_label: ovCfg.message_label || ch.message_label || "",
    message_placeholder: ovCfg.message_placeholder || ch.message_placeholder || "",
    button_text: ovCfg.button_text || ch.button_text || "",
    fields,
  });
}

function syncStudioFromRoute(force = false) {
  if (adminStore.currentRoute !== "channels") return;
  const mode = adminStore.subRoute.mode;
  const keyParam = adminStore.subRoute.param1 || "";
  const routeKey = `${mode}:${keyParam}`;
  if (!force && hydratedRouteKey.value === routeKey) return;

  if (mode === "new") {
    hydratedRouteKey.value = routeKey;
    populateNew();
    return;
  }
  if (mode === "edit" && keyParam) {
    const ch = channels.value[keyParam];
    const ov = overrides.value.find((o) => String(o.channel_key) === keyParam);
    if (ch || ov) {
      hydratedRouteKey.value = routeKey;
      populateFromMerged(keyParam, ch || {}, ov);
    } else if (!loading.value && Object.keys(channels.value).length > 0) {
      notifyToast(`Channel "${keyParam}" not found.`, "destructive");
      navigateTo("channels");
    }
    return;
  }
  hydratedRouteKey.value = "";
}

watch(
  () => [adminStore.subRoute.mode, adminStore.subRoute.param1],
  () => {
    if (adminStore.currentRoute !== "channels") return;
    syncStudioFromRoute();
  }
);

function openCreateChannel() {
  hydratedRouteKey.value = "";
  navigateToSubRoute("channels", "new");
}

function openEditChannel(key: string) {
  hydratedRouteKey.value = "";
  navigateToSubRoute("channels", "edit", key);
}

function addCustomField() {
  form.fields.push({
    name: `field_${form.fields.length + 1}`,
    label: "Custom Field",
    type: "string",
    required: false,
    placeholder: "",
    optionsStr: "",
  });
}

function moveField(idx: number, delta: -1 | 1) {
  const next = idx + delta;
  if (next < 0 || next >= form.fields.length) return;
  const copy = [...form.fields];
  const [item] = copy.splice(idx, 1);
  copy.splice(next, 0, item);
  form.fields = copy;
}

async function load() {
  loading.value = true;
  try {
    const res = await apiGet<{
      default_channel: string;
      channels: typeof channels.value;
      sqlite_overrides: typeof overrides.value;
    }>("channels.list");
    defaultChannel.value = res.default_channel || "general";
    channels.value = res.channels || {};
    overrides.value = res.sqlite_overrides || [];
    syncStudioFromRoute();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load channels.", "destructive");
  } finally {
    loading.value = false;
  }
}

async function saveChannel() {
  saving.value = true;
  try {
    await apiPost("channels.save", {
      channel_key: form.channel_key,
      label: form.label,
      description: form.description,
      sla_window: form.sla_window,
      is_active: form.is_active,
      sort_order: Number(form.sort_order) || 100,
      config: {
        code: form.code,
        subject_placeholder: form.subject_placeholder,
        message_label: form.message_label,
        message_placeholder: form.message_placeholder,
        button_text: form.button_text,
        fields: form.fields.map((f) => ({
          name: f.name,
          label: f.label,
          type: f.type,
          required: f.required,
          placeholder: f.placeholder,
          options:
            f.type === "enum"
              ? f.optionsStr.split(",").map((s) => s.trim()).filter(Boolean)
              : undefined,
        })),
      },
    });
    notifyToast("Channel configuration & custom fields saved.", "success");
    const savedKey = form.channel_key;
    await load();
    if (isCreateMode.value && savedKey) {
      navigateToSubRoute("channels", "edit", savedKey);
    } else {
      syncStudioFromRoute(true);
    }
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to save channel.", "destructive");
  } finally {
    saving.value = false;
  }
}

function requestRemoveOverride(key: string) {
  deleteTargetKey.value = key;
  deleteConfirmOpen.value = true;
}

async function executeRemoveOverride() {
  if (!deleteTargetKey.value) return;
  saving.value = true;
  const target = deleteTargetKey.value;
  try {
    await apiPost("channels.delete", { channel_key: target });
    notifyToast("Channel override removed.", "success");
    deleteConfirmOpen.value = false;
    deleteTargetKey.value = "";
    await load();
    if (inStudio.value && form.channel_key === target) {
      if (channels.value[target]) {
        syncStudioFromRoute(true);
      } else {
        navigateTo("channels");
      }
    }
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Delete failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div>
  <!-- CHANNELS DIRECTORY VIEW -->
  <div v-if="!inStudio" class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Inquiry Channels &amp; Form Builder</h2>
        <p class="text-sm text-muted-foreground">
          Configure routing channels, form copy, and custom inquiry fields layered over <code class="font-mono text-xs">content/channels.php</code> (default: {{ defaultChannel }}).
        </p>
      </div>
      <Button v-if="isOwner" size="sm" @click="openCreateChannel">
        <Plus class="h-4 w-4" aria-hidden="true" />
        <span>New Form Channel</span>
      </Button>
    </div>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      <Card v-for="(ch, key) in channels" :key="key" class="p-5 flex flex-col justify-between gap-4">
        <div class="space-y-2">
          <div class="flex items-start justify-between gap-2">
            <div>
              <span class="font-semibold text-sm">{{ ch.label || key }}</span>
              <div class="text-xs font-mono text-muted-foreground">/contact/?channel={{ key }}</div>
            </div>
            <div class="flex items-center gap-1">
              <Badge v-if="String(key) === defaultChannel" variant="secondary">Default</Badge>
              <Badge :variant="isOverridden(String(key)) ? 'default' : 'outline'">
                {{ isOverridden(String(key)) ? "SQLite" : "Config" }}
              </Badge>
            </div>
          </div>
          <p class="text-xs text-muted-foreground">{{ ch.heading || ch.description || "Standard contact routing channel." }}</p>
          <div class="flex flex-wrap gap-3 text-[11px] font-mono text-muted-foreground pt-1">
            <span>SLA: {{ ch.sla || "1-2 Business Days" }}</span>
            <span>·</span>
            <span>{{ Array.isArray(ch.fields) ? ch.fields.length : 0 }} custom field(s)</span>
          </div>
        </div>
        <div v-if="isOwner" class="flex justify-end gap-1.5 pt-3 border-t border-border">
          <Button variant="outline" size="sm" @click="openEditChannel(String(key))">
            <Pencil class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Edit Schema</span>
          </Button>
        </div>
      </Card>
    </div>

    <Card v-if="overrides.length > 0" class="p-5 space-y-3">
      <div class="flex items-center justify-between border-b border-border pb-2.5">
        <h3 class="text-sm font-semibold">SQLite Channel Overrides ({{ overrides.length }})</h3>
      </div>
      <div class="divide-y divide-border text-xs">
        <div v-for="o in overrides" :key="String(o.channel_key)" class="py-2.5 flex flex-wrap items-center justify-between gap-2">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono font-semibold">{{ o.channel_key }}</span>
            <span class="text-muted-foreground">— {{ o.label }} ({{ o.sla_window }})</span>
            <Badge :variant="o.is_active ? 'success' : 'destructive'">
              {{ o.is_active ? "Active" : "Disabled" }}
            </Badge>
          </div>
          <div v-if="isOwner" class="flex items-center gap-1">
            <Button variant="ghost" size="sm" @click="openEditChannel(String(o.channel_key))">
              <Pencil class="h-3.5 w-3.5" aria-hidden="true" />
              <span>Edit</span>
            </Button>
            <Button variant="ghost" size="sm" :aria-label="`Delete channel override ${o.channel_key}`" @click="requestRemoveOverride(String(o.channel_key))">
              <Trash2 class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
            </Button>
          </div>
        </div>
      </div>
    </Card>
  </div>

  <!-- FULL-PAGE CHANNEL & SCHEMA STUDIO -->
  <div v-else class="space-y-6">
    <!-- Sticky Top Action Bar -->
    <div class="sticky -top-4 sm:-top-6 lg:-top-8 z-20 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-3 bg-background/95 backdrop-blur border-b border-border flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-3 min-w-0">
        <Button variant="outline" size="sm" @click="navigateTo('channels')">
          <ArrowLeft class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Back to Channels</span>
        </Button>
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h2 class="text-base sm:text-lg font-semibold tracking-tight truncate">
              {{ isCreateMode ? "New Form Channel" : form.label || form.channel_key }}
            </h2>
            <Badge v-if="form.channel_key" variant="outline" class="font-mono text-[11px]">{{ form.channel_key }}</Badge>
            <Badge :variant="form.is_active ? 'success' : 'destructive'">
              {{ form.is_active ? "Active" : "Disabled" }}
            </Badge>
          </div>
          <div class="text-xs font-mono text-muted-foreground truncate">
            SLA: {{ form.sla_window }} · {{ form.fields.length }} custom field(s)
          </div>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Button
          v-if="currentOverrideRecord"
          type="button"
          variant="outline"
          size="sm"
          @click="requestRemoveOverride(form.channel_key)"
        >
          <RotateCcw class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
          <span>Remove Override</span>
        </Button>
        <Button type="submit" form="channel-studio-form" size="sm" :disabled="saving">
          <Save class="h-3.5 w-3.5" aria-hidden="true" />
          <span>{{ saving ? "Saving..." : "Save Channel" }}</span>
        </Button>
      </div>
    </div>

    <form id="channel-studio-form" class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start" @submit.prevent="saveChannel">
      <!-- Left Settings Column -->
      <div class="lg:col-span-5 space-y-5 min-w-0">
        <Card class="p-5 space-y-4">
          <div class="flex items-center justify-between border-b border-border pb-2.5">
            <h3 class="text-sm font-semibold">Channel Identity &amp; SLA</h3>
            <label class="flex items-center gap-2 text-xs">
              <SwitchControl v-model="form.is_active" />
              <span>Active</span>
            </label>
          </div>

          <div class="grid gap-3 sm:grid-cols-2">
            <div class="space-y-1">
              <label for="ch-key" class="text-xs font-medium">Channel Key</label>
              <Input
                id="ch-key"
                v-model="form.channel_key"
                pattern="^[a-z0-9_-]+$"
                required
                placeholder="sales"
                class="font-mono text-xs"
              />
            </div>
            <div class="space-y-1">
              <label for="ch-code" class="text-xs font-medium">Receipt Prefix Code</label>
              <Input id="ch-code" v-model="form.code" maxlength="6" placeholder="SALES" class="font-mono uppercase text-xs" />
            </div>
            <div class="space-y-1 sm:col-span-2">
              <label for="ch-label" class="text-xs font-medium">Display Label</label>
              <Input id="ch-label" v-model="form.label" required placeholder="Enterprise Sales" />
            </div>
            <div class="space-y-1">
              <label for="ch-sla" class="text-xs font-medium">SLA Window</label>
              <Input id="ch-sla" v-model="form.sla_window" required />
            </div>
            <div class="space-y-1">
              <label for="ch-order" class="text-xs font-medium">Sort Order</label>
              <Input id="ch-order" v-model="form.sort_order" type="number" min="0" />
            </div>
          </div>
        </Card>

        <Card class="p-5 space-y-4">
          <h3 class="text-sm font-semibold border-b border-border pb-2.5">Public Form Copy &amp; Placeholders</h3>
          <div class="space-y-3">
            <div class="space-y-1">
              <label for="ch-desc" class="text-xs font-medium">Form Heading / Description</label>
              <Input id="ch-desc" v-model="form.description" placeholder="Speak with our enterprise deployment architects" />
            </div>
            <div class="space-y-1">
              <label for="ch-subj-ph" class="text-xs font-medium">Subject Placeholder</label>
              <Input id="ch-subj-ph" v-model="form.subject_placeholder" placeholder="Deployment scope or RFP" />
            </div>
            <div class="space-y-1">
              <label for="ch-msg-lbl" class="text-xs font-medium">Message Label</label>
              <Input id="ch-msg-lbl" v-model="form.message_label" placeholder="Project Requirements" />
            </div>
            <div class="space-y-1">
              <label for="ch-msg-ph" class="text-xs font-medium">Message Placeholder</label>
              <Input id="ch-msg-ph" v-model="form.message_placeholder" placeholder="Outline your timeline and goals..." />
            </div>
            <div class="space-y-1">
              <label for="ch-btn" class="text-xs font-medium">Submit Button Label</label>
              <Input id="ch-btn" v-model="form.button_text" placeholder="Request Consultation" />
            </div>
          </div>
        </Card>
      </div>

      <!-- Right Custom Schema Builder + Live Preview Column -->
      <div class="lg:col-span-7 space-y-5 min-w-0">
        <Card class="p-5 space-y-4">
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
            <div>
              <h3 class="text-sm font-semibold">Visual Custom Field Schema ({{ form.fields.length }}/20)</h3>
              <p class="text-xs text-muted-foreground">Additional structured inputs captured into SQLite JSON per submission.</p>
            </div>
            <Button type="button" variant="outline" size="sm" :disabled="form.fields.length >= 20" @click="addCustomField">
              <Plus class="h-3.5 w-3.5" aria-hidden="true" />
              <span>Add Custom Field</span>
            </Button>
          </div>

          <div v-if="form.fields.length === 0" class="rounded-md border border-dashed border-border p-6 text-center text-xs text-muted-foreground">
            No custom fields added. Only standard contact fields (Name, Email, Subject, Message) will render on this channel.
          </div>

          <div v-else class="space-y-3">
            <div v-for="(f, idx) in form.fields" :key="idx" class="rounded-lg border border-border p-4 space-y-3 bg-muted/20">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                  <Badge variant="outline">Field {{ idx + 1 }}</Badge>
                  <span class="font-mono text-xs font-medium">{{ f.name || "unnamed_field" }}</span>
                </div>
                <div class="flex items-center gap-1">
                  <label class="flex items-center gap-1.5 text-xs mr-2 cursor-pointer">
                    <input v-model="f.required" type="checkbox" class="rounded border-input" />
                    <span>Required</span>
                  </label>
                  <Button type="button" variant="ghost" size="icon" class="h-7 w-7" :disabled="idx === 0" aria-label="Move field up" @click="moveField(idx, -1)">
                    <ArrowUp class="h-3.5 w-3.5" aria-hidden="true" />
                  </Button>
                  <Button type="button" variant="ghost" size="icon" class="h-7 w-7" :disabled="idx === form.fields.length - 1" aria-label="Move field down" @click="moveField(idx, 1)">
                    <ArrowDown class="h-3.5 w-3.5" aria-hidden="true" />
                  </Button>
                  <Button type="button" variant="ghost" size="icon" class="h-7 w-7" aria-label="Remove custom field" @click="form.fields.splice(idx, 1)">
                    <Trash2 class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
                  </Button>
                </div>
              </div>

              <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="space-y-1">
                  <label :for="`cf-name-${idx}`" class="text-[11px] font-medium">Field Key</label>
                  <Input :id="`cf-name-${idx}`" v-model="f.name" pattern="^[a-z0-9_]+$" required class="font-mono text-xs" placeholder="team_size" />
                </div>
                <div class="space-y-1">
                  <label :for="`cf-label-${idx}`" class="text-[11px] font-medium">Visible Label</label>
                  <Input :id="`cf-label-${idx}`" v-model="f.label" required placeholder="Team Size" />
                </div>
                <div class="space-y-1">
                  <label :for="`cf-type-${idx}`" class="text-[11px] font-medium">Input Type</label>
                  <select :id="`cf-type-${idx}`" v-model="f.type" class="h-9 w-full rounded-md border border-input bg-background px-2 text-xs">
                    <option value="string">Text Input</option>
                    <option value="enum">Select Dropdown</option>
                  </select>
                </div>
                <div class="space-y-1">
                  <label :for="`cf-extra-${idx}`" class="text-[11px] font-medium">
                    {{ f.type === "enum" ? "Options (comma-sep)" : "Placeholder" }}
                  </label>
                  <Input
                    v-if="f.type === 'enum'"
                    :id="`cf-extra-${idx}`"
                    v-model="f.optionsStr"
                    placeholder="1-10, 11-50, 50+"
                  />
                  <Input
                    v-else
                    :id="`cf-extra-${idx}`"
                    v-model="f.placeholder"
                    placeholder="Enter value..."
                  />
                </div>
              </div>
            </div>
          </div>
        </Card>

        <!-- Live Form Preview Card -->
        <Card class="p-5 space-y-4">
          <div class="flex items-center justify-between border-b border-border pb-2.5">
            <div class="flex items-center gap-2">
              <Eye class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
              <h3 class="text-sm font-semibold">Live Channel Form Preview</h3>
            </div>
            <Badge variant="outline">{{ form.sla_window || "1-2 Business Days" }}</Badge>
          </div>

          <div class="rounded-lg border border-border bg-muted/20 p-4 space-y-4">
            <div>
              <div class="text-sm font-semibold">{{ form.label || "Channel Label" }}</div>
              <p class="text-xs text-muted-foreground mt-0.5">{{ form.description || "Describe what this inquiry channel handles." }}</p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 text-xs opacity-75">
              <div class="space-y-1">
                <div class="font-medium">Full Name *</div>
                <div class="h-8 rounded border border-input bg-background px-2.5 flex items-center text-muted-foreground">Jane Doe</div>
              </div>
              <div class="space-y-1">
                <div class="font-medium">Work Email *</div>
                <div class="h-8 rounded border border-input bg-background px-2.5 flex items-center text-muted-foreground">jane@company.com</div>
              </div>
            </div>

            <div v-if="form.fields.length > 0" class="grid gap-3 sm:grid-cols-2 text-xs">
              <div v-for="(f, idx) in form.fields" :key="`prev-${idx}`" class="space-y-1">
                <div class="font-medium">
                  {{ f.label || f.name }} <span v-if="f.required" class="text-destructive">*</span>
                </div>
                <select v-if="f.type === 'enum'" class="h-8 w-full rounded border border-input bg-background px-2 text-xs" disabled>
                  <option v-for="opt in f.optionsStr.split(',').map(s => s.trim()).filter(Boolean)" :key="opt">{{ opt }}</option>
                  <option v-if="!f.optionsStr.trim()">Select an option...</option>
                </select>
                <div v-else class="h-8 rounded border border-input bg-background px-2.5 flex items-center text-muted-foreground truncate">
                  {{ f.placeholder || "Input value..." }}
                </div>
              </div>
            </div>

            <div class="space-y-3 text-xs">
              <div class="space-y-1">
                <div class="font-medium">Subject *</div>
                <div class="h-8 rounded border border-input bg-background px-2.5 flex items-center text-muted-foreground">
                  {{ form.subject_placeholder || "How can we help?" }}
                </div>
              </div>
              <div class="space-y-1">
                <div class="font-medium">{{ (form.message_label || "Message").replace(/\s*\*+$/, "") }} <span class="text-destructive">*</span></div>
                <div class="min-h-16 rounded border border-input bg-background p-2.5 text-muted-foreground">
                  {{ form.message_placeholder || "Write your message..." }}
                </div>
              </div>
            </div>

            <div class="flex justify-end">
              <span class="inline-flex h-8 items-center rounded-md bg-primary px-4 text-xs font-medium text-primary-foreground">
                {{ form.button_text || "Send Inquiry" }}
              </span>
            </div>
          </div>
        </Card>
      </div>
    </form>
  </div>

  <ConfirmDialog
    v-model:open="deleteConfirmOpen"
    title="Remove Channel Override?"
    :description="`Remove SQLite override for channel '${deleteTargetKey}' and revert to content/channels.php defaults?`"
    confirm-label="Remove Override"
    variant="destructive"
    :loading="saving"
    @confirm="executeRemoveOverride"
  />
  </div>
</template>
