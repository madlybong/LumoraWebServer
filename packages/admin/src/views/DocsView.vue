<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import {
  ArrowLeft,
  FolderPlus,
  History,
  Pencil,
  Plus,
  RotateCcw,
  Save,
  Search,
  Trash2,
} from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import type { DocsArticleItem, DocsTrackItem } from "../lib/types";
import { slugifyText } from "../lib/utils";
import { adminStore, isEditor, navigateTo, navigateToSubRoute, notifyToast } from "../stores/admin";
import RevisionHistoryModal from "../components/RevisionHistoryModal.vue";
import RichContentEditor from "../components/RichContentEditor.vue";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";
import ModalDialog from "../components/ui/ModalDialog.vue";

const tracks = ref<DocsTrackItem[]>([]);
const loading = ref(false);
const searchQ = ref("");
const activeTrackFilter = ref("");
const trackModalOpen = ref(false);
const saving = ref(false);

const slugTouched = ref(false);
const mdMode = ref<"markdown" | "html">("markdown");
const hydratedRouteKey = ref("");

const revisionsOpen = ref(false);
const revisionKey = ref("");

const confirmDeleteOpen = ref(false);
const confirmTarget = ref<{ trackId: string; article: DocsArticleItem } | null>(null);

const trackForm = reactive({
  track_id: "",
  title: "",
  description: "",
  sort_order: 100,
});

const articleForm = reactive({
  track_id: "operator",
  slug: "",
  title: "",
  summary: "",
  markdown: "# Documentation Title\n\nWrite Markdown documentation here...",
  tagsStr: "",
  status: "published",
  sort_order: 100,
});

const inStudio = computed(
  () => adminStore.currentRoute === "docs" && (adminStore.subRoute.mode === "new" || adminStore.subRoute.mode === "edit")
);
const isCreateArticle = computed(() => adminStore.currentRoute === "docs" && adminStore.subRoute.mode === "new");

const currentArticleRecord = computed(() => {
  if (isCreateArticle.value || !articleForm.track_id || !articleForm.slug) return null;
  const tr = tracks.value.find((t) => t.track_id === articleForm.track_id);
  return tr?.articles.find((a) => a.slug === articleForm.slug) || null;
});

const parsedTags = computed(() =>
  articleForm.tagsStr
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean)
);

const filteredTracks = computed(() => {
  const q = searchQ.value.trim().toLowerCase();
  const base = activeTrackFilter.value
    ? tracks.value.filter((t) => t.track_id === activeTrackFilter.value)
    : tracks.value;
  if (!q) return base;
  return base
    .map((tr) => ({
      ...tr,
      articles: tr.articles.filter(
        (a) =>
          a.title.toLowerCase().includes(q) ||
          a.slug.toLowerCase().includes(q) ||
          tr.title.toLowerCase().includes(q) ||
          tr.track_id.toLowerCase().includes(q)
      ),
    }))
    .filter((tr) => tr.articles.length > 0 || tr.title.toLowerCase().includes(q) || tr.track_id.toLowerCase().includes(q));
});

watch(
  () => articleForm.title,
  (val) => {
    if (isCreateArticle.value && !slugTouched.value) {
      articleForm.slug = slugifyText(val);
    }
  }
);

async function load() {
  loading.value = true;
  try {
    const res = await apiGet<{ tracks: DocsTrackItem[] }>("docs.list");
    tracks.value = res.tracks || [];
    syncStudioFromRoute();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load docs manifest.", "destructive");
  } finally {
    loading.value = false;
  }
}

function populateNewArticle(defaultTrack?: string) {
  slugTouched.value = false;
  mdMode.value = "markdown";
  const resolvedTrack = defaultTrack || tracks.value[0]?.track_id || "operator";
  Object.assign(articleForm, {
    track_id: resolvedTrack,
    slug: "",
    title: "",
    summary: "",
    markdown: "# Guide Title\n\nDescribe the workflow cleanly.",
    tagsStr: "",
    status: "published",
    sort_order: 100,
  });
}

function populateEditArticle(trackId: string, art: DocsArticleItem) {
  slugTouched.value = true;
  mdMode.value = "markdown";
  Object.assign(articleForm, {
    track_id: trackId,
    slug: art.slug,
    title: art.title,
    summary: art.summary || "",
    markdown: art.markdown || `# ${art.title}\n\n${art.summary || ""}`,
    tagsStr: (art.tags || []).join(", "),
    status: art.status || "published",
    sort_order: art.sort_order ?? 100,
  });
}

