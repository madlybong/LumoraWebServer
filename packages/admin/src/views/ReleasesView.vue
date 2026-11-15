<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import { FileCheck, Pencil, Plus, Trash2 } from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import { isOwner, notifyToast } from "../stores/admin";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import CodeMirrorEditor from "../components/ui/CodeMirrorEditor.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";

const manifest = ref<{ product?: string; version?: string; artifacts?: Array<Record<string, unknown>> }>({});
const sqliteReleases = ref<Array<Record<string, unknown>>>([]);
const saving = ref(false);
const hashing = ref(false);
const dragActive = ref(false);

const deleteConfirmOpen = ref(false);
const deleteTargetId = ref<number | null>(null);
const deleteTargetName = ref("");

const form = reactive({
  version: "0.1.0",
  platform: "linux",
  filename: "",
  sha256: "",
  size_bytes: 0,
  channel: "stable",
  release_notes: "",
});

function resetForm() {
  Object.assign(form, {
    version: "0.1.0",
    platform: "linux",
    filename: "",
    sha256: "",
    size_bytes: 0,
    channel: "stable",
    release_notes: "",
  });
}

async function load() {
  try {
    const res = await apiGet<{
      manifest: typeof manifest.value;
      sqlite_releases: typeof sqliteReleases.value;
    }>("releases.list");
    manifest.value = res.manifest || {};
    sqliteReleases.value = res.sqlite_releases || [];
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load releases.", "destructive");
  }
}

async function computeFileDigest(files: FileList | null) {
  const file = files?.[0];
  if (!file) return;
  if (!window.crypto?.subtle) {
    notifyToast("WebCrypto SHA-256 requires a secure HTTPS or localhost origin.", "destructive");
    return;
  }
  hashing.value = true;
  try {
    const buffer = await file.arrayBuffer();
    const digest = await window.crypto.subtle.digest("SHA-256", buffer);
    const hex = Array.from(new Uint8Array(digest))
      .map((b) => b.toString(16).padStart(2, "0"))
      .join("");
    form.filename = file.name.replace(/[^a-zA-Z0-9._-]/g, "-");
    form.size_bytes = file.size;
    form.sha256 = hex;
    notifyToast(`Computed SHA-256 locally for ${file.name}.`, "success");
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "SHA-256 calculation failed.", "destructive");
  } finally {
    hashing.value = false;
  }
}

function onDrop(e: DragEvent) {
  e.preventDefault();
  dragActive.value = false;
  void computeFileDigest(e.dataTransfer?.files || null);
}

function editRow(row: Record<string, unknown>) {
  Object.assign(form, {
    version: String(row.version || "0.1.0"),
    platform: String(row.platform || "archive"),
    filename: String(row.filename || ""),
    sha256: String(row.sha256 || ""),
    size_bytes: Number(row.size_bytes || 0),
    channel: String(row.channel || "stable"),
    release_notes: String(row.release_notes || ""),
  });
  document.querySelector("main")?.scrollTo({ top: 0, behavior: "smooth" });
}

