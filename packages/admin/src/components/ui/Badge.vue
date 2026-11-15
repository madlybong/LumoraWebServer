<script setup lang="ts">
import { computed } from "vue";
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "../../lib/utils";

const badgeVariants = cva(
  "inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium",
  {
    variants: {
      variant: {
        default: "border-transparent bg-primary text-primary-foreground",
        secondary: "border-transparent bg-secondary text-secondary-foreground",
        outline: "border-border text-foreground",
        destructive: "border-transparent bg-destructive text-destructive-foreground",
        success: "border-emerald-500/30 bg-emerald-500/12 text-emerald-700 dark:text-emerald-300",
        warning: "border-amber-500/30 bg-amber-500/12 text-amber-700 dark:text-amber-300",
      },
    },
    defaultVariants: {
      variant: "secondary",
    },
  }
);

type BadgeVariants = VariantProps<typeof badgeVariants>;

const props = withDefaults(
  defineProps<{ variant?: BadgeVariants["variant"]; class?: string }>(),
  { variant: "secondary", class: "" }
);

const classes = computed(() => cn(badgeVariants({ variant: props.variant }), props.class));
</script>

<template>
  <span :class="classes"><slot /></span>
</template>
