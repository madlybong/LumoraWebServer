<script setup lang="ts">
import { ref, watch } from "vue";
import { History, RotateCcw } from "lucide-vue-next";
import { apiGet, apiPost } from "../lib/api";
import type { RevisionItem } from "../lib/types";
import { formatDateTime } from "../lib/utils";
import { notifyToast } from "../stores/admin";
import Badge from "./ui/Badge.vue";
import Button from "./ui/Button.vue";
import ConfirmDialog from "./ui/ConfirmDialog.vue";
import ModalDialog from "./ui/ModalDialog.vue";

const open = defineModel<boolean>("open", { default: false });

const props = withDefaults(
  defineProps<{
    entityType: "blog" | "docs" | "legal";
    entityKey: string;
    canRestore?: boolean;
  }>(),
  {
    canRestore: false,
  }
);

const emit = defineEmits<{
  restored: [payload: { entity_type: string; entity_key: string }];
}>();

const loading = ref(false);
const restoring = ref(false);
const revisions = ref<RevisionItem[]>([]);
const selected = ref<RevisionItem | null>(null);
const confirmOpen = ref(false);

async function load() {
  if (!props.entityKey) return;
  loading.value = true;
  try {
    const res = await apiGet<{ ok: boolean; revisions: RevisionItem[] }>("revisions.list", {
      entity_type: props.entityType,
      entity_key: props.entityKey,
    });
    revisions.value = res.revisions || [];
    selected.value = revisions.value[0] || null;
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Failed to load revisions.", "destructive");
  } finally {
    loading.value = false;
  }
}

watch(open, (val) => {
  if (val) {
    void load();
  }
});

async function executeRestore() {
  if (!selected.value) return;
  restoring.value = true;
  try {
    const res = await apiPost<{ ok: boolean; entity_type: string; entity_key: string }>("revisions.restore", {
      id: selected.value.id,
    });
    notifyToast(`Restored revision #${selected.value.id}.`, "success");
    confirmOpen.value = false;
    open.value = false;
    emit("restored", { entity_type: res.entity_type, entity_key: res.entity_key });
  } catch (err) {
    notifyToast(err instanceof Error ? err.message : "Restore failed.", "destructive");
  } finally {
    restoring.value = false;
  }
}
</script>

<template>
  <ModalDialog
    v-model:open="open"
    title="Revision History"
    :description="`Latest SQLite snapshots for ${props.entityType}: ${props.entityKey}`"
    size="xl"
  >
    <div v-if="loading" class="py-10 text-center text-sm text-muted-foreground">Loading revisions...</div>
    <div v-else-if="revisions.length === 0" class="py-10 text-center text-sm text-muted-foreground">
      No saved revisions yet for this record. Save changes once to create an immutable snapshot.
    </div>
    <div v-else class="grid gap-4 md:grid-cols-3">
      <div class="space-y-1.5 max-h-[52vh] overflow-y-auto overscroll-contain lumora-scroll-area pr-1">
        <button
          v-for="(rev, idx) in revisions"
          :key="rev.id"
          type="button"
          :class="[
            'w-full text-left rounded-md border p-2.5 text-xs transition-colors cursor-pointer',
            selected?.id === rev.id ? 'border-primary bg-accent' : 'border-border hover:bg-muted/40',
          ]"
          @click="selected = rev"
        >
          <div class="flex items-center justify-between gap-2">
            <span class="font-medium truncate">#{{ rev.id }} · {{ rev.title || props.entityKey }}</span>
            <Badge v-if="idx === 0" variant="success">Latest</Badge>
          </div>
          <div class="mt-1 text-[11px] text-muted-foreground truncate">{{ rev.actor_email || "system" }}</div>
          <div class="text-[11px] text-muted-foreground">{{ formatDateTime(rev.created_at) }}</div>
        </button>
      </div>

      <div v-if="selected" class="md:col-span-2 space-y-3 border border-border rounded-md p-4 bg-muted/20 min-w-0">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-2.5">
          <div>
            <div class="text-sm font-semibold flex items-center gap-1.5">
              <History class="h-4 w-4" aria-hidden="true" />
              <span>Snapshot #{{ selected.id }}</span>
            </div>
            <div class="text-xs text-muted-foreground">
              Saved {{ formatDateTime(selected.created_at) }} by {{ selected.actor_email || "system" }}
            </div>
          </div>
        </div>

        <pre class="text-xs font-mono bg-background border border-border rounded p-3 max-h-[45vh] overflow-auto overscroll-contain lumora-scroll-area whitespace-pre-wrap">{{ JSON.stringify(selected.snapshot, null, 2) }}</pre>
      </div>
    </div>

    <template #footer>
      <span v-if="selected" class="mr-auto text-xs text-muted-foreground">
        {{ revisions.length }} revision(s) stored
      </span>
      <Button variant="outline" size="sm" @click="open = false">Close</Button>
      <Button
        v-if="props.canRestore && selected"
        size="sm"
        :disabled="restoring"
        @click="confirmOpen = true"
      >
        <RotateCcw class="h-3.5 w-3.5" aria-hidden="true" />
        <span>Restore Selected Revision</span>
      </Button>
    </template>

    <ConfirmDialog
      v-model:open="confirmOpen"
      title="Restore Revision Snapshot?"
      :description="`Replace current live SQLite record (${props.entityKey}) with snapshot #${selected?.id}? A new revision entry will also be preserved.`"
      confirm-label="Restore Version"
      variant="destructive"
      :loading="restoring"
      @confirm="executeRestore"
    />
  </ModalDialog>
</template>
