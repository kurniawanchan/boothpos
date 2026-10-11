<script setup>
/** 041-back-office-ui-redesign (US2) — bagian berjudul di dalam formulir (FormDrawer): judul, deskripsi, isi. */
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
});
</script>

<template>
  <fieldset class="mb-6 border-0 p-0 last:mb-0">
    <legend class="mb-1 text-[13.5px] font-bold">{{ title }}</legend>
    <p v-if="description" class="mb-3 text-[12px] leading-relaxed text-muted">{{ description }}</p>
    <div class="flex flex-col gap-3.5"><slot /></div>
  </fieldset>
</template>
