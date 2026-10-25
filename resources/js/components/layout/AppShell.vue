<script setup>
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useSettingsStore } from '../../stores/settings';
import { useOutletStore } from '../../stores/outlet';
import { activateOutletSelection } from '../../utils/outletSelection';
import { useRailState } from '../../composables/useRailState';
import { useBranding } from '../../composables/useBranding';
import { customHeaderCount } from '../../composables/usePageHeader';
import RailNav from './RailNav.vue';
import TopBar from './TopBar.vue';
import SchemaUpdateBanner from './SchemaUpdateBanner.vue';

/**
 * 041-back-office-ui-redesign (US1) — kerangka seluruh halaman: rel navigasi + topbar + area konten.
 * Menggantikan AppSidebar/AppTopbar. Yang dipertahankan dari versi sebelumnya (komentar asli di riwayat git):
 *  - nama toko dimuat SEKALI di sini (bukan di tiap view);
 *  - judul/subjudul bawaan dari route.meta.titleKey/subtitleKey, jatuh ke nav.<nama>;
 *  - tombol keluar → auth.logout() lalu ke halaman login.
 * Yang baru: keadaan ciut/lebar rel diingat per pengguna (useRailState), tautan "lewati ke konten", dan judul
 * bawaan yang otomatis disembunyikan begitu sebuah halaman memakai <PageHeader> (composables/usePageHeader.js).
 */
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const settings = useSettingsStore();
const outlet = useOutletStore();
// 045 Stage 2 — pilihan outlet tersimpan diaktifkan SINKRON saat setup, sebelum anak-anak (halaman) memuat
// datanya, supaya permintaan pertama mereka sudah membawa X-Outlet-Id. Server tetap memotong dengan lingkup pengguna.
activateOutletSelection(auth.user?.id);

// Memilih outlet memuat ulang halaman: setiap layar mengambil ulang datanya dengan header baru.
function onOutletChange(id) {
  outlet.select(id);
  window.location.reload();
}
const { t } = useI18n();

const { collapsed, toggle } = useRailState(computed(() => auth.user?.id ?? null));
// 072 — judul tab dan favicon dari identitas toko.
useBranding();

const pageTitle = computed(() => t(route.meta.titleKey ?? `nav.${route.name}`));
const pageSubtitle = computed(() => t(route.meta.subtitleKey ?? `nav.${String(route.name)}_subtitle`));

onMounted(async () => {
  // Cakupan outlet dimuat bersamaan dengan pengaturan (tidak saling menunggu). Non-fatal bila gagal: tanpa cakupan
  // pemilih outlet tidak tampil dan server memakai semua outlet yang boleh.
  const scopeLoaded = auth.user?.id != null ? outlet.load(auth.user.id).catch(() => {}) : Promise.resolve();
  await settings.load();
  await scopeLoaded;
});

async function handleLogout() {
  await auth.logout();
  router.push({ name: 'login' });
}
</script>

<template>
  <div class="flex h-screen bg-page">
    <!-- 072 — setinggi layar (bukan min-h-screen): akar yang tumbuh mengikuti isi membuat halaman POS menggulir dan
         footer keranjang (Total/Bayar) terdorong keluar layar. `main` di bawah satu-satunya penggulir halaman. -->
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[100] focus:bg-brand focus:px-3 focus:py-2 focus:text-[13px] focus:font-bold focus:text-white">
      {{ t('frame.skip_to_content') }}
    </a>
    <RailNav :collapsed="collapsed" @toggle="toggle" />
    <div class="flex min-w-0 flex-1 flex-col">
      <TopBar :store-name="settings.storeName" :outlets="outlet.outlets" :outlet-id="outlet.selected" @update:outlet-id="onOutletChange" @logout="handleLogout" />
      <SchemaUpdateBanner v-if="settings.schemaUpdateRequired" />
      <main id="main-content" tabindex="-1" class="min-h-0 flex-1 overflow-auto outline-none" :class="route.meta.fillHeight ? 'flex flex-col' : ''">
        <!-- Judul bawaan untuk halaman yang belum memakai <PageHeader>. -->
        <div v-if="customHeaderCount === 0" class="flex flex-col gap-0.5 px-[26px] pt-5">
          <h1 id="page-heading" tabindex="-1" class="text-[22px] font-bold tracking-tight outline-none">{{ pageTitle }}</h1>
          <p class="text-[12.5px] text-muted">{{ pageSubtitle }}</p>
        </div>
        <RouterView />
      </main>
    </div>
  </div>
</template>
