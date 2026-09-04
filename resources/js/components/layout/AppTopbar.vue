<script setup>
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../../stores/auth';
import LanguageSwitcher from './LanguageSwitcher.vue';
import SystemModeBadge from './SystemModeBadge.vue';

const { t } = useI18n();
// Sama seperti SystemModeBadge — pengguna/logout diambil langsung dari
// store di sini, bukan lewat prop, karena topbar sudah jadi tempat semua
// widget lintas-layar (bahasa, mode) berdiri sendiri mengakses store-nya
// masing-masing (009-ui-ux-refinements US1/FR-020, research.md R2).
const auth = useAuthStore();

defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  // 009-ui-ux-refinements US1/FR-019 — nama toko & event aktif, diambil
  // sekali di AppShell.vue dan diteruskan sebagai prop (bukan store
  // baru) karena keduanya sudah punya sumber data yang ada (lihat
  // research.md R11): settings store untuk nama toko, pola
  // `listEvents({status:'active'})` yang sudah dipakai DashboardView
  // untuk event aktif.
  storeName: { type: String, default: '' },
  activeEvent: { type: Object, default: null },
  // Sengaja render tombol "Tampilkan sidebar" DI SINI (bagian dari flex
  // row topbar), bukan sebagai elemen fixed melayang di atas konten —
  // versi fixed sebelumnya menutupi judul halaman karena keduanya sama-
  // sama menempati pojok kiri atas begitu sidebar hilang. Ikut alur flex
  // yang sama dengan judul memastikan keduanya selalu berdampingan, tidak
  // pernah tumpang tindih.
  sidebarHidden: { type: Boolean, default: false },
});
const emit = defineEmits(['show-sidebar', 'logout']);
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
      <p v-if="storeName || activeEvent" class="text-[11.5px] font-medium text-muted-3">
        <span v-if="storeName">{{ storeName }}</span>
        <span v-if="storeName && activeEvent"> · </span>
        <span v-if="activeEvent">{{ activeEvent.name }}</span>
      </p>
    </div>
    <!-- Rendered langsung di sini (bukan lewat slot actions) supaya
         tersedia di SETIAP layar yang memakai AppTopbar tanpa perlu
         tiap view mendaftarkannya sendiri (FR-003/FR-004). -->
    <SystemModeBadge />
    <LanguageSwitcher />
    <slot name="actions" />

    <!-- 009-ui-ux-refinements US1/FR-020 — dipindah dari footer
         AppSidebar.vue ke pojok kanan-atas topbar, dikelompokkan dengan
         tombol logout, bukan diduplikasi (research.md R2). -->
    <div class="flex items-center gap-2.5 border-l border-line-3 pl-3">
      <RouterLink :to="{ name: 'profile' }" class="flex items-center gap-2.5 rounded-md px-1 py-1.5 transition-colors hover:bg-line-7">
        <img
          v-if="auth.user?.photo_url"
          :src="auth.user.photo_url"
          :alt="auth.user?.name"
          class="h-[30px] w-[30px] flex-none rounded-full object-cover"
        />
        <div v-else class="flex h-[30px] w-[30px] flex-none items-center justify-center rounded-full bg-mint-100 text-[12px] font-bold text-brand-active">
          {{ (auth.user?.name || '?').slice(0, 2).toUpperCase() }}
        </div>
        <div class="flex min-w-0 flex-col">
          <span class="truncate text-[12.5px] font-semibold">{{ auth.user?.name }} · {{ auth.user?.username }}</span>
          <span class="text-[11px] capitalize text-muted-3">{{ auth.user?.role }}</span>
        </div>
      </RouterLink>
      <button
        type="button"
        class="flex items-center justify-center gap-2 rounded-md border border-line px-3 py-2 text-[12.5px] font-bold text-muted-4 transition-colors hover:border-danger-border-hover hover:bg-danger-bg hover:text-danger-text"
        @click="emit('logout')"
      >
        <i class="ph-duotone ph-sign-out text-[15px]" aria-hidden="true"></i>
        {{ t('nav.logout') }}
      </button>
    </div>
  </header>
</template>
