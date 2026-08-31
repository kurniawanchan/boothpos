<script setup>
import { ref, onMounted } from 'vue';
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
      <AppTopbar :title="route.meta.title" :subtitle="route.meta.subtitle" />
      <main class="min-h-0 flex-1 overflow-auto">
        <RouterView />
      </main>
    </div>
  </div>
</template>
