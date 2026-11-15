<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import { EditorView, keymap, lineNumbers, highlightActiveLineGutter, highlightSpecialChars, drawSelection, highlightActiveLine, placeholder as cmPlaceholder } from "@codemirror/view";
import { Compartment, EditorState, type Extension } from "@codemirror/state";
import { defaultKeymap, history, historyKeymap, indentWithTab } from "@codemirror/commands";
import { searchKeymap, highlightSelectionMatches } from "@codemirror/search";
import { autocompletion, closeBrackets, closeBracketsKeymap } from "@codemirror/autocomplete";
import { bracketMatching, foldGutter, foldKeymap, indentOnInput, syntaxHighlighting, HighlightStyle } from "@codemirror/language";
import { html } from "@codemirror/lang-html";
import { markdown } from "@codemirror/lang-markdown";
import { tags } from "@lezer/highlight";
import { adminStore } from "../../stores/admin";

const modelValue = defineModel<string>({ default: "" });

const props = withDefaults(
  defineProps<{
    id?: string;
    language?: "html" | "markdown" | "plain";
    placeholder?: string;
    minHeight?: string;
    maxHeight?: string;
    wordWrap?: boolean;
    readOnly?: boolean;
  }>(),
  {
    id: undefined,
    language: "html",
    placeholder: "",
    minHeight: "320px",
    maxHeight: "640px",
    wordWrap: true,
    readOnly: false,
  }
);

const hostRef = ref<HTMLElement | null>(null);
let view: EditorView | null = null;
let syncingFromModel = false;

const languageCompartment = new Compartment();
const wrapCompartment = new Compartment();
const readOnlyCompartment = new Compartment();
const themeCompartment = new Compartment();
const placeholderCompartment = new Compartment();

function buildLanguageExtension(lang: "html" | "markdown" | "plain"): Extension {
  if (lang === "html") {
    return html({ autoCloseTags: true, matchClosingTags: true });
  }
  if (lang === "markdown") {
    return markdown();
  }
  return [];
}

