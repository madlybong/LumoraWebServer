<script setup lang="ts">
import { cn } from "../../lib/utils";

const props = withDefaults(defineProps<{ class?: string }>(), { class: "" });
</script>

<template>
  <div :class="cn('rounded-lg border border-border bg-card text-card-foreground shadow-xs', props.class)">
    <slot />
  </div>
</template>
