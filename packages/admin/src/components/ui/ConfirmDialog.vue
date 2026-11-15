<script setup lang="ts">
import ModalDialog from "./ModalDialog.vue";
import Button from "./Button.vue";

const open = defineModel<boolean>("open", { default: false });

const props = withDefaults(
  defineProps<{
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: "destructive" | "default";
    loading?: boolean;
  }>(),
  {
    description: "",
    confirmLabel: "Confirm",
    cancelLabel: "Cancel",
    variant: "default",
    loading: false,
  }
);

const emit = defineEmits<{
  confirm: [];
}>();

function handleConfirm() {
  emit("confirm");
}
</script>

<template>
  <ModalDialog v-model:open="open" :title="props.title" :description="props.description" size="sm">
    <p class="text-sm text-muted-foreground">
      {{ props.description || "Please confirm you want to proceed with this action." }}
    </p>
    <template #footer>
      <Button
        variant="outline"
        type="button"
        :autofocus="props.variant === 'destructive'"
        :disabled="props.loading"
        @click="open = false"
      >
        {{ props.cancelLabel }}
      </Button>
      <Button
        :variant="props.variant === 'destructive' ? 'destructive' : 'default'"
        type="button"
        :autofocus="props.variant !== 'destructive'"
        :disabled="props.loading"
        @click="handleConfirm"
      >
        {{ props.loading ? "Working..." : props.confirmLabel }}
      </Button>
    </template>
  </ModalDialog>
</template>
