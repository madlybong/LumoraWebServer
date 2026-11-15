<script setup lang="ts">
import { onMounted, reactive, ref } from "vue";
import { Image as ImageIcon, Plus, RefreshCw, Save, Trash2 } from "lucide-vue-next";
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from "reka-ui";
import { apiGet, apiPost } from "../lib/api";
import type { MediaItem, RouteSeoItem } from "../lib/types";
import { isEditor, notifyToast } from "../stores/admin";
import MediaPickerModal from "../components/MediaPickerModal.vue";
import Badge from "../components/ui/Badge.vue";
import Button from "../components/ui/Button.vue";
import Card from "../components/ui/Card.vue";
import CodeMirrorEditor from "../components/ui/CodeMirrorEditor.vue";
import ConfirmDialog from "../components/ui/ConfirmDialog.vue";
import Input from "../components/ui/Input.vue";

const preview = ref<Record<string, string>>({});
const routeSeo = ref<RouteSeoItem[]>([]);
const loading = ref(false);
const saving = ref(false);
const activeTab = ref("sitemap_xml");
const activeOverrideTab = ref("llms_txt");
const pickerOpen = ref(false);

const deleteConfirmOpen = ref(false);
const deleteTargetPath = ref("");

const overrides = reactive({
  llms_txt: "",
  llms_full_txt: "",
  security_txt: "",
  robots_extra: "",
});

const seoForm = reactive({
  route_path: "/",
  meta_title: "",
  meta_description: "",
  og_image: "",
  robots: "index, follow",
});

const tabs = [
  { key: "sitemap_xml", label: "sitemap.xml" },
  { key: "robots_txt", label: "robots.txt" },
  { key: "llms_txt", label: "llms.txt" },
  { key: "llms_full_txt", label: "llms-full.txt" },
  { key: "security_txt", label: "security.txt" },
  { key: "manifest_json", label: "site.webmanifest" },
];

const overrideTabs = [
  { key: "llms_txt", label: "llms.txt", hint: "Markdown summary served at /llms.txt for AI indexers." },
  { key: "llms_full_txt", label: "llms-full.txt", hint: "Extended documentation & specification corpus at /llms-full.txt." },
  { key: "security_txt", label: "security.txt", hint: "RFC 9116 vulnerability disclosure policy at /.well-known/security.txt." },
  { key: "robots_extra", label: "robots.txt extra", hint: "Additional crawler Allow/Disallow directives appended to generated robots.txt." },
] as const;

async function load() {
  loading.value = true;
  try {
    const [prevRes, settingsRes] = await Promise.all([
      apiGet<Record<string, string>>("discovery.preview"),
      apiGet<{ settings: Record<string, string>; route_seo: RouteSeoItem[] }>("discovery.settings-get"),
    ]);
    preview.value = prevRes || {};
    if (settingsRes?.settings) {
      overrides.llms_txt = settingsRes.settings.llms_txt || "";
      overrides.llms_full_txt = settingsRes.settings.llms_full_txt || "";
      overrides.security_txt = settingsRes.settings.security_txt || "";
      overrides.robots_extra = settingsRes.settings.robots_extra || "";
    }
    routeSeo.value = settingsRes?.route_seo || [];
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load discovery configuration.", "destructive");
  } finally {
    loading.value = false;
  }
}

async function saveOverrides() {
  saving.value = true;
  try {
    await apiPost("discovery.settings-save", {
      llms_txt: overrides.llms_txt,
      llms_full_txt: overrides.llms_full_txt,
      security_txt: overrides.security_txt,
      robots_extra: overrides.robots_extra,
    });
    notifyToast("Discovery overrides saved & compiled.", "success");
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to save discovery overrides.", "destructive");
  } finally {
    saving.value = false;
  }
}

async function saveRouteSeo() {
  saving.value = true;
  try {
    await apiPost("discovery.route-seo-save", seoForm);
    notifyToast(`SEO override saved for ${seoForm.route_path}.`, "success");
    seoForm.route_path = "/";
    seoForm.meta_title = "";
    seoForm.meta_description = "";
    seoForm.og_image = "";
    seoForm.robots = "index, follow";
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to save route SEO.", "destructive");
  } finally {
    saving.value = false;
  }
}

function editRouteSeo(item: RouteSeoItem) {
  Object.assign(seoForm, {
    route_path: item.route_path,
    meta_title: item.meta_title || "",
    meta_description: item.meta_description || "",
    og_image: item.og_image || "",
    robots: item.robots || "index, follow",
  });
}

function requestDeleteSeo(routePath: string) {
  deleteTargetPath.value = routePath;
  deleteConfirmOpen.value = true;
}

