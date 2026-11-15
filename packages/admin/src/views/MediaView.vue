<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { Check, Copy, Image as ImageIcon, Search, Trash2, Upload } from "lucide-vue-next";
import { apiGet, apiPost, apiUpload } from "../lib/api";
import type { MediaItem } from "../lib/types";
import { copyText, formatDateTime } from "../lib/utils";
import { isEditor, notifyToast } from "../stores/admin";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";

const items = ref<MediaItem[]>([]);
const altText = ref("");
const fileInput = ref<HTMLInputElement | null>(null);
const selectedCount = ref(0);
const uploading = ref(false);
const dragActive = ref(false);
const searchQ = ref("");
const originFilter = ref<"all" | "upload" | "static">("all");

const altDrafts = ref<Record<number, string>>({});
const savingAltId = ref<number | null>(null);
const copiedId = ref<string | null>(null);
const copyLiveMessage = ref("");
let lastCopyAnnouncement = "";

const deleteConfirmOpen = ref(false);
const deleteTarget = ref<MediaItem | null>(null);
const deleting = ref(false);

const uploadCount = computed(() => items.value.filter((i) => (i.source_origin || "upload") === "upload").length);
const staticCount = computed(() => items.value.filter((i) => i.source_origin === "static").length);

const filtered = computed(() => {
  const q = searchQ.value.trim().toLowerCase();
  return items.value.filter((item) => {
    const orig = item.source_origin || "upload";
    if (originFilter.value !== "all" && orig !== originFilter.value) return false;
    if (!q) return true;
    return (
      item.filename.toLowerCase().includes(q) ||
      item.public_path.toLowerCase().includes(q) ||
      (item.alt_text || "").toLowerCase().includes(q)
    );
  });
});

async function load() {
  try {
    const res = await apiGet<{ items: MediaItem[] }>("media.list");
    items.value = res.items || [];
    const nextDrafts: Record<number, string> = {};
    for (const item of items.value) {
      if (item.id > 0) {
        nextDrafts[item.id] = item.alt_text || "";
      }
    }
    altDrafts.value = nextDrafts;
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load media library.", "destructive");
  }
}

function clearSelection() {
  if (fileInput.value) fileInput.value.value = "";
  selectedCount.value = 0;
  altText.value = "";
}

async function uploadFileList(files: FileList | null) {
  if (!files || files.length === 0) {
    notifyToast("Select at least one image file first.", "destructive");
    return;
  }
  uploading.value = true;
  try {
    for (const file of Array.from(files)) {
      const fd = new FormData();
      fd.append("file", file);
      const fallbackAlt = file.name.replace(/\.[^.]+$/, "").replace(/[-_]+/g, " ");
      fd.append("alt_text", files.length === 1 && altText.value ? altText.value : fallbackAlt);
      await apiUpload("media.upload", fd);
    }
    clearSelection();
    notifyToast(`Uploaded & verified ${files.length} image${files.length === 1 ? "" : "s"}.`, "success");
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Upload rejected.", "destructive");
  } finally {
    uploading.value = false;
  }
}

function onDrop(e: DragEvent) {
  e.preventDefault();
  dragActive.value = false;
  if (!isEditor.value) return;
  void uploadFileList(e.dataTransfer?.files || null);
}

async function saveAltText(item: MediaItem) {
  if (item.id <= 0) return;
  savingAltId.value = item.id;
  try {
    const nextAlt = (altDrafts.value[item.id] || "").trim();
    await apiPost("media.update", { id: item.id, alt_text: nextAlt });
    item.alt_text = nextAlt;
    notifyToast(`Updated alt text for ${item.filename}.`, "success");
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Alt text update failed.", "destructive");
  } finally {
    savingAltId.value = null;
  }
}

async function copyImgTag(item: MediaItem) {
  const safeAlt = (item.id > 0 ? altDrafts.value[item.id] : item.alt_text) || item.alt_text || item.filename;
  const tag = `<img src="${item.public_path}" alt="${safeAlt}" width="${item.width || 800}" height="${item.height || 600}" loading="lazy" />`;
  const key = `${item.source_origin || "upload"}:${item.public_path}`;
  const ok = await copyText(tag);
  copiedId.value = ok ? key : null;
  let msg = ok ? `Copied snippet for ${item.filename}` : "Copy failed";
  if (msg === lastCopyAnnouncement) msg += "\u00A0";
  lastCopyAnnouncement = msg;
  copyLiveMessage.value = msg;
  if (ok) notifyToast("Copied Gate 06 <img> snippet to clipboard.", "success");
  else notifyToast(tag);
  window.setTimeout(() => {
    if (copiedId.value === key) copiedId.value = null;
    copyLiveMessage.value = "";
  }, 2000);
}

