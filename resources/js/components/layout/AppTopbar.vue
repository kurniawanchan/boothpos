<script setup>
import LanguageSwitcher from './LanguageSwitcher.vue';

defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
});
</script>

<template>
  <header class="flex flex-wrap items-center gap-4 border-b border-line-2 bg-white px-[26px] py-3.5">
    <div class="flex min-w-[200px] flex-1 flex-col gap-0.5">
      <h1 id="page-heading" tabindex="-1" class="text-[19px] font-bold tracking-tight outline-none">{{ title }}</h1>
      <p v-if="subtitle" class="text-[12.5px] text-muted-2">{{ subtitle }}</p>
    </div>
    <!-- Rendered langsung di sini (bukan lewat slot actions) supaya
         tersedia di SETIAP layar yang memakai AppTopbar tanpa perlu
         tiap view mendaftarkannya sendiri (FR-003/FR-004). -->
    <LanguageSwitcher />
    <slot name="actions" />
  </header>
</template>
