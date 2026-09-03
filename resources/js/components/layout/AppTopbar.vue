<script setup>
import { useI18n } from 'vue-i18n';
import LanguageSwitcher from './LanguageSwitcher.vue';
import SystemModeBadge from './SystemModeBadge.vue';

const { t } = useI18n();

defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  // Sengaja render tombol "Tampilkan sidebar" DI SINI (bagian dari flex
  // row topbar), bukan sebagai elemen fixed melayang di atas konten —
  // versi fixed sebelumnya menutupi judul halaman karena keduanya sama-
  // sama menempati pojok kiri atas begitu sidebar hilang. Ikut alur flex
  // yang sama dengan judul memastikan keduanya selalu berdampingan, tidak
  // pernah tumpang tindih.
  sidebarHidden: { type: Boolean, default: false },
});
defineEmits(['show-sidebar']);
</script>

<template>
  <header class="flex flex-wrap items-center gap-4 border-b border-line-2 bg-white px-[26px] py-3.5">
    <button
      v-if="sidebarHidden"
      type="button"
      class="flex h-9 w-9 flex-none items-center justify-center rounded-md border border-line-2 text-muted-4 transition-colors hover:border-brand hover:text-brand-active"
      :aria-label="t('nav.show_sidebar')"
      :title="t('nav.show_sidebar')"
      @click="$emit('show-sidebar')"
    >
      <i class="ph-duotone ph-sidebar-simple text-[17px]" aria-hidden="true"></i>
    </button>
    <div class="flex min-w-[200px] flex-1 flex-col gap-0.5">
      <h1 id="page-heading" tabindex="-1" class="text-[19px] font-bold tracking-tight outline-none">{{ title }}</h1>
      <p v-if="subtitle" class="text-[12.5px] text-muted-2">{{ subtitle }}</p>
    </div>
    <!-- Rendered langsung di sini (bukan lewat slot actions) supaya
         tersedia di SETIAP layar yang memakai AppTopbar tanpa perlu
         tiap view mendaftarkannya sendiri (FR-003/FR-004). -->
    <SystemModeBadge />
    <LanguageSwitcher />
    <slot name="actions" />
  </header>
</template>