async function saveRelease() {
  saving.value = true;
  try {
    await apiPost("releases.save", form);
    form.filename = "";
    form.sha256 = "";
    form.size_bytes = 0;
    form.release_notes = "";
    notifyToast("Release entry recorded in manifest & SHA256SUMS.", "success");
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Save failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

function requestRemove(row: Record<string, unknown>) {
  deleteTargetId.value = Number(row.id);
  deleteTargetName.value = String(row.filename || row.id);
  deleteConfirmOpen.value = true;
}

async function executeRemove() {
  if (deleteTargetId.value === null) return;
  saving.value = true;
  try {
    await apiPost("releases.delete", { id: deleteTargetId.value });
    notifyToast("Release entry removed.", "success");
    deleteConfirmOpen.value = false;
    deleteTargetId.value = null;
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Delete failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="space-y-6">
    <div>
      <h2 class="text-xl font-semibold tracking-tight">Releases &amp; Checksums</h2>
      <p class="text-sm text-muted-foreground">
        Manage artifacts published to <code class="font-mono text-xs">/releases/release-manifest.json</code> and <code class="font-mono text-xs">/releases/SHA256SUMS</code>.
      </p>
    </div>

    <div class="grid gap-6 xl:grid-cols-12 items-start">
      <Card v-if="isOwner" class="xl:col-span-6 overflow-hidden">
        <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
          <div>
            <h3 class="text-sm font-semibold">Register / Update Release Artifact</h3>
            <p class="text-xs text-muted-foreground">Drop a local build below to auto-compute SHA-256 without uploading binaries.</p>
          </div>
          <Button v-if="form.filename || form.sha256" variant="ghost" size="sm" @click="resetForm">Reset</Button>
        </div>

        <form id="release-register-form" class="p-5 space-y-4" @submit.prevent="saveRelease">
          <div
            :class="[
              'rounded-md border border-dashed p-3 text-xs flex flex-wrap items-center justify-between gap-2 transition-colors',
              dragActive ? 'border-primary bg-accent/60' : 'border-border bg-muted/20',
            ]"
            @dragover.prevent="dragActive = true"
            @dragleave.prevent="dragActive = false"
            @drop="onDrop"
          >
            <div class="flex items-center gap-2 text-muted-foreground">
              <FileCheck class="h-4 w-4 shrink-0" aria-hidden="true" />
              <span>Local WebCrypto Checksum Dropzone (computes SHA-256 in memory)</span>
            </div>
            <label class="inline-flex items-center gap-1.5 rounded-md border border-input bg-background px-3 py-1.5 font-medium hover:bg-accent cursor-pointer">
              <input
                type="file"
                class="sr-only"
                :disabled="hashing"
                @change="computeFileDigest(($event.target as HTMLInputElement).files); (($event.target as HTMLInputElement).value = '')"
              />
              <span>{{ hashing ? "Hashing..." : "Select File" }}</span>
            </label>
          </div>

          <div class="grid gap-3 sm:grid-cols-3">
            <div class="space-y-1">
              <label for="rel-ver" class="text-xs font-medium">Version</label>
              <Input id="rel-ver" v-model="form.version" required />
            </div>
            <div class="space-y-1">
              <label for="rel-plat" class="text-xs font-medium">Platform</label>
              <select id="rel-plat" v-model="form.platform" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
                <option value="linux">linux</option>
                <option value="windows">windows</option>
                <option value="macos">macos</option>
                <option value="android">android</option>
                <option value="archive">archive</option>
              </select>
            </div>
            <div class="space-y-1">
              <label for="rel-chan" class="text-xs font-medium">Channel</label>
              <select id="rel-chan" v-model="form.channel" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
                <option value="stable">stable</option>
                <option value="beta">beta</option>
                <option value="rc">rc (release candidate)</option>
              </select>
            </div>
            <div class="space-y-1 sm:col-span-2">
              <label for="rel-file" class="text-xs font-medium">Artifact Filename</label>
              <Input id="rel-file" v-model="form.filename" required placeholder="app-0.1.0-linux-x64.tar.gz" class="font-mono text-xs" />
            </div>
            <div class="space-y-1">
              <label for="rel-size" class="text-xs font-medium">Size (Bytes)</label>
              <Input id="rel-size" v-model="form.size_bytes" type="number" min="0" />
            </div>
            <div class="space-y-1 sm:col-span-3">
              <label for="rel-sha" class="text-xs font-medium">SHA-256 Digest (64 hex chars)</label>
              <Input id="rel-sha" v-model="form.sha256" pattern="^[a-fA-F0-9]{64}$" required class="font-mono text-xs" />
            </div>
            <div class="space-y-1 sm:col-span-3">
              <label for="rel-notes" class="text-xs font-medium">Release Notes / Changelog Highlights</label>
              <CodeMirrorEditor
                id="rel-notes"
                v-model="form.release_notes"
                language="markdown"
                placeholder="Summary of changes, checksums verified..."
                min-height="120px"
                max-height="220px"
              />
            </div>
          </div>
        </form>

        <div class="px-5 py-3 border-t border-border bg-muted/30 flex justify-end">
          <Button type="submit" form="release-register-form" size="sm" :disabled="saving">
            <Plus class="h-3.5 w-3.5" aria-hidden="true" />
            <span>{{ saving ? "Saving..." : "Register Release" }}</span>
          </Button>
        </div>
      </Card>

      <div :class="[isOwner ? 'xl:col-span-6' : 'xl:col-span-12', 'space-y-5 min-w-0']">
        <Card class="overflow-hidden">
          <div class="px-5 py-4 border-b border-border flex items-center justify-between">
            <h3 class="text-sm font-semibold">Live Release Manifest</h3>
            <Badge variant="outline">{{ manifest.artifacts?.length || 0 }} artifact(s)</Badge>
          </div>
          <div class="p-5">
            <div v-if="!manifest.artifacts?.length" class="text-xs text-muted-foreground">No release artifacts published.</div>
            <div v-else class="divide-y divide-border text-xs">
              <div v-for="art in manifest.artifacts" :key="String(art.filename)" class="py-2.5 flex items-center justify-between gap-3">
                <div class="min-w-0">
                  <div class="font-mono font-medium truncate">{{ art.filename }}</div>
                  <div class="text-muted-foreground font-mono truncate">sha256: {{ art.sha256 }}</div>
                </div>
                <Badge variant="outline" class="shrink-0">{{ art.platform }} · v{{ art.version }}</Badge>
              </div>
            </div>
          </div>
        </Card>

        <Card v-if="sqliteReleases.length > 0 && isOwner" class="overflow-hidden">
          <div class="px-5 py-4 border-b border-border">
            <h3 class="text-sm font-semibold">SQLite Release Entries ({{ sqliteReleases.length }})</h3>
          </div>
          <div class="divide-y divide-border text-xs">
            <div v-for="row in sqliteReleases" :key="Number(row.id)" class="px-5 py-3 flex flex-wrap items-center justify-between gap-2">
              <div class="min-w-0">
                <span class="font-mono font-medium">{{ row.filename }}</span>
                <span class="text-muted-foreground ml-1.5">(v{{ row.version }} · {{ row.channel || "stable" }})</span>
                <div v-if="row.release_notes" class="text-[11px] text-muted-foreground mt-0.5 truncate max-w-md">{{ row.release_notes }}</div>
              </div>
              <div class="flex items-center gap-1 shrink-0">
                <Button variant="ghost" size="sm" :aria-label="`Edit ${row.filename}`" @click="editRow(row)">
                  <Pencil class="h-3.5 w-3.5" aria-hidden="true" />
                </Button>
                <Button variant="ghost" size="sm" :aria-label="`Delete ${row.filename}`" @click="requestRemove(row)">
                  <Trash2 class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
                </Button>
              </div>
            </div>
          </div>
        </Card>
      </div>
    </div>

    <ConfirmDialog
      v-model:open="deleteConfirmOpen"
      title="Delete Release Artifact Entry?"
      :description="`Remove ${deleteTargetName} from SQLite releases and live release-manifest.json?`"
      confirm-label="Delete Entry"
      variant="destructive"
      :loading="saving"
      @confirm="executeRemove"
    />
  </div>
</template>