function buildThemeExtensions(dark: boolean): Extension {
  const editorTheme = EditorView.theme(
    {
      "&": {
        backgroundColor: "var(--background)",
        color: "var(--foreground)",
        fontSize: "13px",
        fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace",
        minHeight: props.minHeight,
        maxHeight: props.maxHeight,
      },
      ".cm-scroller": {
        overflow: "auto",
        overscrollBehavior: "contain",
        scrollbarWidth: "thin",
        scrollbarColor: "color-mix(in oklab, var(--muted-foreground) 35%, transparent) transparent",
        lineHeight: "1.6",
      },
      ".cm-content": {
        padding: "10px 0",
        caretColor: "var(--foreground)",
      },
      ".cm-line": {
        paddingInline: "12px",
      },
      ".cm-gutters": {
        backgroundColor: "color-mix(in oklab, var(--muted) 55%, var(--background))",
        color: "var(--muted-foreground)",
        borderRight: "1px solid var(--border)",
        minWidth: "42px",
      },
      ".cm-lineNumbers .cm-gutterElement": {
        paddingInline: "8px 6px",
        fontSize: "11px",
      },
      ".cm-activeLine": {
        backgroundColor: dark ? "rgba(255, 255, 255, 0.04)" : "rgba(0, 0, 0, 0.03)",
      },
      ".cm-activeLineGutter": {
        backgroundColor: dark ? "rgba(255, 255, 255, 0.07)" : "rgba(0, 0, 0, 0.05)",
        color: "var(--foreground)",
      },
      "&.cm-focused .cm-selectionBackground, ::selection": {
        backgroundColor: dark ? "rgba(59, 130, 246, 0.30)" : "rgba(37, 99, 235, 0.18)",
      },
      ".cm-selectionMatch": {
        backgroundColor: dark ? "rgba(59, 130, 246, 0.18)" : "rgba(37, 99, 235, 0.12)",
      },
      ".cm-cursor, .cm-dropCursor": {
        borderLeftColor: "var(--foreground)",
      },
      ".cm-panels": {
        backgroundColor: "var(--card)",
        color: "var(--foreground)",
        borderTop: "1px solid var(--border)",
        borderBottom: "1px solid var(--border)",
      },
      ".cm-textfield": {
        backgroundColor: "var(--background)",
        color: "var(--foreground)",
        border: "1px solid var(--input)",
        borderRadius: "4px",
      },
      ".cm-button": {
        backgroundImage: "none",
        backgroundColor: "var(--secondary)",
        color: "var(--secondary-foreground)",
        border: "1px solid var(--border)",
        borderRadius: "4px",
      },
      ".cm-tooltip": {
        backgroundColor: "var(--popover)",
        color: "var(--popover-foreground)",
        border: "1px solid var(--border)",
      },
      "&.cm-focused": {
        outline: "none",
      },
    },
    { dark }
  );

  const highlight = HighlightStyle.define([
    { tag: tags.tagName, color: dark ? "#60a5fa" : "#2563eb", fontWeight: "600" },
    { tag: tags.angleBracket, color: dark ? "#9ca3af" : "#6b7280" },
    { tag: tags.attributeName, color: dark ? "#c084fc" : "#7c3aed" },
    { tag: tags.attributeValue, color: dark ? "#34d399" : "#059669" },
    { tag: tags.string, color: dark ? "#34d399" : "#059669" },
    { tag: tags.comment, color: dark ? "#6b7280" : "#9ca3af", fontStyle: "italic" },
    { tag: tags.keyword, color: dark ? "#f472b6" : "#db2777", fontWeight: "600" },
    { tag: tags.heading, color: dark ? "#60a5fa" : "#1d4ed8", fontWeight: "700" },
    { tag: tags.strong, fontWeight: "700" },
    { tag: tags.emphasis, fontStyle: "italic" },
    { tag: tags.link, color: dark ? "#38bdf8" : "#0284c7", textDecoration: "underline" },
    { tag: tags.url, color: dark ? "#38bdf8" : "#0284c7" },
    { tag: tags.monospace, color: dark ? "#fbbf24" : "#b45309" },
    { tag: tags.meta, color: dark ? "#94a3b8" : "#64748b" },
  ]);

  return [editorTheme, syntaxHighlighting(highlight)];
}

function createEditor() {
  if (!hostRef.value) return;

  const state = EditorState.create({
    doc: modelValue.value || "",
    extensions: [
      lineNumbers(),
      highlightActiveLineGutter(),
      highlightSpecialChars(),
      history(),
      foldGutter(),
      drawSelection(),
      EditorState.allowMultipleSelections.of(true),
      indentOnInput(),
      bracketMatching(),
      closeBrackets(),
      autocompletion(),
      highlightActiveLine(),
      highlightSelectionMatches(),
      keymap.of([
        ...closeBracketsKeymap,
        ...defaultKeymap,
        ...searchKeymap,
        ...historyKeymap,
        ...foldKeymap,
        indentWithTab,
      ]),
      languageCompartment.of(buildLanguageExtension(props.language)),
      wrapCompartment.of(props.wordWrap ? EditorView.lineWrapping : []),
      readOnlyCompartment.of([
        EditorState.readOnly.of(props.readOnly),
        EditorView.editable.of(!props.readOnly),
      ]),
      placeholderCompartment.of(props.placeholder ? cmPlaceholder(props.placeholder) : []),
      themeCompartment.of(buildThemeExtensions(adminStore.resolvedDark)),
      EditorView.updateListener.of((update) => {
        if (update.docChanged && !syncingFromModel) {
          modelValue.value = update.state.doc.toString();
        }
      }),
    ],
  });

  view = new EditorView({
    state,
    parent: hostRef.value,
  });

  if (props.id) {
    view.contentDOM.setAttribute("id", props.id);
  }
}

