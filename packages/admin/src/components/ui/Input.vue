<script setup lang="ts">
import { cn } from "../../lib/utils";

defineOptions({ inheritAttrs: false });

const model = defineModel<string | number>();
const props = withDefaults(defineProps<{ class?: string }>(), { class: "" });
</script>

<template>
  <input
    v-model="model"
    v-bind="$attrs"
    :class="
      cn(
        'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm shadow-xs transition-colors placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
        props.class
      )
    "
  />
</template>
