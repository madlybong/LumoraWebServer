<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import {
  Bold,
  Code,
  Code2,
  Columns2,
  Eye,
  Heading2,
  Heading3,
  Image as ImageIcon,
  Indent,
  Italic,
  Link2,
  List,
  MessageSquareQuote,
  WrapText,
} from "lucide-vue-next";
import { apiPost } from "../lib/api";
import type { MediaItem } from "../lib/types";
import { computeWordStats } from "../lib/utils";
import MediaPickerModal from "./MediaPickerModal.vue";
import CodeMirrorEditor from "./ui/CodeMirrorEditor.vue";

const content = defineModel<string>({ default: "" });
const mode = defineModel<"html" | "markdown">("mode", { default: "html" });

const props = withDefaults(
  defineProps<{
    id?: string;
    label?: string;
    allowModeSwitch?: boolean;
    previewAction?: "blog.preview" | "docs.preview";
    placeholder?: string;
    minHeight?: string;
    maxHeight?: string;
    defaultLayout?: "split" | "code" | "preview";
    disabled?: boolean;
  }>(),
  {
    id: () => "lumora-editor-" + Math.random().toString(36).slice(2, 8),
    label: "Content",
    allowModeSwitch: false,
    previewAction: "blog.preview",
    placeholder: "",
    minHeight: "420px",
    maxHeight: "640px",
    defaultLayout: "code",
    disabled: false,
  }
);

const editorRef = ref<InstanceType<typeof CodeMirrorEditor> | null>(null);
const toolbarRef = ref<HTMLElement | null>(null);
const viewLayout = ref<"split" | "code" | "preview">(props.defaultLayout);
const wordWrap = ref(true);
const previewLoading = ref(false);
const previewHtml = ref("");
const pickerOpen = ref(false);
let debounceTimer: number | undefined;

const showEditor = computed(() => viewLayout.value === "split" || viewLayout.value === "code");
const showPreview = computed(() => viewLayout.value === "split" || viewLayout.value === "preview");

const editorStats = computed(() => {
  const val = content.value || "";
  const lines = val ? val.split("\n").length : 1;
  const chars = val.length;
  const ws = computeWordStats(val);
  return {
    lines,
    chars,
    words: ws.words,
    minutes: ws.minutes,
  };
});

async function refreshPreview() {
  if (!showPreview.value) return;
  previewLoading.value = true;
  try {
    if (props.previewAction === "docs.preview") {
      const res = await apiPost<{ ok: boolean; html: string }>("docs.preview", {
        markdown: content.value,
      });
      previewHtml.value = res.html || "";
    } else {
      const res = await apiPost<{ ok: boolean; html: string }>("blog.preview", {
        content_html: mode.value === "html" ? content.value : "",
        content_markdown: mode.value === "markdown" ? content.value : "",
        editor_mode: mode.value,
      });
      previewHtml.value = res.html || "";
    }
  } catch {
    previewHtml.value = "<p>Preview unavailable.</p>";
  } finally {
    previewLoading.value = false;
  }
}

watch(
  [content, mode, showPreview],
  () => {
    if (!showPreview.value) return;
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(() => {
      void refreshPreview();
    }, 260);
  },
  { immediate: true }
);

onBeforeUnmount(() => {
  window.clearTimeout(debounceTimer);
});

