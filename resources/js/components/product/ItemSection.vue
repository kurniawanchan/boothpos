<script setup>
import LoadingState from '../ui/LoadingState.vue';
import ErrorState from '../ui/ErrorState.vue';

/**
 * 043 (US2) — pembungkus satu bagian halaman item: judul berkalimat biasa di kiri, tombol tindakan bergaris di
 * kanan (slot `action`, hanya dirender pemanggil bila diizinkan), lalu isi. Tiap bagian punya keadaan MEMUAT dan GALAT
 * (+ Coba lagi) sendiri lewat `state`, jadi satu permintaan yang gagal tidak mengosongkan seluruh halaman (FR-021).
 * Gaya kartu mengikuti desain Back Office: garis tipis dan sudut kecil (rounded-[4px]; token --radius-* di proyek ini
 * 0, sedangkan tangkapan desain tabel item memperlihatkan sudut kecil), `overflow-hidden` supaya kepala tabel
 * berlatar tidak menembus sudut.
 */
defineProps({
  title: { type: String, required: true },
  state: { type: String, default: 'ready' }, // loading | error | ready
  errorMessage: { type: String, default: '' },
  testid: { type: String, default: undefined },
});
defineEmits(['retry']);
</script>

<template>
  <section class="overflow-hidden rounded-[4px] border border-line bg-white" :data-testid="testid" :aria-label="title">
    <header class="flex min-h-[48px] flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-2.5">
      <h2 class="text-[13.5px] font-bold text-ink">{{ title }}</h2>
      <div v-if="$slots.action" class="flex items-center gap-2"><slot name="action" /></div>
    </header>
    <LoadingState v-if="state === 'loading'" :rows="3" />
    <ErrorState v-else-if="state === 'error'" :message="errorMessage" @retry="$emit('retry')" />
    <slot v-else />
  </section>
</template>
