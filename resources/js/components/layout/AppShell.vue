<script setup>
import { computed, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useSettingsStore } from '../../stores/settings';
import { listPreorders } from '../../api/preorders';
import { listEvents } from '../../api/events';
import { useRailState } from '../../composables/useRailState';
import { customHeaderCount } from '../../composables/usePageHeader';
import RailNav from './RailNav.vue';
import TopBar from './TopBar.vue';
import SchemaUpdateBanner from './SchemaUpdateBanner.vue';

/**
 * 041-back-office-ui-redesign (US1) — kerangka seluruh halaman: rel navigasi + topbar + area konten.
 * Menggantikan AppSidebar/AppTopbar. Yang dipertahankan dari versi sebelumnya (komentar asli di riwayat git):
 *  - nama toko & event aktif dimuat SEKALI di sini (bukan di tiap view; tak ada endpoint "event aktif");
 *  - judul/subjudul bawaan dari route.meta.titleKey/subtitleKey, jatuh ke nav.<nama>;
 *  - badge pre-order = jumlah pre-order `dp_paid` (belum ada endpoint "alerts" khusus);
 *  - tombol keluar → auth.logout() lalu ke halaman login.
 * Yang baru: keadaan ciut/lebar rel diingat per pengguna (useRailState), tautan "lewati ke konten", dan judul
 * bawaan yang otomatis disembunyikan begitu sebuah halaman memakai <PageHeader> (composables/usePageHeader.js).
 */
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const settings = useSettingsStore();
const { t } = useI18n();

const activeEvent = ref(null);
const preorderAlertCount = ref(0);
const { collapsed, toggle } = useRailState(computed(() => auth.user?.id ?? null));

const pageTitle = computed(() => t(route.meta.titleKey ?? `nav.${route.name}`));
const pageSubtitle = computed(() => t(route.meta.subtitleKey ?? `nav.${String(route.name)}_subtitle`));

onMounted(async () => {
  await settings.load();
  try {
    const res = await listPreorders({ status: 'dp_paid', per_page: 1 });
    preorderAlertCount.value = res.meta?.total ?? 0;
  } catch {
    // Non-fatal — lencana tetap 0 bila gagal.
  }
  try {
    const eventsRes = await listEvents({ status: 'active', per_page: 1 });
    activeEvent.value = eventsRes.data[0] ?? null;
  } catch {
    // Non-fatal — topbar cukup tidak menampilkan nama event.
  }
});

async function handleLogout() {
  await auth.logout();
  router.push({ name: 'login' });
}
</script>

<template>
  <div class="flex min-h-screen bg-page">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[100] focus:bg-brand focus:px-3 focus:py-2 focus:text-[13px] focus:font-bold focus:text-white">
      {{ t('frame.skip_to_content') }}
    </a>
    <RailNav :collapsed="collapsed" :preorder-alert-count="preorderAlertCount" @toggle="toggle" />
    <div class="flex min-w-0 flex-1 flex-col">
      <TopBar :store-name="settings.storeName" :active-event="activeEvent" @logout="handleLogout" />
      <SchemaUpdateBanner v-if="settings.schemaUpdateRequired" />
      <main id="main-content" tabindex="-1" class="min-h-0 flex-1 overflow-auto outline-none">
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
