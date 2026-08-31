<script setup>
import { storeToRefs } from 'pinia';
import { useToastStore } from '../../stores/toast';

const toast = useToastStore();
const { items } = storeToRefs(toast);

const styles = {
  success: 'bg-ink text-white border-transparent',
  danger: 'bg-white text-danger-text border-danger-border',
  warning: 'bg-warn-bg text-warn-text border-warn-border',
  info: 'bg-white text-ink border-line-2',
};

const icons = {
  success: 'ph-check-circle',
  danger: 'ph-warning-circle',
  warning: 'ph-warning',
  info: 'ph-info',
};
</script>

<template>
  <div class="fixed bottom-5 right-5 z-[100] flex w-full max-w-sm flex-col gap-2" aria-live="polite" role="status">
    <div
      v-for="item in items"
      :key="item.id"
      class="flex items-start gap-3 rounded-lg border px-4 py-3 text-[13px] font-medium shadow-lg"
      :class="styles[item.variant] ?? styles.info"
    >
      <i class="ph-duotone text-[17px]" :class="icons[item.variant] ?? icons.info" aria-hidden="true"></i>
      <span class="flex-1 leading-snug">{{ item.message }}</span>
      <button
        type="button"
        class="text-current opacity-70 transition-opacity hover:opacity-100"
        aria-label="Tutup notifikasi"
        @click="toast.dismiss(item.id)"
      >
        <i class="ph-duotone ph-x text-[14px]" aria-hidden="true"></i>
      </button>
    </div>
  </div>
</template>