async function executeDeleteSeo() {
  if (!deleteTargetPath.value) return;
  saving.value = true;
  try {
    await apiPost("discovery.route-seo-delete", { route_path: deleteTargetPath.value });
    notifyToast(`Removed SEO override for ${deleteTargetPath.value}.`, "success");
    deleteConfirmOpen.value = false;
    deleteTargetPath.value = "";
    await load();
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Delete failed.", "destructive");
  } finally {
    saving.value = false;
  }
}

function onOgPicked(item: MediaItem) {
  seoForm.og_image = item.public_path || item.url;
}

onMounted(load);
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold tracking-tight">Discovery, SEO &amp; AI Feeds</h2>
        <p class="text-sm text-muted-foreground">Live compiled feeds, CodeMirror-powered runtime overrides for AI/crawler directives, and per-route OpenGraph metadata.</p>
      </div>
      <Button variant="outline" size="sm" :disabled="loading" @click="load">
        <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
        <span>Refresh</span>
      </Button>
    </div>

    <div class="grid gap-6 xl:grid-cols-12 items-start">
      <!-- Left: Runtime CodeMirror Overrides -->
      <Card v-if="isEditor" class="xl:col-span-7 overflow-hidden">
        <div class="px-5 py-4 border-b border-border">
          <h3 class="text-sm font-semibold">Custom Feed &amp; Crawler Overrides</h3>
          <p class="text-xs text-muted-foreground">Leave any directive blank to fall back cleanly to dynamically compiled output.</p>
        </div>

        <form id="discovery-overrides-form" class="p-5 space-y-4" @submit.prevent="saveOverrides">
          <TabsRoot v-model="activeOverrideTab">
            <TabsList class="flex flex-wrap gap-1 border-b border-border pb-3 mb-3" aria-label="Directive override editors">
              <TabsTrigger
                v-for="tab in overrideTabs"
                :key="tab.key"
                :value="tab.key"
                class="rounded-md px-3 py-1.5 text-xs font-mono transition-colors data-[state=active]:bg-primary data-[state=active]:text-primary-foreground text-muted-foreground hover:bg-muted cursor-pointer"
              >
                {{ tab.label }}
                <span
                  v-if="overrides[tab.key]?.trim()"
                  class="ml-1.5 inline-block h-1.5 w-1.5 rounded-full bg-emerald-400"
                />
              </TabsTrigger>
            </TabsList>

            <TabsContent value="llms_txt" class="space-y-2">
              <div class="text-xs text-muted-foreground">{{ overrideTabs[0].hint }}</div>
              <CodeMirrorEditor
                id="disc-llms"
                v-model="overrides.llms_txt"
                language="markdown"
                placeholder="# Brand Summary&#10;&#10;> Concise overview for LLM agents..."
                min-height="260px"
                max-height="420px"
              />
            </TabsContent>

            <TabsContent value="llms_full_txt" class="space-y-2">
              <div class="text-xs text-muted-foreground">{{ overrideTabs[1].hint }}</div>
              <CodeMirrorEditor
                id="disc-llms-full"
                v-model="overrides.llms_full_txt"
                language="markdown"
                placeholder="# Full Technical Corpus..."
                min-height="260px"
                max-height="420px"
              />
            </TabsContent>

            <TabsContent value="security_txt" class="space-y-2">
              <div class="text-xs text-muted-foreground">{{ overrideTabs[2].hint }}</div>
              <CodeMirrorEditor
                id="disc-sec"
                v-model="overrides.security_txt"
                language="plain"
                placeholder="Contact: mailto:security@example.com&#10;Preferred-Languages: en"
                min-height="260px"
                max-height="420px"
              />
            </TabsContent>

            <TabsContent value="robots_extra" class="space-y-2">
              <div class="text-xs text-muted-foreground">{{ overrideTabs[3].hint }}</div>
              <CodeMirrorEditor
                id="disc-robots"
                v-model="overrides.robots_extra"
                language="plain"
                placeholder="User-agent: GPTBot&#10;Allow: /"
                min-height="260px"
                max-height="420px"
              />
            </TabsContent>
          </TabsRoot>
        </form>

        <div class="px-5 py-3 border-t border-border bg-muted/30 flex items-center justify-between gap-2">
          <span class="text-xs text-muted-foreground font-mono">Ctrl+F search supported inside editor</span>
          <Button type="submit" form="discovery-overrides-form" size="sm" :disabled="saving">
            <Save class="h-3.5 w-3.5" aria-hidden="true" />
            <span>{{ saving ? "Saving..." : "Save Discovery Overrides" }}</span>
          </Button>
        </div>
      </Card>

      <!-- Right: Live Compiled Output Inspector -->
      <Card :class="[isEditor ? 'xl:col-span-5' : 'xl:col-span-12', 'overflow-hidden']">
        <div class="px-5 py-4 border-b border-border">
          <h3 class="text-sm font-semibold">Live Compiled Output</h3>
          <p class="text-xs text-muted-foreground">Exact payload served to search crawlers and AI retrievers right now.</p>
        </div>
        <div class="p-5">
          <TabsRoot v-model="activeTab">
            <TabsList class="flex flex-wrap gap-1 border-b border-border pb-3 mb-3" aria-label="Discovery endpoints">
              <TabsTrigger
                v-for="tab in tabs"
                :key="tab.key"
                :value="tab.key"
                class="rounded-md px-2.5 py-1 text-xs font-mono transition-colors data-[state=active]:bg-primary data-[state=active]:text-primary-foreground text-muted-foreground hover:bg-muted cursor-pointer"
              >
                {{ tab.label }}
              </TabsTrigger>
            </TabsList>

            <TabsContent v-for="tab in tabs" :key="tab.key" :value="tab.key">
              <pre class="h-[310px] overflow-auto overscroll-contain lumora-scroll-area rounded-md border border-border bg-muted/40 p-3.5 text-xs font-mono whitespace-pre-wrap">{{ preview[tab.key] || "(empty or disabled in lumora.config.php)" }}</pre>
            </TabsContent>
          </TabsRoot>
        </div>
      </Card>
    </div>

    <Card class="overflow-hidden">
      <div class="px-5 py-4 border-b border-border">
        <h3 class="text-sm font-semibold">Per-Route SEO &amp; OpenGraph Overrides</h3>
        <p class="text-xs text-muted-foreground">Override <code class="font-mono">&lt;title&gt;</code>, description, OG image, or robots directives on specific static routes.</p>
      </div>

      <form v-if="isEditor" id="route-seo-form" class="p-5 space-y-4 border-b border-border" @submit.prevent="saveRouteSeo">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div class="space-y-1">
            <label for="rseo-path" class="text-xs font-medium">Route Path</label>
            <Input id="rseo-path" v-model="seoForm.route_path" required placeholder="/contact/" class="font-mono text-xs" />
          </div>
          <div class="space-y-1">
            <label for="rseo-robots" class="text-xs font-medium">Robots Directive</label>
            <Input id="rseo-robots" v-model="seoForm.robots" placeholder="index, follow" class="font-mono text-xs" />
          </div>
          <div class="space-y-1 sm:col-span-2">
            <label for="rseo-title" class="text-xs font-medium">Meta Title</label>
            <Input id="rseo-title" v-model="seoForm.meta_title" placeholder="Custom SEO page title" />
          </div>
          <div class="space-y-1 sm:col-span-2">
            <label for="rseo-desc" class="text-xs font-medium">Meta Description</label>
            <Input id="rseo-desc" v-model="seoForm.meta_description" placeholder="120-160 char meta description" />
          </div>
          <div class="space-y-1 sm:col-span-2">
            <label for="rseo-og" class="text-xs font-medium">OpenGraph Image</label>
            <div class="flex gap-1.5">
              <Input id="rseo-og" v-model="seoForm.og_image" placeholder="/public/uploads/og.webp" class="font-mono text-xs" />
              <Button type="button" variant="outline" size="sm" aria-label="Pick OG image" @click="pickerOpen = true">
                <ImageIcon class="h-3.5 w-3.5" aria-hidden="true" />
              </Button>
            </div>
          </div>
        </div>
        <div class="flex justify-end">
          <Button type="submit" size="sm" :disabled="saving">
            <Plus class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Save Route SEO</span>
          </Button>
        </div>
      </form>

      <div class="p-5">
        <div v-if="routeSeo.length === 0" class="text-xs text-muted-foreground">No per-route SEO overrides configured yet.</div>
        <div v-else class="divide-y divide-border text-xs">
          <div v-for="item in routeSeo" :key="item.route_path" class="py-2.5 flex flex-wrap items-center justify-between gap-2">
            <div class="space-y-0.5 min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono font-semibold">{{ item.route_path }}</span>
                <Badge variant="outline">{{ item.robots }}</Badge>
              </div>
              <div class="text-muted-foreground truncate">{{ item.meta_title || "(default title)" }}</div>
            </div>
            <div v-if="isEditor" class="flex items-center gap-1 shrink-0">
              <Button variant="ghost" size="sm" @click="editRouteSeo(item)">Edit</Button>
              <Button variant="ghost" size="sm" :aria-label="`Delete SEO override for ${item.route_path}`" @click="requestDeleteSeo(item.route_path)">
                <Trash2 class="h-3.5 w-3.5 text-destructive" aria-hidden="true" />
              </Button>
            </div>
          </div>
        </div>
      </div>
    </Card>

    <MediaPickerModal v-model:open="pickerOpen" title="Select OpenGraph Image" @select="onOgPicked" />

    <ConfirmDialog
      v-model:open="deleteConfirmOpen"
      title="Remove Route SEO Override?"
      :description="`Delete custom SEO metadata override for route ${deleteTargetPath}?`"
      confirm-label="Remove Override"
      variant="destructive"
      :loading="saving"
      @confirm="executeDeleteSeo"
    />
  </div>
</template>
