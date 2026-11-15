<script setup lang="ts">
import { computed, onMounted, reactive, ref } from "vue";
import { Mail, MessageSquare, RefreshCw, Save, Send, Webhook, Zap } from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import type { AuthStatusResponse } from "../lib/types";
import { formatDateTime } from "../lib/utils";
import { isOwner, navigateTo, notifyToast, setAdminBadges } from "../stores/admin";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import Input from "../components/ui/Input.vue";
import SwitchControl from "../components/ui/SwitchControl.vue";

interface OutboxItem {
  receipt_id: string;
  channel_label: string;
  subject: string;
  sync_status: string;
  email_status: string;
  whatsapp_status: string;
  created_at: string;
}

const loading = ref(false);
const saving = ref(false);
const pendingOutbox = ref<OutboxItem[]>([]);
const testEmailTo = ref("");
const testWaPhone = ref("");

const form = reactive({
  hub: {
    enabled: false,
    endpoint: "",
    timeout_ms: 1500,
    fallback_notify: true,
    has_secret: false,
    shared_secret: "",
    clear_secret: false,
  },
  email: {
    enabled: false,
    transport: "smtp",
    to: "",
    from_email: "",
    from_name: "",
    smtp_host: "",
    smtp_port: 587,
    smtp_user: "",
    smtp_tls: true,
    has_smtp_pass: false,
    smtp_pass: "",
    clear_smtp_pass: false,
  },
  whatsapp: {
    enabled: false,
    provider: "waha",
    phone: "",
    webhook_url: "",
    instance: "default",
    has_api_key: false,
    api_key: "",
    clear_api_key: false,
  },
});

const activeTransportCount = computed(
  () => Number(form.hub.enabled) + Number(form.email.enabled) + Number(form.whatsapp.enabled)
);

async function load() {
  loading.value = true;
  try {
    const res = await apiGet<{
      hub: typeof form.hub;
      email: typeof form.email;
      whatsapp: typeof form.whatsapp;
      pending_outbox: OutboxItem[];
    }>("dispatch.status");
    Object.assign(form.hub, res.hub, { shared_secret: "", clear_secret: false });
    Object.assign(form.email, res.email, { smtp_pass: "", clear_smtp_pass: false });
    Object.assign(form.whatsapp, res.whatsapp, { api_key: "", clear_api_key: false });
    pendingOutbox.value = res.pending_outbox || [];
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load dispatch status.", "destructive");
  } finally {
    loading.value = false;
  }
}

async function saveSettings() {
  saving.value = true;
  try {
    await apiPost("dispatch.save", {
      hub: form.hub,
      email: form.email,
      whatsapp: form.whatsapp,
    });
    notifyToast("Encrypted dispatch settings saved.", "success");
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Could not save settings.", "destructive");
  } finally {
    saving.value = false;
  }
}

