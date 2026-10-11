<script setup>
import { useI18n } from 'vue-i18n';

// 041-back-office-ui-redesign — keadaan "memuat" yang SERAGAM untuk semua halaman (FR-011): bingkai tidak
// pernah menampilkan area konten kosong. Kerangka baris abu-abu + status ARIA agar pembaca layar tahu
// halaman sedang bekerja. Tinggi/jumlah baris bisa disesuaikan, bentuknya tidak.
defineProps({
  rows: { type: Number, default: 5 },
  label: { type: String, default: '' },
});
const { t } = useI18n();
</script>

<template>
  <div role="status" aria-busy="true" class="flex flex-col gap-2.5 px-5 py-6">
    <span class="sr-only">{{ label || t('page.loading') }}</span>
    <div v-for="n in rows" :key="n" class="h-[14px] animate-pulse bg-line-7" :style="{ width: `${92 - ((n * 13) % 40)}%` }" aria-hidden="true"></div>
  </div>
</template>
