<script setup>
import { computed } from 'vue';

const props = defineProps({
  variant: { type: String, default: 'neutral' }, // mint | dark | neutral | warn | danger
});

const classes = computed(
  () =>
    ({
      mint: 'bg-mint-200 text-brand-active',
      dark: 'bg-ink text-white',
      neutral: 'bg-line-7 text-muted-4',
      warn: 'bg-warn-bg text-warn-text',
      danger: 'bg-danger-bg text-danger-text',
    })[props.variant] ?? 'bg-line-7 text-muted-4'
);
</script>

<template>
  <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11.5px] font-bold" :class="classes">
    <slot />
  </span>
</template>
