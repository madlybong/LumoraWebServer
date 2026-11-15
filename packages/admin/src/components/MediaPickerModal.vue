<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { Image as ImageIcon, Search, Upload } from "lucide-vue-next";
import { apiGet, apiUpload } from "../lib/api";
import type { MediaItem } from "../lib/types";
import { isEditor, notifyToast } from "../stores/admin";
import Badge from "./ui/Badge.vue";
import Button from "./ui/Button.vue";
import Input from "./ui/Input.vue";
import ModalDialog from "./ui/ModalDialog.vue";

const open = defineModel<boolean>("open", { default: false });

const props = withDefaults(
  defineProps<{
    title?: string;
  }>(),
  {
    title: "Select or Upload Media Asset",
  }
);

const emit = defineEmits<{
  select: [item: MediaItem];
}>();

const loading = ref(false);
const uploading = ref(false);
const items = ref<MediaItem[]>([]);
const query = ref("");
const originFilter = ref<"all" | "upload" | "static">("all");
const dragActive = ref(false);

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase();
  return items.value.filter((item) => {
    if (originFilter.value !== "all" && (item.source_origin || "upload") !== originFilter.value) {
      return false;
    }
    if (!q) return true;
    return (
      item.filename.toLowerCase().includes(q) ||
      item.public_path.toLowerCase().includes(q) ||
      (item.alt_text || "").toLowerCase().includes(q)
    );
  });
});

async function load() {
  loading.value = true;
  try {
    const res = await apiGet<{ ok: boolean; items: MediaItem[] }>("media.list");
    items.value = res.items;
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load media library.", "destructive");
  } finally {
    loading.value = false;
  }
}

watch(open, (val) => {
  if (val) {
    void load();
  }
});

async function uploadFiles(files: FileList | null) {
  if (!files || files.length === 0 || !isEditor.value) return;
  uploading.value = true;
  try {
    let lastUploaded: MediaItem | null = null;
    for (const file of Array.from(files)) {
      const fd = new FormData();
      fd.append("file", file);
      fd.append("alt_text", file.name.replace(/\.[^.]+$/, "").replace(/[-_]+/g, " "));
      const res = await apiUpload<{ ok: boolean; item: MediaItem }>("media.upload", fd);
      if (res.item) {
        lastUploaded = res.item;
      }
    }
    notifyToast(`Uploaded ${files.length} image${files.length === 1 ? "" : "s"}.`, "success");
    await load();
    if (files.length === 1 && lastUploaded) {
      pick(lastUploaded);
    }
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Upload failed.", "destructive");
  } finally {
    uploading.value = false;
  }
}

function handleDrop(e: DragEvent) {
  e.preventDefault();
  dragActive.value = false;
  void uploadFiles(e.dataTransfer?.files || null);
}

function pick(item: MediaItem) {
  emit("select", item);
  open.value = false;
}
</script>

<template>
  <ModalDialog
    v-model:open="open"
    :title="props.title"
    description="Choose an existing uploaded/static image or drag and drop a new file."
    size="xl"
  >
    <div class="space-y-4">
      <div class="sticky top-0 z-10 bg-card pb-3 border-b border-border space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <div class="relative flex-1 min-w-48">
            <Search class="h-3.5 w-3.5 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true" />
            <label for="media-picker-search" class="sr-only">Search media assets</label>
            <Input id="media-picker-search" v-model="query" placeholder="Search filename, path, alt text..." class="pl-8" />
          </div>
          <div class="flex items-center gap-1 rounded-md border border-border p-0.5 text-xs">
            <button
              v-for="tab in (['all', 'upload', 'static'] as const)"
              :key="tab"
              type="button"
              :class="[
                'rounded px-2.5 py-1 capitalize cursor-pointer',
                originFilter === tab ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
              ]"
              @click="originFilter = tab"
            >
              {{ tab === "upload" ? "Uploaded" : tab === "static" ? "Static /public" : "All" }}
            </button>
          </div>
        </div>

        <div
          v-if="isEditor"
          :class="[
            'rounded-md border border-dashed p-3 text-xs flex flex-wrap items-center justify-between gap-2 transition-colors',
            dragActive ? 'border-primary bg-accent/60' : 'border-border bg-muted/20',
          ]"
          @dragover.prevent="dragActive = true"
          @dragleave.prevent="dragActive = false"
          @drop="handleDrop"
        >
          <div class="flex items-center gap-2 text-muted-foreground">
            <Upload class="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>Drag and drop raster images here (PNG, JPEG, WebP, GIF, AVIF) to upload &amp; insert.</span>
          </div>
          <label class="inline-flex items-center gap-1.5 rounded-md border border-input bg-background px-3 py-1.5 font-medium hover:bg-accent cursor-pointer">
            <input
              type="file"
              accept="image/png,image/jpeg,image/webp,image/gif,image/avif"
              class="sr-only"
              :disabled="uploading"
              @change="uploadFiles(($event.target as HTMLInputElement).files)"
            />
            <span>{{ uploading ? "Uploading..." : "Choose File" }}</span>
          </label>
        </div>
      </div>

      <div v-if="loading" class="py-12 text-center text-sm text-muted-foreground">Loading media library...</div>
      <div v-else-if="filtered.length === 0" class="py-12 text-center text-sm text-muted-foreground">
        No matching images found.
      </div>
      <div v-else class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
        <button
          v-for="item in filtered"
          :key="`${item.source_origin || 'upload'}:${item.public_path}`"
          type="button"
          class="group text-left rounded-md border border-border bg-background overflow-hidden hover:border-primary transition-colors cursor-pointer"
          @click="pick(item)"
        >
          <div class="aspect-video bg-muted flex items-center justify-center overflow-hidden">
            <img
              v-if="item.url"
              :src="item.url"
              :alt="item.alt_text || item.filename"
              :width="item.width || 320"
              :height="item.height || 180"
              loading="lazy"
              class="h-full w-full object-cover group-hover:scale-[1.02] transition-transform"
            />
            <ImageIcon v-else class="h-6 w-6 text-muted-foreground" aria-hidden="true" />
          </div>
          <div class="p-2 space-y-1">
            <div class="flex items-center justify-between gap-1">
              <span class="truncate text-xs font-medium">{{ item.filename }}</span>
              <Badge :variant="item.source_origin === 'static' ? 'outline' : 'secondary'" class="text-[10px] px-1.5 py-0">
                {{ item.source_origin === "static" ? "static" : "upload" }}
              </Badge>
            </div>
            <div class="truncate text-[11px] font-mono text-muted-foreground">
              {{ item.width && item.height ? `${item.width}x${item.height}` : item.mime_type }}
            </div>
          </div>
        </button>
      </div>
    </div>

    <template #footer>
      <span class="mr-auto text-xs text-muted-foreground">{{ filtered.length }} asset(s) available</span>
      <Button variant="outline" size="sm" @click="open = false">Close</Button>
    </template>
  </ModalDialog>
</template>
