<script setup>
import { computed } from 'vue';

const props = defineProps({
  variant: { type: String, default: 'primary' }, // primary | secondary | danger | dark | plain
  size: { type: String, default: 'md' }, // sm | md | lg | icon
  type: { type: String, default: 'button' },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});
defineEmits(['click']);

const base =
  'inline-flex items-center justify-center gap-2 rounded-lg font-bold cursor-pointer transition-colors disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-mint-100 focus-visible:border-brand border';

const variants = {
  primary: 'bg-brand text-white border-transparent hover:bg-brand-hover active:bg-brand-active',
  secondary: 'bg-white text-muted-5 border-line hover:border-brand hover:text-brand-active',
  danger: 'bg-white text-danger-text border-line hover:border-danger-border-hover hover:bg-danger-bg',
  dark: 'bg-ink text-white border-transparent hover:bg-ink-hover',
  plain: 'bg-transparent text-muted border-transparent hover:bg-line-7',
};

const sizes = {
  sm: 'h-9 px-3 text-[12.5px]',
  md: 'h-[46px] px-4 text-[14px]',
  lg: 'h-12 px-5 text-[15px]',
  icon: 'h-[34px] w-[34px] p-0',
};

const classes = computed(() => [base, variants[props.variant] ?? variants.primary, sizes[props.size] ?? sizes.md]);
</script>

<template>
  <button :type="type" :disabled="disabled || loading" :class="classes" @click="$emit('click', $event)">
    <i v-if="loading" class="ph-duotone ph-circle-notch animate-spin text-[16px]" aria-hidden="true"></i>
    <slot />
  </button>
</template>
