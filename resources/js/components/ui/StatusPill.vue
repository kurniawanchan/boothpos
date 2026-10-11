<script setup>
import { computed } from 'vue';

// 041-back-office-ui-redesign — nada StatusChip desain ditambahkan DI SINI (bukan komponen kedua):
// success | info | accent | warning | danger | neutral. Varian lama tetap berlaku sebagai alias supaya
// pemakai yang ada tidak berubah: mint (= accent lembut), dark, warn (= warning).
const props = defineProps({
  variant: { type: String, default: 'neutral' }, // neutral | accent | info | success | warning | danger | mint | dark | warn
});

const TONES = {
  neutral: 'bg-line-7 text-muted-4',
  accent: 'bg-mint-100 text-brand-active',
  info: 'bg-sky-bg text-sky-text',
  success: 'bg-success-bg text-success-text',
  warning: 'bg-warn-bg text-warn-text',
  danger: 'bg-danger-bg text-danger-text',
  // alias varian lama
  mint: 'bg-mint-200 text-brand-active',
  dark: 'bg-ink text-white',
  warn: 'bg-warn-bg text-warn-text',
};

const classes = computed(() => TONES[props.variant] ?? TONES.neutral);
</script>

<template>
  <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[11.5px] font-bold" :class="classes">
    <slot />
  </span>
</template>