onMounted(() => {
  const bar = toolbarRef.value;
  if (!bar || "focusGroup" in HTMLElement.prototype) return;

  const buttons = Array.from(bar.querySelectorAll<HTMLButtonElement>("button"));
  buttons.forEach((b, i) => {
    b.tabIndex = i === 0 ? 0 : -1;
  });

  bar.addEventListener("focusin", (e) => {
    const target = e.target as HTMLButtonElement | null;
    if (!target || !buttons.includes(target)) return;
    buttons.forEach((b) => {
      b.tabIndex = b === target ? 0 : -1;
    });
  });

  bar.addEventListener("keydown", (e: KeyboardEvent) => {
    const active = document.activeElement as HTMLButtonElement | null;
    const idx = active ? buttons.indexOf(active) : -1;
    if (idx === -1) return;

    const rtl = getComputedStyle(bar).direction === "rtl";
    const stepMap: Record<string, number> = {
      ArrowRight: rtl ? -1 : 1,
      ArrowLeft: rtl ? 1 : -1,
    };

    let next = -1;
    if (e.key in stepMap) {
      next = (idx + stepMap[e.key] + buttons.length) % buttons.length;
    } else if (e.key === "Home") {
      next = 0;
    } else if (e.key === "End") {
      next = buttons.length - 1;
    }
    if (next !== -1) {
      e.preventDefault();
      buttons[next]?.focus();
    }
  });
});

function replaceSelection(before: string, after: string, fallbackPlaceholder: string) {
  if (props.disabled) return;
  if (editorRef.value) {
    editorRef.value.insertSnippet(before, after, fallbackPlaceholder);
    return;
  }
  content.value = `${content.value}${before}${fallbackPlaceholder}${after}`;
}

function formatAction(kind: "h2" | "h3" | "bold" | "italic" | "code" | "callout" | "list" | "codeblock" | "link") {
  if (props.disabled) return;
  const md = mode.value === "markdown";
  switch (kind) {
    case "h2":
      return replaceSelection(md ? "## " : "<h2>", md ? "\n" : "</h2>\n", "Section Heading");
    case "h3":
      return replaceSelection(md ? "### " : "<h3>", md ? "\n" : "</h3>\n", "Subheading");
    case "bold":
      return replaceSelection(md ? "**" : "<strong>", md ? "**" : "</strong>", "bold text");
    case "italic":
      return replaceSelection(md ? "*" : "<em>", md ? "*" : "</em>", "italic text");
    case "code":
      return replaceSelection(md ? "`" : "<code>", md ? "`" : "</code>", "snippet");
    case "callout":
      return replaceSelection(md ? "> " : "<blockquote><p>", md ? "\n" : "</p></blockquote>\n", "Key takeaway or callout note");
    case "list":
      return replaceSelection(md ? "- " : "<ul>\n  <li>", md ? "\n" : "</li>\n</ul>\n", "List item");
    case "codeblock":
      return replaceSelection(md ? "\n```\n" : "<pre><code>", md ? "\n```\n" : "</code></pre>\n", "code here");
    case "link":
      return replaceSelection(md ? "[" : '<a href="https://example.com">', md ? "](https://example.com)" : "</a>", "link label");
  }
}