function insertSnippet(before: string, after = "", fallbackPlaceholder = "") {
  if (!view || props.readOnly) return;
  const sel = view.state.selection.main;
  const selectedText = view.state.sliceDoc(sel.from, sel.to) || fallbackPlaceholder;
  const insertTextStr = `${before}${selectedText}${after}`;
  const anchor = sel.from + before.length;
  const head = anchor + selectedText.length;

  view.dispatch({
    changes: { from: sel.from, to: sel.to, insert: insertTextStr },
    selection: { anchor, head },
  });
  view.focus();
}

function insertText(text: string) {
  insertSnippet(text, "", "");
}

function formatHtml() {
  if (!view || props.readOnly) return;
  const raw = view.state.doc.toString().trim();
  if (!raw) return;

  // Lightweight deterministic block formatter without external prettier runtime
  const tokens = raw.replace(/>\s+</g, ">\n<").split("\n");
  const voidOrInline = /^(?:<\/?(?:strong|em|b|i|code|a|span|small|br|hr|img|input)\b|<\/[^>]+>$)/i;
  const closingOnly = /^<\/([a-z0-9-]+)>/i;
  const openingBlock = /^<(p|div|section|article|aside|ul|ol|li|blockquote|pre|figure|table|thead|tbody|tr|th|td|h[1-6])\b[^>]*>$/i;
  const selfClosingOrComplete = /^<([a-z0-9-]+)\b[^>]*>.*<\/\1>$/i;

  let indent = 0;
  const out: string[] = [];
  for (const rawLine of tokens) {
    const line = rawLine.trim();
    if (!line) continue;
    if (closingOnly.test(line) && !selfClosingOrComplete.test(line)) {
      indent = Math.max(0, indent - 1);
    }
    out.push("  ".repeat(indent) + line);
    if (
      openingBlock.test(line) &&
      !selfClosingOrComplete.test(line) &&
      !line.endsWith("/>") &&
      !voidOrInline.test(line)
    ) {
      indent += 1;
    }
  }
  const formatted = out.join("\n");
  view.dispatch({
    changes: { from: 0, to: view.state.doc.length, insert: formatted },
  });
  modelValue.value = formatted;
  view.focus();
}

function focus() {
  view?.focus();
}

defineExpose({
  insertSnippet,
  insertText,
  formatHtml,
  focus,
});

watch(modelValue, (next) => {
  if (!view) return;
  const current = view.state.doc.toString();
  if (next === current) return;
  syncingFromModel = true;
  view.dispatch({
    changes: { from: 0, to: current.length, insert: next || "" },
  });
  syncingFromModel = false;
});

watch(
  () => props.language,
  (lang) => {
    view?.dispatch({
      effects: languageCompartment.reconfigure(buildLanguageExtension(lang)),
    });
  }
);

watch(
  () => props.wordWrap,
  (wrap) => {
    view?.dispatch({
      effects: wrapCompartment.reconfigure(wrap ? EditorView.lineWrapping : []),
    });
  }
);

watch(
  () => props.readOnly,
  (ro) => {
    view?.dispatch({
      effects: readOnlyCompartment.reconfigure([
        EditorState.readOnly.of(ro),
        EditorView.editable.of(!ro),
      ]),
    });
  }
);

watch(
  () => props.placeholder,
  (ph) => {
    view?.dispatch({
      effects: placeholderCompartment.reconfigure(ph ? cmPlaceholder(ph) : []),
    });
  }
);

watch(
  () => adminStore.resolvedDark,
  (dark) => {
    view?.dispatch({
      effects: themeCompartment.reconfigure(buildThemeExtensions(dark)),
    });
  }
);

onMounted(createEditor);

onBeforeUnmount(() => {
  view?.destroy();
  view = null;
});
</script>

<template>
  <div
    ref="hostRef"
    class="lumora-cm-wrapper w-full overflow-hidden rounded-md border border-input bg-background focus-within:ring-2 focus-within:ring-ring"
  />
</template>
