<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";
import {
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  History,
  Pencil,
  Plus,
  RotateCcw,
  Save,
  Trash2,
} from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import type { LegalDocumentItem, LegalSection } from "../lib/types";
import { slugifyText } from "../lib/utils";
import { adminStore, isOwner, navigateTo, navigateToSubRoute, notifyToast } from "../stores/admin";
import RevisionHistoryModal from "../components/RevisionHistoryModal.vue";
import RichContentEditor from "../components/RichContentEditor.vue";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";

const documents = ref<LegalDocumentItem[]>([]);
const loading = ref(false);
const saving = ref(false);
const slugTouched = ref(false);
const hydratedRouteKey = ref("");

const revisionsOpen = ref(false);
const revisionKey = ref("");

const revertConfirmOpen = ref(false);
const revertTargetSlug = ref("");

const draft = reactive({
  slug: "",
  doc_no: "",
  title: "",
  lead: "",
  version: "1.0.0",
  date: new Date().toISOString().slice(0, 10),
  status: "ACTIVE POLICY",
  sections: [] as LegalSection[],
});

const inStudio = computed(
  () => adminStore.currentRoute === "legal" && (adminStore.subRoute.mode === "new" || adminStore.subRoute.mode === "edit")
);
const isCreateMode = computed(() => adminStore.currentRoute === "legal" && adminStore.subRoute.mode === "new");

const currentDocRecord = computed(() => {
  if (isCreateMode.value || !draft.slug) return null;
  return documents.value.find((d) => d.slug === draft.slug) || null;
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
    const res = await apiGet<{ documents: LegalDocumentItem[] }>("legal.list");
    documents.value = res.documents || [];
    syncStudioFromRoute();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load legal documents.", "destructive");
  } finally {
    loading.value = false;
  }
}

function populateNew() {
  slugTouched.value = false;
  Object.assign(draft, {
    slug: "",
    doc_no: "LGL-99",
    title: "",
    lead: "",
    version: "1.0.0",
    date: new Date().toISOString().slice(0, 10),
    status: "ACTIVE POLICY",
    sections: [{ id: "overview", title: "1. Overview", body: "<p></p>" }],
  });
}

function populateEdit(doc: LegalDocumentItem) {
  slugTouched.value = true;
  Object.assign(draft, {
    slug: doc.slug,
    doc_no: doc.doc_no || "",
    title: doc.title,
    lead: doc.lead || "",
    version: doc.version || "1.0.0",
    date: doc.date || new Date().toISOString().slice(0, 10),
    status: doc.status || "ACTIVE POLICY",
    sections: (doc.sections || []).map((s) => ({ ...s })),
  });
}

function syncStudioFromRoute(force = false) {
  if (adminStore.currentRoute !== "legal") return;
  const mode = adminStore.subRoute.mode;
  const slugParam = adminStore.subRoute.param1 || "";
  const routeKey = `${mode}:${slugParam}`;
  if (!force && hydratedRouteKey.value === routeKey) return;

  if (mode === "new") {
    hydratedRouteKey.value = routeKey;
    populateNew();
    return;
  }
  if (mode === "edit" && slugParam) {
    const doc = documents.value.find((d) => d.slug === slugParam);
    if (doc) {
      hydratedRouteKey.value = routeKey;
      populateEdit(doc);
    } else if (!loading.value && documents.value.length > 0) {
      notifyToast(`Legal document "${slugParam}" not found.`, "destructive");
      navigateTo("legal");
    }
    return;
  }
  hydratedRouteKey.value = "";
}

watch(
  () => [adminStore.subRoute.mode, adminStore.subRoute.param1],
  () => {
    if (adminStore.currentRoute !== "legal") return;
    syncStudioFromRoute();
  }
);

function openEdit(doc: LegalDocumentItem) {
  hydratedRouteKey.value = "";
  navigateToSubRoute("legal", "edit", doc.slug);
}

function openNew() {
  hydratedRouteKey.value = "";
  navigateToSubRoute("legal", "new");
}

function addSection() {
  draft.sections.push({
    id: `section-${draft.sections.length + 1}`,
    title: `${draft.sections.length + 1}. Clause`,
    body: "<p></p>",
  });
}

