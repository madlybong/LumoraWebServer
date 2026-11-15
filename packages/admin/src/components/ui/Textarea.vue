<script setup lang="ts">
import { cn } from "../../lib/utils";

defineOptions({ inheritAttrs: false });

const model = defineModel<string>();
const props = withDefaults(defineProps<{ class?: string }>(), { class: "" });
</script>

<template>
  <textarea
    v-model="model"
    v-bind="$attrs"
    :class="
      cn(
        'flex min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
        props.class
      )
    "
  />
</template>
