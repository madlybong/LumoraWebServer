<script setup lang="ts">
import { SwitchRoot, SwitchThumb } from "reka-ui";

const checked = defineModel<boolean>({ default: false });
const props = withDefaults(defineProps<{ id?: string; disabled?: boolean }>(), {
  id: undefined,
  disabled: false,
});
</script>

<template>
  <SwitchRoot
    :id="props.id"
    v-model="checked"
    :disabled="props.disabled"
    class="peer inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors data-[state=checked]:bg-primary data-[state=unchecked]:bg-input disabled:cursor-not-allowed disabled:opacity-50"
  >
    <SwitchThumb
      class="pointer-events-none block h-4 w-4 rounded-full bg-background shadow-sm transition-transform data-[state=checked]:translate-x-4 data-[state=unchecked]:translate-x-0"
    />
  </SwitchRoot>
</template>
