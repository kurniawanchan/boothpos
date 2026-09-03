<script setup>
import { computed, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useSettingsStore } from '../../stores/settings';
import { listPreorders } from '../../api/preorders';
import AppSidebar from './AppSidebar.vue';
import AppTopbar from './AppTopbar.vue';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const settings = useSettingsStore();
const { t } = useI18n();

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
});

async function handleLogout() {
  await auth.logout();
  router.push({ name: 'login' });
}
</script>

<template>
  <div class="flex min-h-screen">
    <AppSidebar :preorder-alert-count="preorderAlertCount" @logout="handleLogout" />
    <div class="flex min-w-0 flex-1 flex-col">
      <AppTopbar :title="pageTitle" :subtitle="pageSubtitle" />
      <main class="min-h-0 flex-1 overflow-auto">
        <RouterView />
      </main>
    </div>
  </div>
</template>
