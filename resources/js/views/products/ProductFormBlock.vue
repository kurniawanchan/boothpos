<script setup>
/**
 * 043 — bagian formulir item "rata": label kecil huruf besar berwarna redup (+ satu baris deskripsi opsional),
 * dipisah garis tipis dari bagian berikutnya. Tanpa kartu/bingkai berlapis — sesuai desain formulir Menu item.
 * Dipakai di dalam BaseDrawer `flush`, jadi padding horizontal 26px dipegang di sini.
 * Slot `actions` (opsional) = kendali kecil di ujung kanan kepala bagian, mis. isian markup di "Varian & harga".
 */
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
});
</script>

<template>
  <section class="flex flex-col gap-3.5 border-b border-line-2 px-[26px] py-5 last:border-b-0">
    <div class="flex items-start justify-between gap-4">
      <div class="flex flex-col gap-1">
        <h3 class="text-[11px] font-semibold uppercase tracking-[0.06em] text-muted">{{ title }}</h3>
        <p v-if="description" class="text-[12px] leading-relaxed text-muted-3">{{ description }}</p>
      </div>
      <slot name="actions" />
    </div>
    <slot />
  </section>
</template>
