<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import {
  ArrowLeft,
  Globe,
  History,
  Image as ImageIcon,
  Pencil,
  Plus,
  RotateCcw,
  Save,
  Search,
  Trash2,
} from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import type { BlogPostItem, MediaItem } from "../lib/types";
import { slugifyText } from "../lib/utils";
import { adminStore, isEditor, navigateTo, navigateToSubRoute, notifyToast } from "../stores/admin";
import MediaPickerModal from "../components/MediaPickerModal.vue";
import RevisionHistoryModal from "../components/RevisionHistoryModal.vue";
import RichContentEditor from "../components/RichContentEditor.vue";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";

const posts = ref<BlogPostItem[]>([]);
const loading = ref(false);
const saving = ref(false);
const searchQ = ref("");
const filterStatus = ref<"" | "published" | "scheduled" | "draft">("");

const slugTouched = ref(false);
const pickerTarget = ref<"hero" | "og">("hero");
const pickerOpen = ref(false);

const revisionsOpen = ref(false);
const revisionKey = ref("");

const confirmDeleteOpen = ref(false);
const confirmTarget = ref<BlogPostItem | null>(null);
const editorMode = ref<"html" | "markdown">("html");
const hydratedRouteKey = ref("");

const draft = reactive({
  slug: "",
  title: "",
  summary: "",
  author: "",
  category: "Engineering",
  tagsStr: "",
  date: new Date().toISOString().slice(0, 10),
  image: "",
  status: "published",
  content_html: "",
  meta_title: "",
  meta_description: "",
  og_image: "",
  canonical_url: "",
});

const inStudio = computed(
  () => adminStore.currentRoute === "blog" && (adminStore.subRoute.mode === "new" || adminStore.subRoute.mode === "edit")
);
const isCreateMode = computed(() => adminStore.currentRoute === "blog" && adminStore.subRoute.mode === "new");

const currentPostRecord = computed(() => {
  if (isCreateMode.value || !draft.slug) return null;
  return posts.value.find((p) => p.slug === draft.slug) || null;
});

const statusCounts = computed(() => {
  let published = 0;
  let scheduled = 0;
  let draftCount = 0;
  let sqlite = 0;
  for (const p of posts.value) {
    const st = p.status || "published";
    if (st === "draft") draftCount++;
    else if (st === "scheduled") scheduled++;
    else published++;
    if (p.source_origin === "sqlite") sqlite++;
  }
  return { total: posts.value.length, published, scheduled, draft: draftCount, sqlite };
});

const parsedTags = computed(() =>
  draft.tagsStr
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean)
);

const seoPreviewTitle = computed(
  () => draft.meta_title.trim() || draft.title.trim() || "Untitled Article"
);
const seoPreviewDesc = computed(
  () =>
    draft.meta_description.trim() ||
    draft.summary.trim() ||
    "Add a summary or meta description to preview how search engines display this post."
);
const seoPreviewUrl = computed(() => {
  const domain = adminStore.boot.branding.domain || "example.com";
  const slug = draft.slug || "article-slug";
  return draft.canonical_url.trim() || `https://${domain}/blog/${slug}/`;
});

const filteredPosts = computed(() => {
  const q = searchQ.value.trim().toLowerCase();
  return posts.value.filter((p) => {
    if (filterStatus.value && (p.status || "published") !== filterStatus.value) {
      return false;
    }
    if (!q) return true;
    return (
      p.title.toLowerCase().includes(q) ||
      p.slug.toLowerCase().includes(q) ||
      (p.category || "").toLowerCase().includes(q)
    );
  });
});

watch(
  () => draft.title,
  (val) => {
    if (isCreateMode.value && !slugTouched.value) {
      draft.slug = slugifyText(val);
    }
  }
);

async function load() {
  loading.value = true;
  try {
    const res = await apiGet<{ posts: BlogPostItem[] }>("blog.list");
    posts.value = res.posts || [];
    syncStudioFromRoute();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load blog posts.", "destructive");
  } finally {
    loading.value = false;
  }
}

function populateNewDraft() {
  slugTouched.value = false;
  editorMode.value = "html";
  Object.assign(draft, {
    slug: "",
    title: "",
    summary: "",
    author: adminStore.user?.name || "",
    category: "Engineering",
    tagsStr: "",
    date: new Date().toISOString().slice(0, 10),
    image: "",
    status: "published",
    content_html: "<p></p>",
    meta_title: "",
    meta_description: "",
    og_image: "",
    canonical_url: "",
  });
}