function moveSection(idx: number, delta: -1 | 1) {
  const next = idx + delta;
  if (next < 0 || next >= draft.sections.length) return;
  const copy = [...draft.sections];
  const [item] = copy.splice(idx, 1);
  copy.splice(next, 0, item);
  draft.sections = copy;
}

function scrollToSection(idx: number) {
  const el = document.getElementById(`legal-sec-card-${idx}`);
  el?.scrollIntoView({ behavior: "smooth", block: "start" });
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

async function saveDoc() {
  saving.value = true;
  try {
    const res = await apiPost<{ ok: boolean; document?: LegalDocumentItem }>("legal.save", {
      slug: draft.slug,
      doc_no: draft.doc_no,
      title: draft.title,
      lead: draft.lead,
      version: draft.version,
      date: draft.date,
      status: draft.status,
      sections: draft.sections,
      is_active: true,
    });
    notifyToast("Legal override saved and revision recorded.", "success");
    const savedSlug = res.document?.slug || draft.slug;
    await load();
    if (isCreateMode.value && savedSlug) {
      navigateToSubRoute("legal", "edit", savedSlug);
    } else {
      syncStudioFromRoute(true);
    }
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Save failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

function requestRevert(slug: string) {
  revertTargetSlug.value = slug;
  revertConfirmOpen.value = true;
}

async function executeRevert() {
  if (!revertTargetSlug.value) return;
  saving.value = true;
  const target = revertTargetSlug.value;
  try {
    await apiPost("legal.delete", { slug: target });
    notifyToast("Restored territory/file legal document.", "success");
    revertConfirmOpen.value = false;
    revertTargetSlug.value = "";
    await load();
    if (inStudio.value && draft.slug === target) {
      const stillExists = documents.value.some((d) => d.slug === target);
      if (stillExists) {
        syncStudioFromRoute(true);
      } else {
        navigateTo("legal");
      }
    }
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Revert failed.", "destructive");
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
        <h2 class="text-xl font-semibold tracking-tight">Legal Registry &amp; Territory Policies</h2>
        <p class="text-sm text-muted-foreground">Owner-governed statutory documents with SQLite runtime override &amp; rollback history.</p>
      </div>
      <Button v-if="isOwner" size="sm" @click="openNew">
        <Plus class="h-4 w-4" aria-hidden="true" />
        <span>New Custom Policy</span>
      </Button>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
      <Card v-for="doc in documents" :key="doc.slug" class="p-5 flex flex-col justify-between gap-4">
        <div class="space-y-2">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-mono text-xs text-muted-foreground">{{ doc.doc_no }} · /legal/{{ doc.slug }}/</span>
            <Badge :variant="doc.source_origin === 'sqlite' ? 'default' : 'outline'">
              {{ doc.source_origin === "sqlite" ? "SQLite Override" : "Territory Default" }}
            </Badge>
          </div>
          <div>
            <h3 class="text-base font-semibold">{{ doc.title }}</h3>
            <p class="mt-1 text-xs text-muted-foreground leading-relaxed">{{ doc.lead }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-3 text-[11px] font-mono text-muted-foreground pt-1">
            <span>Version: {{ doc.version || "1.0.0" }}</span>
            <span>·</span>
            <span>{{ doc.sections?.length || 0 }} clause section(s)</span>
            <span>·</span>
            <span>Updated: {{ doc.date || "—" }}</span>
          </div>
        </div>
        <div v-if="isOwner" class="flex flex-wrap justify-end gap-1.5 pt-3 border-t border-border">
          <Button
            v-if="doc.source_origin === 'sqlite'"
            variant="ghost"
            size="sm"
            :aria-label="`Revision history for /legal/${doc.slug}/`"
            @click="openRevisions(doc.slug)"
          >
            <History class="h-3.5 w-3.5" aria-hidden="true" />
            <span>History</span>
          </Button>
          <Button v-if="doc.source_origin === 'sqlite'" variant="ghost" size="sm" @click="requestRevert(doc.slug)">
            <RotateCcw class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Revert Default</span>
          </Button>
          <Button variant="outline" size="sm" @click="openEdit(doc)">
            <Pencil class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Edit Document</span>
          </Button>
        </div>
      </Card>
    </div>
  </div>

  <!-- FULL-PAGE LEGAL POLICY STUDIO -->
  <div v-else class="space-y-6">
    <div class="sticky -top-4 sm:-top-6 lg:-top-8 z-20 -mx-4 sm:-mx-6 lg:-mx-8 px-4 sm:px-6 lg:px-8 py-3 bg-background/95 backdrop-blur border-b border-border flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-3 min-w-0">
        <Button variant="outline" size="sm" @click="navigateTo('legal')">
          <ArrowLeft class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Back to Policies</span>
        </Button>
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <h2 class="text-base sm:text-lg font-semibold tracking-tight truncate">
              {{ isCreateMode ? "New Custom Policy" : draft.title || draft.slug }}
            </h2>
            <Badge v-if="currentDocRecord" :variant="currentDocRecord.source_origin === 'sqlite' ? 'default' : 'outline'">
              {{ currentDocRecord.source_origin === "sqlite" ? "SQLite Override" : "Territory Default" }}
            </Badge>
          </div>
          <div class="text-xs font-mono text-muted-foreground truncate">
            {{ draft.doc_no || "DOC" }} · /legal/{{ draft.slug || "slug" }}/ · v{{ draft.version }}
          </div>
        </div>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <Button type="button" variant="outline" size="sm" @click="addSection">
          <Plus class="h-3.5 w-3.5" aria-hidden="true" />
          <span>Add Clause Section</span>
        </Button>
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
          v-if="currentDocRecord?.source_origin === 'sqlite'"
          type="button"
          variant="outline"
          size="sm"
          @click="requestRevert(draft.slug)"
        >
          <RotateCcw class="h-3.5 w-3.5 text-amber-600 dark:text-amber-400" aria-hidden="true" />
          <span>Revert Default</span>
        </Button>
        <Button type="submit" form="legal-studio-form" size="sm" :disabled="saving">
          <Save class="h-3.5 w-3.5" aria-hidden="true" />
          <span>{{ saving ? "Saving..." : "Save Legal Document" }}</span>
        </Button>
      </div>
    </div>

    <form id="legal-studio-form" class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start" @submit.prevent="saveDoc">
      <!-- Left Clause Sections Column -->
      <div class="lg:col-span-8 space-y-5 min-w-0">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-sm font-semibold">Statutory Clause Sections ({{ draft.sections.length }})</h3>
            <p class="text-xs text-muted-foreground">Section HTML bodies are server-sanitized on save and recorded into revision history.</p>
          </div>
        </div>

        <Card v-if="draft.sections.length === 0" class="p-8 text-center space-y-3">
          <p class="text-sm text-muted-foreground">No clause sections yet. Add your first clause section below.</p>
          <Button type="button" variant="outline" size="sm" @click="addSection">
            <Plus class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Add First Clause</span>
          </Button>
        </Card>

        <Card
          v-for="(sec, idx) in draft.sections"
          :id="`legal-sec-card-${idx}`"
          :key="`${idx}-${sec.id}`"
          class="p-5 space-y-4 scroll-mt-20"
        >
          <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
            <div class="flex items-center gap-2">
              <Badge variant="outline">Clause {{ idx + 1 }}</Badge>
              <span class="text-sm font-semibold truncate">{{ sec.title || sec.id }}</span>
            </div>
            <div class="flex items-center gap-1">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                class="h-7 w-7"
                :disabled="idx === 0"
                title="Move section up"
                aria-label="Move section up"
                @click="moveSection(idx, -1)"
              >
                <ArrowUp class="h-3.5 w-3.5" aria-hidden="true" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                class="h-7 w-7"
                :disabled="idx === draft.sections.length - 1"
                title="Move section down"
                aria-label="Move section down"
                @click="moveSection(idx, 1)"
              >
                <ArrowDown class="h-3.5 w-3.5" aria-hidden="true" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                class="h-7 w-7"
                title="Remove section"
                aria-label="Remove section"
                @click="draft.sections.splice(idx, 1)"
              >
                <Trash2 class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
              </Button>
            </div>
          </div>

          <div class="grid gap-3 sm:grid-cols-3">
            <div class="space-y-1">
              <label :for="`lg-sec-id-${idx}`" class="text-xs font-medium">Anchor ID</label>
              <Input :id="`lg-sec-id-${idx}`" v-model="sec.id" placeholder="section-id" class="font-mono text-xs" />
            </div>
            <div class="space-y-1 sm:col-span-2">
              <label :for="`lg-sec-title-${idx}`" class="text-xs font-medium">Section Heading</label>
              <Input :id="`lg-sec-title-${idx}`" v-model="sec.title" placeholder="Section heading" />
            </div>
          </div>

          <RichContentEditor
            :id="`lg-sec-body-${idx}`"
            v-model="sec.body"
            mode="html"
            label="Clause Body HTML"
            preview-action="blog.preview"
            default-layout="code"
            min-height="220px"
            max-height="420px"
          />
        </Card>
      </div>

      <!-- Right Sticky Inspector -->
      <div class="lg:col-span-4 space-y-5 lg:sticky lg:top-16 min-w-0">
        <Card class="p-5 space-y-4">
          <h3 class="text-sm font-semibold border-b border-border pb-2.5">Policy Metadata</h3>
          <div class="space-y-3">
            <div class="space-y-1">
              <label for="lg-title" class="text-xs font-medium">Document Title</label>
              <Input id="lg-title" v-model="draft.title" required />
            </div>
            <div class="space-y-1">
              <label for="lg-lead" class="text-xs font-medium">Lead Summary</label>
              <Input id="lg-lead" v-model="draft.lead" />
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
              <div class="space-y-1">
                <label for="lg-slug" class="text-xs font-medium">URL Slug</label>
                <Input
                  id="lg-slug"
                  v-model="draft.slug"
                  pattern="^[a-z0-9-]+$"
                  required
                  class="font-mono text-xs"
                  @input="slugTouched = true"
                />
              </div>
              <div class="space-y-1">
                <label for="lg-no" class="text-xs font-medium">Document ID</label>
                <Input id="lg-no" v-model="draft.doc_no" class="font-mono text-xs" />
              </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
              <div class="space-y-1">
                <label for="lg-ver" class="text-xs font-medium">Version</label>
                <Input id="lg-ver" v-model="draft.version" class="font-mono text-xs" />
              </div>
              <div class="space-y-1">
                <label for="lg-date" class="text-xs font-medium">Effective Date</label>
                <Input id="lg-date" v-model="draft.date" type="date" />
              </div>
            </div>
            <div class="space-y-1">
              <label for="lg-status" class="text-xs font-medium">Policy Status Label</label>
              <Input id="lg-status" v-model="draft.status" placeholder="ACTIVE POLICY" />
            </div>
          </div>
        </Card>

        <Card v-if="draft.sections.length > 0" class="p-5 space-y-3">
          <div class="flex items-center justify-between border-b border-border pb-2.5">
            <h3 class="text-sm font-semibold">Clause Outline</h3>
            <span class="text-[11px] font-mono text-muted-foreground">{{ draft.sections.length }} sections</span>
          </div>
          <nav aria-label="Legal clause outline" class="space-y-1 max-h-60 overflow-y-auto overscroll-contain lumora-scroll-area pr-1">
            <button
              v-for="(sec, idx) in draft.sections"
              :key="`nav-${idx}-${sec.id}`"
              type="button"
              class="w-full text-left rounded px-2.5 py-1.5 text-xs text-muted-foreground hover:bg-accent hover:text-accent-foreground transition-colors truncate cursor-pointer"
              @click="scrollToSection(idx)"
            >
              <span class="font-mono mr-1.5">{{ idx + 1 }}.</span>{{ sec.title || sec.id || "Untitled Clause" }}
            </button>
          </nav>
        </Card>
      </div>
    </form>
  </div>

  <RevisionHistoryModal
    v-model:open="revisionsOpen"
    entity-type="legal"
    :entity-key="revisionKey"
    :can-restore="isOwner"
    @restored="onRevisionRestored"
  />

  <ConfirmDialog
    v-model:open="revertConfirmOpen"
    title="Revert Legal Override?"
    :description="`Remove SQLite override for /legal/${revertTargetSlug}/ and restore territory/file defaults?`"
    confirm-label="Revert Default"
    variant="destructive"
    :loading="saving"
    @confirm="executeRevert"
  />
  </div>
</template>