function requestDelete(item: MediaItem) {
  deleteTarget.value = item;
  deleteConfirmOpen.value = true;
}

async function executeDelete() {
  if (!deleteTarget.value || deleteTarget.value.id <= 0) return;
  deleting.value = true;
  try {
    await apiPost("media.delete", { id: deleteTarget.value.id });
    notifyToast("Image removed from Media Library.", "success");
    deleteConfirmOpen.value = false;
    deleteTarget.value = null;
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Delete failed.", "destructive");
  } finally {
    deleting.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Raster Media Library</h2>
        <p class="text-sm text-muted-foreground">
          Multi-file upload &amp; static <code class="font-mono text-xs">/public/</code> image discovery with inline alt-text editing and Gate 06 natural dimensions.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <Badge variant="secondary">{{ items.length }} assets total</Badge>
      </div>
    </div>

    <Card v-if="isEditor" class="overflow-hidden">
      <div class="px-5 py-4 border-b border-border flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
          <Upload class="h-4 w-4 text-muted-foreground" aria-hidden="true" />
          <h3 class="text-sm font-semibold">Upload Verified Raster Assets</h3>
        </div>
        <span class="text-xs text-muted-foreground">Supported: .webp, .avif, .png, .jpg, .gif</span>
      </div>
      <div class="p-5 space-y-4">
        <div
          :class="[
            'rounded-lg border border-dashed p-5 text-center transition-colors',
            dragActive ? 'border-primary bg-accent/60' : 'border-border bg-muted/20',
          ]"
          @dragover.prevent="dragActive = true"
          @dragleave.prevent="dragActive = false"
          @drop="onDrop"
        >
          <ImageIcon class="h-6 w-6 mx-auto mb-1.5 text-muted-foreground" aria-hidden="true" />
          <p class="text-xs font-medium">Drag and drop one or multiple raster images here to upload directly</p>
          <p class="text-[11px] text-muted-foreground mt-0.5">Binary headers and natural image dimensions are verified server-side.</p>
        </div>
        <form id="media-upload-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="uploadFileList(fileInput?.files || null)">
          <div class="space-y-1">
            <label for="media-file" class="text-xs font-medium">Raster Image File(s)</label>
            <input
              id="media-file"
              ref="fileInput"
              type="file"
              multiple
              accept="image/webp,image/avif,image/png,image/jpeg,image/gif"
              required
              class="block w-full text-xs file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-3 file:py-1.5 file:text-xs file:font-medium hover:file:bg-accent"
              @change="selectedCount = ($event.target as HTMLInputElement).files?.length || 0"
            />
          </div>
          <div class="space-y-1">
            <label for="media-alt" class="text-xs font-medium">Accessible Alt Text (Single File)</label>
            <Input id="media-alt" v-model="altText" placeholder="Descriptive alt text" />
          </div>
        </form>
      </div>
      <div class="px-5 py-3 border-t border-border bg-muted/30 flex flex-wrap items-center justify-between gap-2">
        <span class="text-xs text-muted-foreground">
          {{ selectedCount > 0 ? `${selectedCount} file(s) staged for upload.` : "Batch uploads derive default alt text from filenames." }}
        </span>
        <div class="flex items-center gap-2">
          <Button v-if="selectedCount > 0" type="button" variant="ghost" size="sm" @click="clearSelection">
            Clear Selection
          </Button>
          <Button type="submit" form="media-upload-form" size="sm" :disabled="uploading">
            <Upload class="h-4 w-4" aria-hidden="true" />
            <span>{{ uploading ? "Uploading..." : "Upload Files" }}</span>
          </Button>
        </div>
      </div>
    </Card>

    <Card class="p-3 flex flex-wrap items-center justify-between gap-3">
      <div class="relative flex-1 min-w-52">
        <Search class="h-3.5 w-3.5 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true" />
        <label for="media-search" class="sr-only">Search media assets</label>
        <Input id="media-search" v-model="searchQ" type="search" placeholder="Filter by filename, path, or alt text..." class="pl-8" />
      </div>
      <div class="flex items-center gap-1 rounded-md border border-border p-0.5 text-xs">
        <button
          v-for="tab in (['all', 'upload', 'static'] as const)"
          :key="tab"
          type="button"
          :class="[
            'rounded px-2.5 py-1 cursor-pointer transition-colors',
            originFilter === tab ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
          ]"
          @click="originFilter = tab"
        >
          {{
            tab === "upload"
              ? `Uploads (${uploadCount})`
              : tab === "static"
                ? `Static /public (${staticCount})`
                : `All (${items.length})`
          }}
        </button>
      </div>
    </Card>

    <p role="status" aria-live="polite" class="sr-only">{{ copyLiveMessage }}</p>

    <Card v-if="filtered.length === 0" class="p-10 text-center text-sm text-muted-foreground">
      No images found matching your filter.
    </Card>

    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      <Card
        v-for="item in filtered"
        :key="`${item.source_origin || 'upload'}:${item.public_path}`"
        class="overflow-hidden flex flex-col hover:border-foreground/25 transition-colors"
      >
        <div class="aspect-video bg-muted flex items-center justify-center overflow-hidden border-b border-border/60">
          <img
            :src="item.url"
            :alt="item.alt_text || item.filename"
            :width="item.width || 320"
            :height="item.height || 180"
            loading="lazy"
            class="max-h-full max-w-full object-contain"
          />
        </div>
        <div class="p-4 space-y-2.5 text-xs flex-1 flex flex-col justify-between">
          <div class="space-y-1">
            <div class="flex items-center justify-between gap-2">
              <span class="font-medium truncate">{{ item.filename }}</span>
              <Badge :variant="item.source_origin === 'static' ? 'outline' : 'secondary'">
                {{ item.source_origin === "static" ? "Static" : "Upload" }}
              </Badge>
            </div>
            <div class="text-muted-foreground font-mono truncate">{{ item.public_path }}</div>
            <div class="text-muted-foreground font-mono">
              {{ item.width }}×{{ item.height }} · {{ Math.max(1, Math.round(item.size_bytes / 1024)) }} KB · {{ formatDateTime(item.created_at) }}
            </div>
          </div>

          <div v-if="isEditor && item.id > 0" class="flex gap-1.5 items-center pt-1">
            <label :for="`alt-${item.id}`" class="sr-only">Alt text for {{ item.filename }}</label>
            <Input
              :id="`alt-${item.id}`"
              v-model="altDrafts[item.id]"
              placeholder="Alt text description..."
              class="h-8 text-xs"
            />
            <Button
              variant="outline"
              size="sm"
              :disabled="savingAltId === item.id || altDrafts[item.id] === (item.alt_text || '')"
              @click="saveAltText(item)"
            >
              Save Alt
            </Button>
          </div>
        </div>
        <div class="px-4 py-2.5 border-t border-border bg-muted/30 flex items-center justify-between">
          <Button variant="outline" size="sm" :aria-label="`Copy HTML img tag for ${item.filename}`" @click="copyImgTag(item)">
            <Check v-if="copiedId === `${item.source_origin || 'upload'}:${item.public_path}`" class="h-3.5 w-3.5" aria-hidden="true" />
            <Copy v-else class="h-3.5 w-3.5" aria-hidden="true" />
            <span>{{ copiedId === `${item.source_origin || 'upload'}:${item.public_path}` ? "Copied" : "Copy <img>" }}</span>
          </Button>
          <Button
            v-if="isEditor && item.source_origin !== 'static' && item.id > 0"
            variant="ghost"
            size="sm"
            :aria-label="`Delete asset ${item.filename}`"
            @click="requestDelete(item)"
          >
            <Trash2 class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
          </Button>
        </div>
      </Card>
    </div>

    <ConfirmDialog
      v-model:open="deleteConfirmOpen"
      title="Delete Uploaded Media Asset?"
      :description="`Permanently delete ${deleteTarget?.filename}? Published articles referencing ${deleteTarget?.public_path} will show a broken image unless updated.`"
      confirm-label="Delete Asset"
      variant="destructive"
      :loading="deleting"
      @confirm="executeDelete"
    />
  </div>
</template>