function populateEditDraft(post: BlogPostItem) {
  slugTouched.value = true;
  editorMode.value = "html";
  Object.assign(draft, {
    slug: post.slug,
    title: post.title,
    summary: post.summary || "",
    author: post.author || "",
    category: post.category || "General",
    tagsStr: (post.tags || []).join(", "),
    date: post.date || new Date().toISOString().slice(0, 10),
    image: post.image || "",
    status: post.status || "published",
    content_html: post.content || "",
    meta_title: post.meta_title || "",
    meta_description: post.meta_description || "",
    og_image: post.og_image || "",
    canonical_url: post.canonical_url || "",
  });
}

function syncStudioFromRoute(force = false) {
  if (adminStore.currentRoute !== "blog") return;
  const mode = adminStore.subRoute.mode;
  const slugParam = adminStore.subRoute.param1 || "";
  const routeKey = `${mode}:${slugParam}`;
  if (!force && hydratedRouteKey.value === routeKey) return;

  if (mode === "new") {
    hydratedRouteKey.value = routeKey;
    populateNewDraft();
    return;
  }
  if (mode === "edit" && slugParam) {
    const post = posts.value.find((p) => p.slug === slugParam);
    if (post) {
      hydratedRouteKey.value = routeKey;
      populateEditDraft(post);
    } else if (!loading.value && posts.value.length > 0) {
      notifyToast(`Blog article "${slugParam}" not found.`, "destructive");
      navigateTo("blog");
    }
    return;
  }
  hydratedRouteKey.value = "";
}

watch(
  () => [adminStore.subRoute.mode, adminStore.subRoute.param1],
  () => {
    if (adminStore.currentRoute !== "blog") return;
    syncStudioFromRoute();
  }
);

function openCreate() {
  hydratedRouteKey.value = "";
  navigateToSubRoute("blog", "new");
}

function openEdit(post: BlogPostItem) {
  hydratedRouteKey.value = "";
  navigateToSubRoute("blog", "edit", post.slug);
}

function openMediaPicker(target: "hero" | "og") {
  pickerTarget.value = target;
  pickerOpen.value = true;
}

function onMediaSelected(item: MediaItem) {
  const path = item.public_path || item.url;
  if (pickerTarget.value === "og") {
    draft.og_image = path;
  } else {
    draft.image = path;
  }
}

function openRevisions(slug: string) {
  revisionKey.value = slug;
  revisionsOpen.value = true;
}

async function onRevisionRestored(payload: { entity_key: string }) {
  await load();
  if (inStudio.value && draft.slug === payload.entity_key) {
    syncStudioFromRoute(true);
  }
}

async function savePost() {
  saving.value = true;
  try {
    const res = await apiPost<{ ok: boolean; post?: BlogPostItem }>("blog.save", {
      slug: draft.slug,
      title: draft.title,
      summary: draft.summary,
      author: draft.author,
      category: draft.category,
      tags: parsedTags.value,
      date: draft.date,
      image: draft.image,
      status: draft.status,
      editor_mode: editorMode.value,
      content_html: editorMode.value === "html" ? draft.content_html : "",
      content_markdown: editorMode.value === "markdown" ? draft.content_html : "",
      meta_title: draft.meta_title,
      meta_description: draft.meta_description,
      og_image: draft.og_image,
      canonical_url: draft.canonical_url,
    });
    notifyToast("Blog post saved and revision recorded.", "success");
    const savedSlug = res.post?.slug || draft.slug;
    await load();
    if (isCreateMode.value && savedSlug) {
      navigateToSubRoute("blog", "edit", savedSlug);
    } else {
      syncStudioFromRoute(true);
    }
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to save blog post.", "destructive");
  } finally {
    saving.value = false;
  }
}

function requestDelete(post: BlogPostItem) {
  confirmTarget.value = post;
  confirmDeleteOpen.value = true;
}