function syncStudioFromRoute(force = false) {
  if (adminStore.currentRoute !== "docs") return;
  const mode = adminStore.subRoute.mode;
  const p1 = adminStore.subRoute.param1 || "";
  const p2 = adminStore.subRoute.param2 || "";
  const routeKey = `${mode}:${p1}:${p2}`;
  if (!force && hydratedRouteKey.value === routeKey) return;

  if (mode === "new") {
    hydratedRouteKey.value = routeKey;
    populateNewArticle(p1 || undefined);
    return;
  }
  if (mode === "edit" && p1 && p2) {
    const tr = tracks.value.find((t) => t.track_id === p1);
    const art = tr?.articles.find((a) => a.slug === p2);
    if (art) {
      hydratedRouteKey.value = routeKey;
      populateEditArticle(p1, art);
    } else if (!loading.value && tracks.value.length > 0) {
      notifyToast(`Doc article "${p1}/${p2}" not found.`, "destructive");
      navigateTo("docs");
    }
    return;
  }
  hydratedRouteKey.value = "";
}

watch(
  () => [adminStore.subRoute.mode, adminStore.subRoute.param1, adminStore.subRoute.param2],
  () => {
    if (adminStore.currentRoute !== "docs") return;
    syncStudioFromRoute();
  }
);

function openCreateTrack() {
  Object.assign(trackForm, { track_id: "", title: "", description: "", sort_order: 100 });
  trackModalOpen.value = true;
}