async function flushOutbox() {
  saving.value = true;
  try {
    const res = await apiPost<{ attempted: number; synced: number; failed: number }>("dispatch.flush", {});
    notifyToast(`Outbox flushed: ${res.synced}/${res.attempted} synced, ${res.failed} failed.`);
    await load();
    try {
      const auth = await apiGet<AuthStatusResponse>("auth.status");
      if (auth.badges) setAdminBadges(auth.badges);
    } catch {}
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Flush failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

async function runTest(type: "email" | "whatsapp" | "hub") {
  saving.value = true;
  try {
    const res = await apiPost<{ ok: boolean; status: string }>("dispatch.test", {
      type,
      recipient: type === "email" ? testEmailTo.value : type === "whatsapp" ? testWaPhone.value : "",
    });
    notifyToast(`Test ${type}: ${res.status}`, res.ok ? "success" : "destructive");
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Test dispatch error.", "destructive");
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="space-y-6 pb-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Hub &amp; Edge Dispatch</h2>
        <p class="text-sm text-muted-foreground">
          Secrets are encrypted via AES-256-GCM before storage in SQLite <code class="font-mono text-xs">admin_settings</code>.
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <Badge :variant="activeTransportCount > 0 ? 'success' : 'outline'">
          {{ activeTransportCount }}/3 Transports Active
        </Badge>
        <Button v-if="isOwner" variant="outline" size="sm" :disabled="saving || pendingOutbox.length === 0" @click="flushOutbox">
          <Zap class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Flush Outbox ({{ pendingOutbox.length }})</span>
        </Button>
        <Button variant="outline" size="sm" :disabled="loading" @click="load">
          <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Refresh</span>
        </Button>
      </div>
    </div>

    <Card v-if="pendingOutbox.length > 0" class="overflow-hidden border-amber-500/40">
      <div class="px-5 py-3.5 border-b border-border bg-amber-500/5 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
          <Zap class="h-4 w-4 text-amber-600 dark:text-amber-400" aria-hidden="true" />
          <h3 class="text-sm font-semibold">Pending Outbox Retry Queue</h3>
          <Badge variant="warning">{{ pendingOutbox.length }} queued</Badge>
        </div>
        <Button v-if="isOwner" variant="outline" size="sm" :disabled="saving" @click="flushOutbox">
          Retry Delivery Now
        </Button>
      </div>
      <div tabindex="0" role="region" aria-labelledby="outbox-cap" class="lumora-table-wrap max-h-60 overflow-y-auto overscroll-contain lumora-scroll-area border-0 rounded-none">
        <table class="w-full text-xs text-left">
          <caption id="outbox-cap" class="sr-only">Pending outbox delivery queue</caption>
          <thead class="sticky top-0 z-10 border-b border-border bg-muted/95 backdrop-blur uppercase text-muted-foreground">
            <tr>
              <th scope="col" class="px-5 py-2">Receipt</th>
              <th scope="col" class="px-3 py-2">Channel</th>
              <th scope="col" class="px-3 py-2">Hub Sync</th>
              <th scope="col" class="px-3 py-2">Email</th>
              <th scope="col" class="px-3 py-2">WhatsApp</th>
              <th scope="col" class="px-5 py-2 text-right">Created</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-for="item in pendingOutbox" :key="item.receipt_id" class="hover:bg-muted/20">
              <td class="px-5 py-2 font-mono font-medium">
                <button type="button" class="underline cursor-pointer" @click="navigateTo('inquiries')">{{ item.receipt_id }}</button>
              </td>
              <td class="px-3 py-2">{{ item.channel_label }}</td>
              <td class="px-3 py-2"><Badge variant="outline">{{ item.sync_status }}</Badge></td>
              <td class="px-3 py-2"><Badge variant="outline">{{ item.email_status }}</Badge></td>
              <td class="px-3 py-2"><Badge variant="outline">{{ item.whatsapp_status }}</Badge></td>
              <td class="px-5 py-2 text-right text-muted-foreground">{{ formatDateTime(item.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>

    <form id="dispatch-config-form" class="space-y-5" @submit.prevent="saveSettings">
      <Card class="overflow-hidden">
        <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-start gap-2.5">
            <Webhook class="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" aria-hidden="true" />
            <div>
              <h3 class="text-sm font-semibold">Central Communication Hub (HMAC-SHA256)</h3>
              <p class="text-xs text-muted-foreground">Signed webhook sync with idempotent delivery receipts and timeout fallback.</p>
            </div>
          </div>
          <div class="flex items-center gap-2.5">
            <Badge :variant="form.hub.enabled ? 'success' : 'outline'">
              {{ form.hub.enabled ? "Active" : "Disabled" }}
            </Badge>
            <SwitchControl id="hub-enabled" v-model="form.hub.enabled" :disabled="!isOwner" aria-label="Enable Central Communication Hub" />
          </div>
        </div>
        <div class="p-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="space-y-1 sm:col-span-2">
            <label for="hub-endpoint" class="text-xs font-medium">Hub Endpoint URL</label>
            <Input id="hub-endpoint" v-model="form.hub.endpoint" type="url" :disabled="!isOwner" placeholder="https://hub.example.com/ingest" />
          </div>
          <div class="space-y-1">
            <label for="hub-secret" class="text-xs font-medium flex items-center gap-1.5">
              <span>Shared Secret</span>
              <Badge variant="outline">{{ form.hub.has_secret ? "Configured" : "Not Set" }}</Badge>
            </label>
            <Input
              id="hub-secret"
              v-model="form.hub.shared_secret"
              type="password"
              autocomplete="new-password"
              :disabled="!isOwner"
              placeholder="Leave blank to keep existing"
            />
          </div>
          <div class="space-y-1">
            <label for="hub-timeout" class="text-xs font-medium">Timeout (ms, 500–3000)</label>
            <Input id="hub-timeout" v-model="form.hub.timeout_ms" type="number" min="500" max="3000" :disabled="!isOwner" />
          </div>
          <div class="sm:col-span-2 flex flex-wrap items-center gap-4 pt-1">
            <label for="hub-fallback" class="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer">
              <SwitchControl id="hub-fallback" v-model="form.hub.fallback_notify" :disabled="!isOwner" />
              <span>Fallback to Email / WhatsApp when Hub is unreachable</span>
            </label>
            <label v-if="isOwner && form.hub.has_secret" class="flex items-center gap-1.5 text-xs text-destructive cursor-pointer">
              <input v-model="form.hub.clear_secret" type="checkbox" class="rounded border-input" />
              <span>Clear stored secret</span>
            </label>
          </div>
        </div>
        <div v-if="isOwner" class="px-5 py-3 border-t border-border bg-muted/30 flex flex-wrap items-center justify-between gap-2">
          <span class="text-xs text-muted-foreground">Sends an HMAC-signed <code class="font-mono">test_ping</code> payload to verify connectivity.</span>
          <Button variant="outline" size="sm" :disabled="saving" @click="runTest('hub')">
            <Send class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Test Hub Ping</span>
          </Button>
        </div>
      </Card>

      <Card class="overflow-hidden">
        <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-start gap-2.5">
            <Mail class="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" aria-hidden="true" />
            <div>
              <h3 class="text-sm font-semibold">Edge SMTP Email Transport</h3>
              <p class="text-xs text-muted-foreground">Zero-dependency PHP socket SMTP client with TLS support.</p>
            </div>
          </div>
          <div class="flex items-center gap-2.5">
            <Badge :variant="form.email.enabled ? 'success' : 'outline'">
              {{ form.email.enabled ? "Active" : "Disabled" }}
            </Badge>
            <SwitchControl id="email-enabled" v-model="form.email.enabled" :disabled="!isOwner" aria-label="Enable Edge SMTP Email Transport" />
          </div>
        </div>
        <div class="p-5 grid gap-4 sm:grid-cols-3">
          <div class="space-y-1">
            <label for="smtp-host" class="text-xs font-medium">SMTP Host</label>
            <Input id="smtp-host" v-model="form.email.smtp_host" :disabled="!isOwner" placeholder="smtp.example.com" />
          </div>
          <div class="space-y-1">
            <label for="smtp-port" class="text-xs font-medium">Port</label>
            <Input id="smtp-port" v-model="form.email.smtp_port" type="number" :disabled="!isOwner" />
          </div>
          <div class="space-y-1">
            <label for="email-to" class="text-xs font-medium">Recipient Email</label>
            <Input id="email-to" v-model="form.email.to" type="email" :disabled="!isOwner" />
          </div>
          <div class="space-y-1">
            <label for="email-from" class="text-xs font-medium">From Email</label>
            <Input id="email-from" v-model="form.email.from_email" type="email" :disabled="!isOwner" />
          </div>
          <div class="space-y-1">
            <label for="email-from-name" class="text-xs font-medium">From Name</label>
            <Input id="email-from-name" v-model="form.email.from_name" :disabled="!isOwner" placeholder="Lumora Notifications" />
          </div>
          <div class="space-y-1">
            <label for="smtp-user" class="text-xs font-medium">SMTP Username</label>
            <Input id="smtp-user" v-model="form.email.smtp_user" :disabled="!isOwner" />
          </div>
          <div class="space-y-1 sm:col-span-2">
            <label for="smtp-pass" class="text-xs font-medium flex items-center gap-1.5">
              <span>SMTP Password</span>
              <Badge variant="outline">{{ form.email.has_smtp_pass ? "Saved" : "None" }}</Badge>
            </label>
            <Input
              id="smtp-pass"
              v-model="form.email.smtp_pass"
              type="password"
              autocomplete="new-password"
              :disabled="!isOwner"
              placeholder="Leave blank to keep existing"
            />
          </div>
          <div class="flex flex-wrap items-center gap-4 pt-4">
            <label for="smtp-tls" class="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer">
              <SwitchControl id="smtp-tls" v-model="form.email.smtp_tls" :disabled="!isOwner" />
              <span>STARTTLS / TLS</span>
            </label>
            <label v-if="isOwner && form.email.has_smtp_pass" class="flex items-center gap-1.5 text-xs text-destructive cursor-pointer">
              <input v-model="form.email.clear_smtp_pass" type="checkbox" class="rounded border-input" />
              <span>Clear password</span>
            </label>
          </div>
        </div>
        <div v-if="isOwner" class="px-5 py-3 border-t border-border bg-muted/30 flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2 flex-1 min-w-52 max-w-sm">
            <label for="test-email-to" class="sr-only">Test email recipient override</label>
            <Input id="test-email-to" v-model="testEmailTo" type="email" class="h-8 text-xs" placeholder="Test recipient override (optional)" />
          </div>
          <Button variant="outline" size="sm" :disabled="saving" @click="runTest('email')">
            <Send class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Send Test Email</span>
          </Button>
        </div>
      </Card>

      <Card class="overflow-hidden">
        <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-start gap-2.5">
            <MessageSquare class="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" aria-hidden="true" />
            <div>
              <h3 class="text-sm font-semibold">Self-Hosted WhatsApp Gateway</h3>
              <p class="text-xs text-muted-foreground">Supports WAHA, Evolution API, or custom signed HTTPS webhook.</p>
            </div>
          </div>
          <div class="flex items-center gap-2.5">
            <Badge :variant="form.whatsapp.enabled ? 'success' : 'outline'">
              {{ form.whatsapp.enabled ? "Active" : "Disabled" }}
            </Badge>
            <SwitchControl id="wa-enabled" v-model="form.whatsapp.enabled" :disabled="!isOwner" aria-label="Enable WhatsApp Gateway" />
          </div>
        </div>
        <div class="p-5 grid gap-4 sm:grid-cols-3">
          <div class="space-y-1">
            <label for="wa-prov" class="text-xs font-medium">Provider</label>
            <select id="wa-prov" v-model="form.whatsapp.provider" :disabled="!isOwner" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
              <option value="waha">WAHA</option>
              <option value="evolution">Evolution API</option>
              <option value="webhook">Generic Webhook</option>
            </select>
          </div>
          <div class="space-y-1">
            <label for="wa-url" class="text-xs font-medium">Gateway URL</label>
            <Input id="wa-url" v-model="form.whatsapp.webhook_url" type="url" :disabled="!isOwner" placeholder="https://waha.internal" />
          </div>
          <div class="space-y-1">
            <label for="wa-phone" class="text-xs font-medium">Target Phone</label>
            <Input id="wa-phone" v-model="form.whatsapp.phone" type="tel" :disabled="!isOwner" />
          </div>
          <div class="space-y-1">
            <label for="wa-inst" class="text-xs font-medium">Instance Name</label>
            <Input id="wa-inst" v-model="form.whatsapp.instance" :disabled="!isOwner" />
          </div>
          <div class="space-y-1 sm:col-span-2">
            <label for="wa-key" class="text-xs font-medium flex items-center gap-1.5">
              <span>API Key</span>
              <Badge variant="outline">{{ form.whatsapp.has_api_key ? "Saved" : "None" }}</Badge>
            </label>
            <Input
              id="wa-key"
              v-model="form.whatsapp.api_key"
              type="password"
              autocomplete="new-password"
              :disabled="!isOwner"
              placeholder="Leave blank to keep existing"
            />
            <label v-if="isOwner && form.whatsapp.has_api_key" class="inline-flex items-center gap-1.5 text-xs text-destructive pt-1 cursor-pointer">
              <input v-model="form.whatsapp.clear_api_key" type="checkbox" class="rounded border-input" />
              <span>Clear stored API key</span>
            </label>
          </div>
        </div>
        <div v-if="isOwner" class="px-5 py-3 border-t border-border bg-muted/30 flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2 flex-1 min-w-52 max-w-sm">
            <label for="test-wa-phone" class="sr-only">Test WhatsApp phone override</label>
            <Input id="test-wa-phone" v-model="testWaPhone" type="tel" class="h-8 text-xs" placeholder="Test phone override (optional)" />
          </div>
          <Button variant="outline" size="sm" :disabled="saving" @click="runTest('whatsapp')">
            <Send class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Send Test WhatsApp</span>
          </Button>
        </div>
      </Card>
    </form>

    <div
      v-if="isOwner"
      class="sticky -bottom-4 sm:-bottom-6 lg:-bottom-8 z-20 rounded-lg border border-border bg-card/95 backdrop-blur px-5 py-3 shadow-lg flex flex-wrap items-center justify-between gap-3"
    >
      <span class="text-xs text-muted-foreground">Credentials are sealed via AES-256-GCM before persisting to SQLite.</span>
      <Button type="submit" form="dispatch-config-form" :disabled="saving">
        <Save class="h-4 w-4" aria-hidden="true" />
        <span>{{ saving ? "Saving..." : "Save Dispatch Configuration" }}</span>
      </Button>
    </div>
  </div>
</template>
