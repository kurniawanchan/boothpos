<script setup>
import { computed, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useSettingsStore } from '../../stores/settings';
import { listPreorders } from '../../api/preorders';
import { listEvents } from '../../api/events';
import AppSidebar from './AppSidebar.vue';
import AppTopbar from './AppTopbar.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const settings = useSettingsStore();
const { t } = useI18n();

// 009-ui-ux-refinements US1/FR-019 — sama seperti DashboardView.vue:
// tidak ada endpoint "event aktif saat ini" khusus, jadi event
// `status=active` pertama dianggap sedang berlangsung. Dimuat sekali di
// sini (bukan di tiap view) supaya nama event tersedia di navbar setiap
// layar (research.md R11).
const activeEvent = ref(null);

// Toggle tampilkan/sembunyikan sidebar — preferensi murni per-perangkat
// (localStorage), bukan data akun, jadi sengaja tidak disimpan lewat API.
// Dibungkus try/catch karena localStorage bisa melempar di mode privat
// beberapa browser; kalau gagal, sidebar cukup selalu tampil (aman).
const SIDEBAR_VISIBLE_KEY = 'boothpos:sidebar-visible';
function readStoredSidebarVisible() {
  try {
    return localStorage.getItem(SIDEBAR_VISIBLE_KEY) !== 'hidden';
  } catch {
    return true;
  }
}
const sidebarVisible = ref(readStoredSidebarVisible());
function setSidebarVisible(visible) {
  sidebarVisible.value = visible;
  try {
    localStorage.setItem(SIDEBAR_VISIBLE_KEY, visible ? 'visible' : 'hidden');
  } catch {
    // Non-fatal — preferensi cukup tidak tersimpan lintas sesi.
  }
}

// Sebagian besar layar dinamai persis sama dengan menu_keys-nya
// (route.name === 'dashboard', 'pos', dst.), jadi judul/subjudul default
// mengambil dari nav.<name>/<name>_subtitle. Beberapa layar (Produk,
// Stok) punya judul halaman lebih panjang daripada label sidebarnya
// sendiri — meta.titleKey/subtitleKey di router/index.js mengganti
// default itu untuk kasus tersebut.
const pageTitle = computed(() => t(route.meta.titleKey ?? `nav.${route.name}`));
const pageSubtitle = computed(() => t(route.meta.subtitleKey ?? `nav.${String(route.name)}_subtitle`));

// Preorders needing action have no dedicated "alerts" endpoint — derived
// client-side by counting dp_paid pre-orders (paid but goods not yet
// marked arrived). See project brief §B / ASSUMPTION noted in the report.
const preorderAlertCount = ref(0);

onMounted(async () => {
  await settings.load();
  try {
    const res = await listPreorders({ status: 'dp_paid', per_page: 1 });
    preorderAlertCount.value = res.meta?.total ?? 0;
  } catch {
    // Non-fatal — the badge just stays at 0 if this fails.
  }
  try {
    const eventsRes = await listEvents({ status: 'active', per_page: 1 });
    activeEvent.value = eventsRes.data[0] ?? null;
  } catch {
    // Non-fatal — navbar simply omits the event name if this fails.
  }
});

async function handleLogout() {
  await auth.logout();
  router.push({ name: 'login' });
}
</script>

<template>
  <div class="flex min-h-screen">
    <!-- AppSidebar tetap ter-mount (bukan v-if) SELAMA transisi berjalan
         supaya lebar wrapper bisa dianimasikan mulus (228px <-> 0);
         v-if lama menghapus/memasang ulang secara instan tanpa animasi
         sama sekali. overflow-hidden meng-clip isi <nav> yang tetap
         w-[228px] internal saat wrapper menyempit — pola "slide collapse"
         standar. Judul halaman tidak lagi bisa tertutup: tombol
         "Tampilkan sidebar" sekarang di dalam AppTopbar (ikut alur flex
         yang sama dengan judul), bukan elemen fixed melayang. -->
    <div
      class="flex-none overflow-hidden transition-[width] duration-500 ease-in-out"
      :style="{ width: sidebarVisible ? '228px' : '0px' }"
      :aria-hidden="!sidebarVisible"
      :inert="!sidebarVisible"
    >
      <AppSidebar
        :preorder-alert-count="preorderAlertCount"
        @hide-sidebar="setSidebarVisible(false)"
      />
    </div>
    <div class="flex min-w-0 flex-1 flex-col">
      <AppTopbar
        :title="pageTitle"
        :subtitle="pageSubtitle"
        :store-name="settings.storeName"
        :active-event="activeEvent"
        :sidebar-hidden="!sidebarVisible"
        @show-sidebar="setSidebarVisible(true)"
        @logout="handleLogout"
      />
      <main class="min-h-0 flex-1 overflow-auto">
        <RouterView />
      </main>
    </div>
  </div>
</template>