async function saveTrack() {
  saving.value = true;
  try {
    await apiPost("docs.track-save", trackForm);
    trackModalOpen.value = false;
    notifyToast("Documentation track saved.", "success");
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Save failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

function openCreateArticle(defaultTrack?: string) {
  hydratedRouteKey.value = "";
  navigateToSubRoute("docs", "new", defaultTrack || undefined);
}

function openEditArticle(trackId: string, art: DocsArticleItem) {
  hydratedRouteKey.value = "";
  navigateToSubRoute("docs", "edit", trackId, art.slug);
}

function openRevisions(trackId: string, slug: string) {
  revisionKey.value = `${trackId}/${slug}`;
  revisionsOpen.value = true;
}

async function onRevisionRestored(payload: { entity_key: string }) {
  await load();
  const [tid, sl] = payload.entity_key.split("/", 2);
  if (inStudio.value && articleForm.track_id === tid && articleForm.slug === sl) {
    syncStudioFromRoute(true);
  }
}

async function saveArticle() {
  saving.value = true;
  try {
    await apiPost("docs.save", {
      track_id: articleForm.track_id,
      slug: articleForm.slug,
      title: articleForm.title,
      summary: articleForm.summary,
      markdown: articleForm.markdown,
      tags: parsedTags.value,
      status: articleForm.status,
      sort_order: Number(articleForm.sort_order) || 100,
    });
    notifyToast("Documentation article saved and revision recorded.", "success");
    await load();
    if (isCreateArticle.value && articleForm.track_id && articleForm.slug) {
      navigateToSubRoute("docs", "edit", articleForm.track_id, articleForm.slug);
    } else {
      syncStudioFromRoute(true);
    }
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to save article.", "destructive");
  } finally {
    saving.value = false;
  }
}

function requestDelete(trackId: string, article: DocsArticleItem) {
  confirmTarget.value = { trackId, article };
  confirmDeleteOpen.value = true;
}

async function executeDelete() {
  if (!confirmTarget.value) return;
  saving.value = true;
  const { trackId, article } = confirmTarget.value;
  try {
    await apiPost("docs.delete", { track_id: trackId, slug: article.slug });
    notifyToast(article.has_file_origin ? "Reverted article to base Markdown file." : "SQLite doc article deleted.", "success");
    confirmDeleteOpen.value = false;
    confirmTarget.value = null;
    await load();
    if (inStudio.value && articleForm.track_id === trackId && articleForm.slug === article.slug) {
      if (article.has_file_origin) {
        syncStudioFromRoute(true);
      } else {
        navigateTo("docs");
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
  <!-- DIRECTORY VIEW -->
  <div v-if="!inStudio" class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Docs &amp; Markdown Manager</h2>
        <p class="text-sm text-muted-foreground">
          Tracks and articles merged seamlessly with <code class="font-mono text-xs">content/docs/manifest.json</code>.
        </p>
      </div>
      <div v-if="isEditor" class="flex gap-2">
        <Button variant="outline" size="sm" @click="openCreateTrack">
          <FolderPlus class="h-4 w-4" aria-hidden="true" />
          <span>Add Track</span>
        </Button>
        <Button size="sm" @click="openCreateArticle()">
          <Plus class="h-4 w-4" aria-hidden="true" />
          <span>New Doc Article</span>
        </Button>
      </div>
    </div>

    <Card class="p-3.5 space-y-3">
      <div class="relative">
        <Search class="h-3.5 w-3.5 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true" />
        <label for="docs-search" class="sr-only">Filter documentation tracks and articles</label>
        <Input id="docs-search" v-model="searchQ" type="search" placeholder="Search documentation tracks, titles, or slugs..." class="pl-8" />
      </div>

      <div v-if="tracks.length > 0" class="flex flex-wrap items-center gap-1.5">
        <button
          type="button"
          :class="[
            'rounded-full border px-3 py-1 text-xs font-medium transition-colors cursor-pointer',
            activeTrackFilter === '' ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background text-muted-foreground hover:text-foreground',
          ]"
          @click="activeTrackFilter = ''"
        >
          All Tracks ({{ tracks.reduce((sum, t) => sum + t.articles.length, 0) }})
        </button>
        <button
          v-for="tr in tracks"
          :key="tr.track_id"
          type="button"
          :class="[
            'rounded-full border px-3 py-1 text-xs font-medium transition-colors cursor-pointer',
            activeTrackFilter === tr.track_id ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-background text-muted-foreground hover:text-foreground',
          ]"
          @click="activeTrackFilter = tr.track_id"
        >
          {{ tr.title }} ({{ tr.articles.length }})
        </button>
      </div>
    </Card>

    <div v-if="filteredTracks.length === 0" class="text-sm text-muted-foreground py-8 text-center">
      No documentation tracks match your filter.
    </div>

    <div v-for="tr in filteredTracks" :key="tr.track_id" class="space-y-2.5">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 class="text-base font-semibold">{{ tr.title }}</h3>
          <p class="text-xs text-muted-foreground font-mono">track: {{ tr.track_id }} · {{ tr.description }}</p>
        </div>
        <Button v-if="isEditor" variant="outline" size="sm" @click="openCreateArticle(tr.track_id)">
          <Plus class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Add Article</span>
        </Button>
      </div>
      <Card class="overflow-hidden">
        <div class="divide-y divide-border">
          <div v-for="art in tr.articles" :key="art.slug" class="px-4 py-3 flex flex-wrap items-center justify-between gap-3 hover:bg-muted/25">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <button
                  v-if="isEditor"
                  type="button"
                  class="font-medium text-sm hover:underline cursor-pointer text-left"
                  @click="openEditArticle(tr.track_id, art)"
                >
                  {{ art.title }}
                </button>
                <span v-else class="font-medium text-sm">{{ art.title }}</span>
                <Badge :variant="art.source_origin === 'sqlite' ? 'default' : 'outline'">
                  {{ art.source_origin === "sqlite" ? "SQLite" : "File" }}
                </Badge>
                <Badge v-if="art.status === 'draft'" variant="warning">draft</Badge>
              </div>
              <div class="text-xs font-mono text-muted-foreground">/docs/{{ tr.track_id }}/{{ art.slug }}/</div>
            </div>
            <div v-if="isEditor" class="flex gap-1">
              <Button variant="outline" size="sm" @click="openEditArticle(tr.track_id, art)">
                <Pencil class="h-3.5 w-3.5" aria-hidden="true" />
                <span>{{ art.source_origin === "sqlite" ? "Edit" : "Override" }}</span>
              </Button>
              <Button
                v-if="art.source_origin === 'sqlite'"
                variant="ghost"
                size="sm"
                :aria-label="`Revision history for ${tr.track_id}/${art.slug}`"
                @click="openRevisions(tr.track_id, art.slug)"
              >
                <History class="h-3.5 w-3.5" aria-hidden="true" />
              </Button>
              <Button
                v-if="art.source_origin === 'sqlite'"
                variant="ghost"
                size="sm"
                :aria-label="art.has_file_origin ? `Revert ${art.slug} to file` : `Delete ${art.slug}`"
                :title="art.has_file_origin ? 'Revert SQLite override to base file' : 'Delete SQLite article'"
                @click="requestDelete(tr.track_id, art)"
              >
                <RotateCcw v-if="art.has_file_origin" class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                <Trash2 v-else class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
              </Button>
            </div>
          </div>
        </div>
      </Card>
    </div>
  </div>

  <!-- FULL-PAGE DOCUMENTATION STUDIO -->
  <div v-else class="space-y-6">
    <!-- Sticky Top Studio Bar -->
    <div class="sticky -top-4 sm:-top-6 lg:-top-8 z-20 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-3 bg-background/95 backdrop-blur border-b border-border flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-3 min-w-0">
        <Button variant="outline" size="sm" @click="navigateTo('docs')">
          <ArrowLeft class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Back to Docs</span>
        </Button>
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h2 class="text-base sm:text-lg font-semibold tracking-tight truncate">
              {{ isCreateArticle ? "New Documentation Article" : articleForm.title || articleForm.slug }}
            </h2>
            <Badge :variant="articleForm.status === 'draft' ? 'warning' : 'success'">{{ articleForm.status }}</Badge>
            <Badge v-if="currentArticleRecord" :variant="currentArticleRecord.source_origin === 'sqlite' ? 'default' : 'outline'">
              {{ currentArticleRecord.source_origin === "sqlite" ? "SQLite" : "Markdown File" }}
            </Badge>
          </div>
          <div class="text-xs font-mono text-muted-foreground truncate">
            /docs/{{ articleForm.track_id || "track" }}/{{ articleForm.slug || "slug" }}/
          </div>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Button
          v-if="!isCreateArticle && articleForm.track_id && articleForm.slug"
          type="button"
          variant="outline"
          size="sm"
          @click="openRevisions(articleForm.track_id, articleForm.slug)"
        >
          <History class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Revisions</span>
        </Button>
        <Button
          v-if="currentArticleRecord?.source_origin === 'sqlite'"
          type="button"
          variant="outline"
          size="sm"
          @click="requestDelete(articleForm.track_id, currentArticleRecord)"
        >
          <RotateCcw v-if="currentArticleRecord.has_file_origin" class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
          <Trash2 v-else class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
          <span>{{ currentArticleRecord.has_file_origin ? "Reset to File" : "Delete" }}</span>
        </Button>
        <Button type="submit" form="docs-studio-form" size="sm" :disabled="saving">
          <Save class="h-3.5 w-3.5" aria-hidden="true" />
          <span>{{ saving ? "Saving..." : "Save Documentation" }}</span>
        </Button>
      </div>
    </div>

    <form id="docs-studio-form" class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start" @submit.prevent="saveArticle">
      <!-- Left Main Column -->
      <div class="lg:col-span-8 space-y-5 min-w-0">
        <Card class="p-5 space-y-4">
          <div class="space-y-1.5">
            <label for="doc-title" class="text-xs font-medium">Article Title</label>
            <Input id="doc-title" v-model="articleForm.title" required placeholder="Guide title..." class="text-base font-medium h-10" />
          </div>
          <div class="space-y-1.5">
            <label for="doc-sum" class="text-xs font-medium">Summary</label>
            <Input id="doc-sum" v-model="articleForm.summary" placeholder="Short overview for track listing and search cards..." />
          </div>

          <RichContentEditor
            id="doc-md"
            v-model="articleForm.markdown"
            v-model:mode="mdMode"
            label="Markdown Documentation Source"
            preview-action="docs.preview"
            default-layout="split"
            min-height="500px"
            max-height="720px"
          />
        </Card>
      </div>

      <!-- Right Sticky Inspector -->
      <div class="lg:col-span-4 space-y-5 lg:sticky lg:top-16 min-w-0">
        <Card class="p-5 space-y-4">
          <h3 class="text-sm font-semibold border-b border-border pb-2.5">Documentation Taxonomy</h3>
          <div class="space-y-3">
            <div class="space-y-1">
              <label for="doc-status" class="text-xs font-medium">Status</label>
              <select id="doc-status" v-model="articleForm.status" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
                <option value="published">published</option>
                <option value="draft">draft (localhost preview only)</option>
              </select>
            </div>

            <div class="space-y-1">
              <label for="doc-track" class="text-xs font-medium">Track ID (lowercase-hyphens)</label>
              <Input
                id="doc-track"
                v-model="articleForm.track_id"
                list="lumora-doc-tracks"
                pattern="^[a-z0-9-]+$"
                required
                spellcheck="false"
                class="font-mono text-xs"
              />
              <datalist id="lumora-doc-tracks">
                <option v-for="tr in tracks" :key="tr.track_id" :value="tr.track_id">{{ tr.title }}</option>
              </datalist>
            </div>

            <div class="space-y-1">
              <label for="doc-slug" class="text-xs font-medium">Article Slug</label>
              <Input
                id="doc-slug"
                v-model="articleForm.slug"
                pattern="^[a-z0-9-]+$"
                required
                spellcheck="false"
                class="font-mono text-xs"
                @input="slugTouched = true"
              />
            </div>

            <div class="space-y-1">
              <label for="doc-order" class="text-xs font-medium">Sort Order</label>
              <Input id="doc-order" v-model="articleForm.sort_order" type="number" min="0" />
            </div>

            <div class="space-y-1.5">
              <label for="doc-tags" class="text-xs font-medium">Tags (comma-separated)</label>
              <Input id="doc-tags" v-model="articleForm.tagsStr" placeholder="cli, security, configuration" />
              <div v-if="parsedTags.length > 0" class="flex flex-wrap gap-1 pt-1">
                <Badge v-for="t in parsedTags" :key="t" variant="secondary" class="text-[11px]">{{ t }}</Badge>
              </div>
            </div>
          </div>
        </Card>
      </div>
    </form>
  </div>

  <!-- Create / Update Docs Track Compact Modal -->
  <ModalDialog
    v-model:open="trackModalOpen"
    title="Create / Update Docs Track"
    description="Group documentation guides under a named URL track."
    size="sm"
  >
    <form id="docs-track-form" class="space-y-3" @submit.prevent="saveTrack">
      <div class="space-y-1">
        <label for="tr-id" class="text-xs font-medium">Track ID</label>
        <Input id="tr-id" v-model="trackForm.track_id" pattern="^[a-z0-9-]+$" required placeholder="operator" class="font-mono text-xs" />
      </div>
      <div class="space-y-1">
        <label for="tr-title" class="text-xs font-medium">Title</label>
        <Input id="tr-title" v-model="trackForm.title" required placeholder="Operator Manual" />
      </div>
      <div class="space-y-1">
        <label for="tr-desc" class="text-xs font-medium">Description</label>
        <Input id="tr-desc" v-model="trackForm.description" placeholder="Setup, security hardening, and deployment..." />
      </div>
      <div class="space-y-1">
        <label for="tr-order" class="text-xs font-medium">Sort Order</label>
        <Input id="tr-order" v-model="trackForm.sort_order" type="number" min="0" />
      </div>
    </form>
    <template #footer>
      <Button variant="outline" size="sm" @click="trackModalOpen = false">Cancel</Button>
      <Button type="submit" form="docs-track-form" size="sm" :disabled="saving">Save Track</Button>
    </template>
  </ModalDialog>

  <RevisionHistoryModal
    v-model:open="revisionsOpen"
    entity-type="docs"
    :entity-key="revisionKey"
    :can-restore="isEditor"
    @restored="onRevisionRestored"
  />

  <ConfirmDialog
    v-model:open="confirmDeleteOpen"
    :title="confirmTarget?.article.has_file_origin ? 'Revert Article to File?' : 'Delete Documentation Article?'"
    :description="confirmTarget?.article.has_file_origin ? `Remove SQLite override for ${confirmTarget?.trackId}/${confirmTarget?.article.slug} and restore the underlying Markdown file?` : `Permanently delete SQLite article ${confirmTarget?.trackId}/${confirmTarget?.article.slug}?`"
    :confirm-label="confirmTarget?.article.has_file_origin ? 'Revert to File' : 'Delete Article'"
    variant="destructive"
    :loading="saving"
    @confirm="executeDelete"
  />
  </div>
</template>
