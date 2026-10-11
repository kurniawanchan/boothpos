<script setup>
import { registerPageHeader } from '../../composables/usePageHeader';

/**
 * 041-back-office-ui-redesign (US2) — kepala halaman: judul besar, deskripsi satu baris, tindakan di kanan
 * (satu tombol utama terisi + tombol sekunder bergaris). Memakai komponen ini otomatis menyembunyikan judul
 * bawaan kerangka (composables/usePageHeader.js), jadi tak pernah ada dua judul. `id="page-heading"` dipertahankan
 * karena router memindahkan fokus ke sana setelah navigasi (aksesibilitas).
 */
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
});
registerPageHeader();
</script>

<template>
  <header class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3 px-[26px] pb-4 pt-6">
    <div class="min-w-0 flex-1">
      <h1 id="page-heading" tabindex="-1" class="text-[22px] font-bold tracking-tight outline-none">{{ title }}</h1>
      <p v-if="description" class="mt-1 max-w-[760px] text-[12.5px] leading-relaxed text-muted">{{ description }}</p>
      <div v-if="$slots.meta" class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px] text-muted"><slot name="meta" /></div>
    </div>
    <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2.5"><slot name="actions" /></div>
  </header>
</template>
