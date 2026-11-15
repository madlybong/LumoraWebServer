<script setup lang="ts">
import { computed } from "vue";
import {
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
} from "reka-ui";
import { X } from "lucide-vue-next";

const open = defineModel<boolean>("open", { default: false });

const SIZE_CLASSES: Record<"sm" | "md" | "lg" | "xl", string> = {
  sm: "max-w-md",
  md: "max-w-lg",
  lg: "max-w-2xl",
  xl: "max-w-4xl",
};

const props = withDefaults(
  defineProps<{
    title: string;
    description?: string;
    size?: "sm" | "md" | "lg" | "xl";
    maxWidth?: string;
  }>(),
  {
    description: "",
    size: "lg",
    maxWidth: "",
  }
);

const resolvedWidthClass = computed(() => props.maxWidth || SIZE_CLASSES[props.size] || "max-w-2xl");
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-50 bg-black/55 backdrop-blur-[1px] overscroll-contain" />
      <DialogContent
        :class="[
          'fixed top-1/2 left-1/2 z-50 w-[calc(100%-2rem)] -translate-x-1/2 -translate-y-1/2 flex flex-col max-h-[85dvh] overflow-hidden p-0 rounded-xl border border-border bg-card text-card-foreground shadow-2xl overscroll-contain',
          resolvedWidthClass,
        ]"
      >
        <div class="px-6 py-4 border-b border-border shrink-0 flex items-start justify-between gap-4 bg-card">
          <div class="min-w-0">
            <DialogTitle class="text-base font-semibold leading-snug">{{ props.title }}</DialogTitle>
            <DialogDescription v-if="props.description" class="text-xs text-muted-foreground mt-0.5">
              {{ props.description }}
            </DialogDescription>
          </div>
          <DialogClose
            aria-label="Close modal"
            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground cursor-pointer"
          >
            <X class="h-4 w-4" aria-hidden="true" />
          </DialogClose>
        </div>

        <div class="px-6 py-5 flex-1 min-h-0 overflow-y-auto overscroll-contain lumora-scroll-area">
          <slot />
        </div>

        <div
          v-if="$slots.footer"
          class="px-6 py-3.5 border-t border-border bg-muted/30 shrink-0 flex flex-wrap items-center justify-end gap-2"
        >
          <slot name="footer" />
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
