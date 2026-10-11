<script setup>
import { useI18n } from 'vue-i18n';

// 041-back-office-ui-redesign — keadaan "gagal memuat" yang seragam: pesan jelas + tombol coba lagi.
// Pesan galat spesifik (422/409/403) tetap ditangani handler galat pusat; komponen ini untuk kegagalan
// memuat satu halaman/bagian (jaringan putus, 5xx) tempat pengguna perlu satu jalan keluar yang jelas.
defineProps({
  message: { type: String, default: '' },
  retryable: { type: Boolean, default: true },
});
defineEmits(['retry']);
const { t } = useI18n();
</script>

<template>
  <div role="alert" class="flex flex-col items-center gap-3 px-5 py-12 text-center">
    <i class="ph-duotone ph-warning-circle text-[34px] text-danger-text" aria-hidden="true"></i>
    <p class="max-w-[320px] text-[13px] leading-relaxed text-muted-4">{{ message || t('page.load_failed') }}</p>
    <button
      v-if="retryable"
      type="button"
      class="border-2 border-line bg-white px-3.5 py-2 text-[12.5px] font-bold text-muted-5 transition-colors hover:border-brand hover:text-brand-active focus-visible:outline-none focus-visible:border-brand"
      @click="$emit('retry')"
    >
      {{ t('page.retry') }}
    </button>
  </div>
</template>