function handleInsertMedia(item: MediaItem) {
  const alt = (item.alt_text || item.filename || "Image").replace(/"/g, "&quot;");
  const src = item.public_path || item.url;
  if (mode.value === "markdown") {
    replaceSelection(`\n![${item.alt_text || item.filename}](${src})\n`, "", "");
  } else {
    const wAttr = item.width ? ` width="${item.width}"` : "";
    const hAttr = item.height ? ` height="${item.height}"` : "";
    replaceSelection(`<figure><img src="${src}" alt="${alt}"${wAttr}${hAttr} loading="lazy" /></figure>\n`, "", "");
  }
}
</script>

<template>
  <div class="space-y-1.5">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <label :for="props.id" class="text-xs font-medium text-foreground">{{ props.label }}</label>
      <div class="flex flex-wrap items-center gap-2">
        <div v-if="props.allowModeSwitch" class="inline-flex rounded-md border border-border p-0.5 text-[11px] bg-background">
          <button
            type="button"
            :class="[
              'rounded px-2 py-0.5 cursor-pointer',
              mode === 'html' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
            ]"
            @click="mode = 'html'"
          >
            HTML
          </button>
          <button
            type="button"
            :class="[
              'rounded px-2 py-0.5 cursor-pointer',
              mode === 'markdown' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
            ]"
            @click="mode = 'markdown'"
          >
            Markdown
          </button>
        </div>

        <div class="inline-flex rounded-md border border-border p-0.5 text-[11px] bg-background" role="group" aria-label="Editor layout">
          <button
            type="button"
            :class="[
              'inline-flex items-center gap-1 rounded px-2 py-0.5 cursor-pointer',
              viewLayout === 'split' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
            ]"
            title="Side-by-side Code and Live Preview"
            @click="viewLayout = 'split'"
          >
            <Columns2 class="h-3 w-3" aria-hidden="true" />
            <span>Split</span>
          </button>
          <button
            type="button"
            :class="[
              'inline-flex items-center gap-1 rounded px-2 py-0.5 cursor-pointer',
              viewLayout === 'code' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
            ]"
            title="Code editor only"
            @click="viewLayout = 'code'"
          >
            <Code2 class="h-3 w-3" aria-hidden="true" />
            <span>Code</span>
          </button>
          <button
            type="button"
            :class="[
              'inline-flex items-center gap-1 rounded px-2 py-0.5 cursor-pointer',
              viewLayout === 'preview' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground',
            ]"
            title="Sanitized preview only"
            @click="viewLayout = 'preview'"
          >
            <Eye class="h-3 w-3" aria-hidden="true" />
            <span>Preview</span>
          </button>
        </div>
      </div>
    </div>

    <div class="rounded-lg border border-border overflow-hidden bg-background">
      <div
        v-if="showEditor"
        ref="toolbarRef"
        role="toolbar"
        focusgroup="toolbar wrap"
        aria-label="Content formatting"
        :aria-controls="props.id"
        class="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-muted/40 px-2 py-1.5"
      >
        <div class="flex flex-wrap items-center gap-1">
          <div role="group" aria-label="Text style" class="flex items-center gap-0.5">
            <button type="button" class="min-w-7 min-h-7 inline-flex items-center justify-center rounded hover:bg-accent cursor-pointer" aria-label="Insert Heading 2" title="Heading 2" :aria-disabled="props.disabled" @click="formatAction('h2')">
              <Heading2 class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <button type="button" class="min-w-7 min-h-7 inline-flex items-center justify-center rounded hover:bg-accent cursor-pointer" aria-label="Insert Heading 3" title="Heading 3" :aria-disabled="props.disabled" @click="formatAction('h3')">
              <Heading3 class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <button type="button" class="min-w-7 min-h-7 inline-flex items-center justify-center rounded hover:bg-accent cursor-pointer" aria-label="Bold" title="Bold" :aria-disabled="props.disabled" @click="formatAction('bold')">
              <Bold class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <button type="button" class="min-w-7 min-h-7 inline-flex items-center justify-center rounded hover:bg-accent cursor-pointer" aria-label="Italic" title="Italic" :aria-disabled="props.disabled" @click="formatAction('italic')">
              <Italic class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <button type="button" class="min-w-7 min-h-7 inline-flex items-center justify-center rounded hover:bg-accent cursor-pointer" aria-label="Inline Code" title="Inline Code" :aria-disabled="props.disabled" @click="formatAction('code')">
              <Code class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </div>

          <span role="separator" aria-orientation="vertical" class="mx-1 h-5 border-l border-border"></span>

          <div role="group" aria-label="Structure and links" class="flex items-center gap-0.5">
            <button type="button" class="min-w-7 min-h-7 inline-flex items-center justify-center rounded hover:bg-accent cursor-pointer" aria-label="Callout Box" title="Callout / Blockquote" :aria-disabled="props.disabled" @click="formatAction('callout')">
              <MessageSquareQuote class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <button type="button" class="min-w-7 min-h-7 inline-flex items-center justify-center rounded hover:bg-accent cursor-pointer" aria-label="Bullet List" title="Bullet List" :aria-disabled="props.disabled" @click="formatAction('list')">
              <List class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <button type="button" class="min-w-7 min-h-7 inline-flex items-center justify-center rounded hover:bg-accent cursor-pointer" aria-label="Code Block" title="Code Block" :aria-disabled="props.disabled" @click="formatAction('codeblock')">
              <Code2 class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
            <button type="button" class="min-w-7 min-h-7 inline-flex items-center justify-center rounded hover:bg-accent cursor-pointer" aria-label="Insert Link" title="Insert Link" :aria-disabled="props.disabled" @click="formatAction('link')">
              <Link2 class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </div>

          <span role="separator" aria-orientation="vertical" class="mx-1 h-5 border-l border-border"></span>

          <button
            type="button"
            class="min-h-7 inline-flex items-center gap-1 rounded px-2 text-xs font-medium hover:bg-accent cursor-pointer"
            aria-label="Insert Media Asset"
            title="Insert Media Asset"
            :aria-disabled="props.disabled"
            @click="!props.disabled && (pickerOpen = true)"
          >
            <ImageIcon class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Media</span>
          </button>
        </div>

        <div class="flex items-center gap-1">
          <button
            v-if="mode === 'html'"
            type="button"
            class="min-h-7 inline-flex items-center gap-1 rounded px-2 text-xs text-muted-foreground hover:text-foreground hover:bg-accent cursor-pointer"
            title="Format and indent HTML markup"
            :aria-disabled="props.disabled"
            @click="!props.disabled && editorRef?.formatHtml()"
          >
            <Indent class="h-3.5 w-3.5" aria-hidden="true" />
            <span class="hidden sm:inline">Format HTML</span>
          </button>
          <button
            type="button"
            :class="[
              'min-h-7 inline-flex items-center gap-1 rounded px-2 text-xs cursor-pointer',
              wordWrap ? 'bg-accent text-accent-foreground font-medium' : 'text-muted-foreground hover:text-foreground hover:bg-accent',
            ]"
            title="Toggle soft line wrapping"
            :aria-pressed="wordWrap"
            @click="wordWrap = !wordWrap"
          >
            <WrapText class="h-3.5 w-3.5" aria-hidden="true" />
            <span class="hidden sm:inline">Wrap</span>
          </button>
        </div>
      </div>

      <div :class="viewLayout === 'split' ? 'grid grid-cols-1 xl:grid-cols-2 divide-y xl:divide-y-0 xl:divide-x divide-border' : 'block'">
        <div v-if="showEditor" class="min-w-0">
          <CodeMirrorEditor
            :id="props.id"
            ref="editorRef"
            v-model="content"
            :language="mode"
            :placeholder="props.placeholder"
            :min-height="props.minHeight"
            :max-height="props.maxHeight"
            :word-wrap="wordWrap"
            :read-only="props.disabled"
            class="border-0 rounded-none focus-within:ring-0"
          />
        </div>

        <div
          v-if="showPreview"
          :style="{ minHeight: props.minHeight, maxHeight: props.maxHeight }"
          class="bg-card p-4 overflow-y-auto overscroll-contain lumora-scroll-area min-w-0"
        >
          <div class="flex items-center justify-between text-[11px] font-medium uppercase tracking-wider text-muted-foreground mb-3 pb-2 border-b border-border">
            <span>Sanitized Live Preview</span>
            <span v-if="previewLoading" class="text-primary">Rendering...</span>
          </div>
          <div class="lumora-prose text-sm" v-html="previewHtml" />
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border bg-muted/30 px-3 py-1.5 text-[11px] font-mono text-muted-foreground">
        <span>{{ editorStats.lines }} lines · {{ editorStats.words }} words · {{ editorStats.chars }} chars · ~{{ editorStats.minutes }} min read</span>
        <span>{{ mode.toUpperCase() }} · Ctrl+F Search</span>
      </div>
    </div>

    <MediaPickerModal v-model:open="pickerOpen" title="Insert Image from Media Library" @select="handleInsertMedia" />
  </div>
</template>