async function executeDelete() {
  if (!confirmTarget.value) return;
  saving.value = true;
  const targetSlug = confirmTarget.value.slug;
  const hadFileOrigin = Boolean(confirmTarget.value.has_file_origin);
  try {
    await apiPost("blog.delete", { slug: targetSlug });
    notifyToast(hadFileOrigin ? "Reverted post to base PHP file." : "SQLite blog post deleted.", "success");
    confirmDeleteOpen.value = false;
    confirmTarget.value = null;
    await load();
    if (inStudio.value && draft.slug === targetSlug) {
      if (hadFileOrigin) {
        syncStudioFromRoute(true);
      } else {
        navigateTo("blog");
      }
    }
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Delete failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

function statusBadgeVariant(status?: string) {
  if (status === "draft") return "warning";
  if (status === "scheduled") return "secondary";
  return "success";
}

onMounted(load);
</script>

<template>
  <div>
  <!-- LIST DIRECTORY VIEW -->
  <div v-if="!inStudio" class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Blog Manager</h2>
        <p class="text-sm text-muted-foreground">
          Hybrid merge of <code class="font-mono text-xs">content/blog/*.php</code> and runtime SQLite articles with scheduled publishing &amp; SEO overrides.
        </p>
      </div>
      <Button v-if="isEditor" size="sm" @click="openCreate">
        <Plus class="h-4 w-4" aria-hidden="true" />
        <span>New Article</span>
      </Button>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <button
        type="button"
        :class="['text-left rounded-lg border p-3.5 transition-colors cursor-pointer', filterStatus === '' ? 'border-primary bg-card shadow-xs' : 'border-border bg-card hover:bg-muted/30']"
        @click="filterStatus = ''"
      >
        <div class="text-xs text-muted-foreground">Total Articles</div>
        <div class="mt-1 text-xl font-bold">{{ statusCounts.total }}</div>
      </button>
      <button
        type="button"
        :class="['text-left rounded-lg border p-3.5 transition-colors cursor-pointer', filterStatus === 'published' ? 'border-primary bg-card shadow-xs' : 'border-border bg-card hover:bg-muted/30']"
        @click="filterStatus = 'published'"
      >
        <div class="text-xs text-muted-foreground">Published</div>
        <div class="mt-1 text-xl font-bold">{{ statusCounts.published }}</div>
      </button>
      <button
        type="button"
        :class="['text-left rounded-lg border p-3.5 transition-colors cursor-pointer', filterStatus === 'scheduled' ? 'border-primary bg-card shadow-xs' : 'border-border bg-card hover:bg-muted/30']"
        @click="filterStatus = 'scheduled'"
      >
        <div class="text-xs text-muted-foreground">Scheduled</div>
        <div class="mt-1 text-xl font-bold">{{ statusCounts.scheduled }}</div>
      </button>
      <button
        type="button"
        :class="['text-left rounded-lg border p-3.5 transition-colors cursor-pointer', filterStatus === 'draft' ? 'border-primary bg-card shadow-xs' : 'border-border bg-card hover:bg-muted/30']"
        @click="filterStatus = 'draft'"
      >
        <div class="text-xs text-muted-foreground">Drafts ({{ statusCounts.sqlite }} SQLite)</div>
        <div class="mt-1 text-xl font-bold">{{ statusCounts.draft }}</div>
      </button>
    </div>

    <Card class="p-3.5 flex flex-wrap items-center gap-3">
      <div class="relative flex-1 min-w-52">
        <Search class="h-3.5 w-3.5 text-muted-foreground absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" aria-hidden="true" />
        <label for="blog-filter-q" class="sr-only">Search blog articles</label>
        <Input id="blog-filter-q" v-model="searchQ" type="search" placeholder="Filter by title, slug, or category..." class="pl-8" />
      </div>
      <div class="w-44">
        <label for="blog-filter-status" class="sr-only">Filter blog status</label>
        <select id="blog-filter-status" v-model="filterStatus" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
          <option value="">All statuses</option>
          <option value="published">Published</option>
          <option value="scheduled">Scheduled</option>
          <option value="draft">Draft</option>
        </select>
      </div>
      <Button v-if="searchQ || filterStatus" variant="ghost" size="sm" @click="searchQ = ''; filterStatus = ''">
        Reset
      </Button>
    </Card>

    <Card class="overflow-hidden">
      <div tabindex="0" role="region" aria-labelledby="blog-cap" class="lumora-table-wrap">
        <table class="w-full text-sm text-left">
          <caption id="blog-cap" class="sr-only">Blog posts directory</caption>
          <thead class="border-b border-border bg-muted/60 text-xs uppercase text-muted-foreground">
            <tr>
              <th scope="col" class="px-4 py-2.5">Title &amp; Slug</th>
              <th scope="col" class="px-4 py-2.5">Category</th>
              <th scope="col" class="px-4 py-2.5">Origin</th>
              <th scope="col" class="px-4 py-2.5">Status</th>
              <th scope="col" class="px-4 py-2.5">Date</th>
              <th v-if="isEditor" scope="col" class="px-4 py-2.5 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border">
            <tr v-if="filteredPosts.length === 0">
              <td :colspan="isEditor ? 6 : 5" class="px-4 py-8 text-center text-muted-foreground">No blog posts match your filter.</td>
            </tr>
            <tr v-for="post in filteredPosts" :key="post.slug" class="hover:bg-muted/30">
              <td class="px-4 py-3">
                <button
                  v-if="isEditor"
                  type="button"
                  class="text-left font-medium hover:underline cursor-pointer"
                  @click="openEdit(post)"
                >
                  {{ post.title }}
                </button>
                <div v-else class="font-medium">{{ post.title }}</div>
                <div class="text-xs font-mono text-muted-foreground">/blog/{{ post.slug }}/</div>
              </td>
              <td class="px-4 py-3 text-xs text-muted-foreground">{{ post.category || "General" }}</td>
              <td class="px-4 py-3">
                <Badge :variant="post.source_origin === 'sqlite' ? 'default' : 'outline'">
                  {{ post.source_origin === "sqlite" ? "SQLite CMS" : "File" }}
                </Badge>
              </td>
              <td class="px-4 py-3">
                <Badge :variant="statusBadgeVariant(post.status)">{{ post.status || "published" }}</Badge>
              </td>
              <td class="px-4 py-3 text-xs font-mono text-muted-foreground">{{ post.date || "—" }}</td>
              <td v-if="isEditor" class="px-4 py-3 text-right space-x-1 whitespace-nowrap">
                <Button variant="outline" size="sm" @click="openEdit(post)">
                  <Pencil class="h-3.5 w-3.5" aria-hidden="true" />
                  <span>{{ post.source_origin === "sqlite" ? "Edit" : "Override" }}</span>
                </Button>
                <Button
                  v-if="post.source_origin === 'sqlite'"
                  variant="ghost"
                  size="sm"
                  :aria-label="`Revision history for ${post.slug}`"
                  @click="openRevisions(post.slug)"
                >
                  <History class="h-3.5 w-3.5" aria-hidden="true" />
                </Button>
                <Button
                  v-if="post.source_origin === 'sqlite'"
                  variant="ghost"
                  size="sm"
                  :aria-label="post.has_file_origin ? `Revert ${post.slug} to file` : `Delete ${post.slug}`"
                  :title="post.has_file_origin ? 'Revert SQLite override to base PHP file' : 'Delete SQLite post'"
                  @click="requestDelete(post)"
                >
                  <RotateCcw v-if="post.has_file_origin" class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                  <Trash2 v-else class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
                </Button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>
  </div>

  <!-- FULL-PAGE BLOG STUDIO -->
  <div v-else class="space-y-6">
    <!-- Sticky Top Studio Header Bar -->
    <div class="sticky -top-4 sm:-top-6 lg:-top-8 z-20 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-3 bg-background/95 backdrop-blur border-b border-border flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-3 min-w-0">
        <Button variant="outline" size="sm" @click="navigateTo('blog')">
          <ArrowLeft class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Back to Articles</span>
        </Button>
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h2 class="text-base sm:text-lg font-semibold tracking-tight truncate">
              {{ isCreateMode ? "New Blog Article" : draft.title || draft.slug }}
            </h2>
            <Badge :variant="statusBadgeVariant(draft.status)">{{ draft.status }}</Badge>
            <Badge v-if="currentPostRecord" :variant="currentPostRecord.source_origin === 'sqlite' ? 'default' : 'outline'">
              {{ currentPostRecord.source_origin === "sqlite" ? "SQLite" : "Flat-File Base" }}
            </Badge>
          </div>
          <div class="text-xs font-mono text-muted-foreground truncate">/blog/{{ draft.slug || "new-slug" }}/</div>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Button
          v-if="!isCreateMode && draft.slug"
          type="button"
          variant="outline"
          size="sm"
          @click="openRevisions(draft.slug)"
        >
          <History class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Revisions</span>
        </Button>
        <Button
          v-if="currentPostRecord?.source_origin === 'sqlite'"
          type="button"
          variant="outline"
          size="sm"
          @click="requestDelete(currentPostRecord)"
        >
          <RotateCcw v-if="currentPostRecord.has_file_origin" class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
          <Trash2 v-else class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
          <span>{{ currentPostRecord.has_file_origin ? "Reset to Flat-File" : "Delete" }}</span>
        </Button>
        <Button type="submit" form="blog-studio-form" size="sm" :disabled="saving">
          <Save class="h-3.5 w-3.5" aria-hidden="true" />
          <span>{{ saving ? "Saving..." : "Save Article" }}</span>
        </Button>
      </div>
    </div>

    <form id="blog-studio-form" class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start" @submit.prevent="savePost">
      <!-- Left Main Content Column -->
      <div class="lg:col-span-8 space-y-5 min-w-0">
        <Card class="p-5 space-y-4">
          <div class="space-y-1.5">
            <label for="blog-title" class="text-xs font-medium">Article Title</label>
            <Input id="blog-title" v-model="draft.title" required placeholder="Enter headline..." class="text-base font-medium h-10" />
          </div>

          <div class="space-y-1.5">
            <label for="blog-summary" class="text-xs font-medium">Summary / Excerpt</label>
            <Input id="blog-summary" v-model="draft.summary" placeholder="Concise article summary for index cards and Atom feed..." />
          </div>

          <RichContentEditor
            id="blog-html"
            v-model="draft.content_html"
            v-model:mode="editorMode"
            label="Article Content (Server-Sanitized on Save)"
            allow-mode-switch
            preview-action="blog.preview"
            default-layout="split"
            min-height="500px"
            max-height="720px"
          />
        </Card>
      </div>

      <!-- Right Sticky Inspector Column -->
      <div class="lg:col-span-4 space-y-5 lg:sticky lg:top-16 min-w-0">
        <Card class="p-5 space-y-4">
          <h3 class="text-sm font-semibold border-b border-border pb-2.5">Publishing &amp; Classification</h3>
          <div class="space-y-3">
            <div class="space-y-1">
              <label for="blog-status" class="text-xs font-medium">Publication Status</label>
              <select id="blog-status" v-model="draft.status" class="h-9 w-full rounded-md border border-input bg-background px-2.5 text-sm">
                <option value="published">published</option>
                <option value="scheduled">scheduled (auto-publishes on date)</option>
                <option value="draft">draft (localhost preview only)</option>
              </select>
            </div>

            <div class="space-y-1">
              <label for="blog-slug" class="text-xs font-medium">URL Slug (lowercase-hyphens)</label>
              <Input
                id="blog-slug"
                v-model="draft.slug"
                pattern="^[a-z0-9-]+$"
                required
                spellcheck="false"
                class="font-mono text-xs"
                @input="slugTouched = true"
              />
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
              <div class="space-y-1">
                <label for="blog-date" class="text-xs font-medium">
                  {{ draft.status === "scheduled" ? "Scheduled Publish Date" : "Publish Date" }}
                </label>
                <Input id="blog-date" v-model="draft.date" type="date" required />
              </div>
              <div class="space-y-1">
                <label for="blog-cat" class="text-xs font-medium">Category</label>
                <Input id="blog-cat" v-model="draft.category" />
              </div>
            </div>

            <div class="space-y-1">
              <label for="blog-author" class="text-xs font-medium">Author</label>
              <Input id="blog-author" v-model="draft.author" />
            </div>
          </div>
        </Card>

        <Card class="p-5 space-y-4">
          <h3 class="text-sm font-semibold border-b border-border pb-2.5">Cover Media &amp; Tags</h3>
          <div class="space-y-3">
            <div class="space-y-1.5">
              <label for="blog-img" class="text-xs font-medium">Cover Image Path</label>
              <div class="flex gap-1.5">
                <Input id="blog-img" v-model="draft.image" placeholder="/public/uploads/2026-11/cover.webp" class="font-mono text-xs" />
                <Button type="button" variant="outline" size="sm" aria-label="Pick cover image" @click="openMediaPicker('hero')">
                  <ImageIcon class="h-3.5 w-3.5" aria-hidden="true" />
                </Button>
              </div>
              <div v-if="draft.image" class="rounded-md border border-border bg-muted/40 overflow-hidden aspect-video flex items-center justify-center">
                <img :src="draft.image" :alt="draft.title || 'Cover preview'" class="max-h-full max-w-full object-contain" />
              </div>
            </div>

            <div class="space-y-1.5">
              <label for="blog-tags" class="text-xs font-medium">Tags (comma-separated)</label>
              <Input id="blog-tags" v-model="draft.tagsStr" placeholder="release, engineering, security" />
              <div v-if="parsedTags.length > 0" class="flex flex-wrap gap-1 pt-1">
                <Badge v-for="t in parsedTags" :key="t" variant="secondary" class="text-[11px]">{{ t }}</Badge>
              </div>
            </div>
          </div>
        </Card>

        <Card class="p-5 space-y-4">
          <h3 class="text-sm font-semibold border-b border-border pb-2.5">SEO &amp; OpenGraph Overrides</h3>
          <div class="space-y-3">
            <div class="space-y-1">
              <label for="blog-meta-title" class="text-xs font-medium">SEO Title Override</label>
              <Input id="blog-meta-title" v-model="draft.meta_title" placeholder="Custom <title> tag..." />
            </div>
            <div class="space-y-1">
              <label for="blog-canonical" class="text-xs font-medium">Canonical URL</label>
              <Input id="blog-canonical" v-model="draft.canonical_url" placeholder="/blog/my-post/" class="font-mono text-xs" />
            </div>
            <div class="space-y-1">
              <div class="flex items-center justify-between">
                <label for="blog-meta-desc" class="text-xs font-medium">Meta Description Override</label>
                <span class="text-[11px] font-mono text-muted-foreground">{{ draft.meta_description.length }}/160</span>
              </div>
              <Input id="blog-meta-desc" v-model="draft.meta_description" placeholder="120-160 character search snippet..." />
            </div>
            <div class="space-y-1">
              <label for="blog-og-img" class="text-xs font-medium">OpenGraph Social Share Image</label>
              <div class="flex gap-1.5">
                <Input id="blog-og-img" v-model="draft.og_image" placeholder="/public/uploads/og-card.webp" class="font-mono text-xs" />
                <Button type="button" variant="outline" size="sm" aria-label="Pick OpenGraph image" @click="openMediaPicker('og')">
                  <ImageIcon class="h-3.5 w-3.5" aria-hidden="true" />
                </Button>
              </div>
            </div>

            <div class="rounded-md border border-border bg-muted/25 p-3 space-y-1">
              <div class="flex items-center gap-1.5 text-[10px] font-mono uppercase tracking-wider text-muted-foreground">
                <Globe class="h-3 w-3" aria-hidden="true" />
                <span>Search Snippet Preview</span>
              </div>
              <div class="text-xs font-mono text-muted-foreground truncate">{{ seoPreviewUrl }}</div>
              <div class="text-sm font-medium text-blue-600 dark:text-blue-400 truncate">{{ seoPreviewTitle }}</div>
              <p class="text-xs text-muted-foreground line-clamp-2">{{ seoPreviewDesc }}</p>
            </div>
          </div>
        </Card>
      </div>
    </form>
  </div>

  <MediaPickerModal v-model:open="pickerOpen" title="Select Cover / Social Image" @select="onMediaSelected" />

  <RevisionHistoryModal
    v-model:open="revisionsOpen"
    entity-type="blog"
    :entity-key="revisionKey"
    :can-restore="isEditor"
    @restored="onRevisionRestored"
  />

  <ConfirmDialog
    v-model:open="confirmDeleteOpen"
    :title="confirmTarget?.has_file_origin ? 'Revert Post to Base PHP File?' : 'Delete SQLite Blog Post?'"
    :description="confirmTarget?.has_file_origin ? `Delete the SQLite override for /blog/${confirmTarget?.slug}/ and restore the underlying file-based post?` : `Permanently delete SQLite blog post /blog/${confirmTarget?.slug}/?`"
    :confirm-label="confirmTarget?.has_file_origin ? 'Revert to File' : 'Delete Post'"
    variant="destructive"
    :loading="saving"
    @confirm="executeDelete"
  />
  </div>
</template>
