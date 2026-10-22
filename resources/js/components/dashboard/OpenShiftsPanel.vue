<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { shifts as fetchShifts } from '../../api/dashboard';
import { formatIDR } from '../../utils/formatters';
import { formatDateTime } from '../../utils/date';
import StatusPill from '../ui/StatusPill.vue';
import LoadingState from '../ui/LoadingState.vue';
import ErrorState from '../ui/ErrorState.vue';

/**
 * 065 / US1 — panel dashboard "Shift terbuka": satu baris per shift terbuka (aplikasi dan web), dari
 * GET /dashboard/shifts. Tiap baris menautkan ke Sesi Kasir (`/session?id=`). `refresh()` dipanggil auto-refresh
 * Dashboard: data lama bertahan sampai yang baru tiba, atau tetap bila refresh gagal.
 */
const { t } = useI18n();
const state = ref('loading'); // loading | ready | error
const rows = ref([]);
const totalOpen = ref(0);

async function load({ silent = false } = {}) {
  if (!silent) state.value = 'loading';
  try {
    const res = await fetchShifts();
    rows.value = res.data ?? [];
    totalOpen.value = res.total_open ?? rows.value.length;
    state.value = 'ready';
  } catch {
    if (!silent) state.value = 'error';
  }
}
onMounted(() => load());
defineExpose({ refresh: () => load({ silent: true }) });
</script>

<template>
  <section class="mx-[26px] mb-5 border-2 border-line bg-white" data-testid="open-shifts-panel" :aria-label="t('shifts.panel_title')">
    <div class="flex items-center justify-between px-5 pt-4">
      <h2 class="text-[15px] font-bold tracking-tight">{{ t('shifts.panel_title') }}</h2>
      <span class="text-[12.5px] text-muted-3" data-testid="open-shifts-count">{{ t('shifts.panel_count', { count: totalOpen }) }}</span>
    </div>
    <LoadingState v-if="state === 'loading'" :rows="2" />
    <ErrorState v-else-if="state === 'error'" @retry="load()" />
    <p v-else-if="rows.length === 0" class="px-5 py-6 text-[13px] text-muted-3" data-testid="open-shifts-empty">{{ t('shifts.empty_open') }}</p>
    <ul v-else class="divide-y divide-line-3 px-5 pb-3 pt-2">
      <li v-for="s in rows" :key="s.id" class="flex flex-wrap items-center gap-3 py-2.5" data-testid="open-shift-row">
        <router-link :to="{ path: '/session', query: { id: s.id } }" class="min-w-[140px] text-[14px] font-bold text-brand-active hover:underline">{{ s.cashier?.name ?? '—' }}</router-link>
        <StatusPill :variant="s.source === 'app' ? 'info' : 'neutral'">{{ s.source === 'app' ? (s.terminal_name || t('shifts.source_app')) : t('shifts.source_web') }}</StatusPill>
        <span class="text-[12.5px] text-muted-3">{{ s.outlet?.name }} · {{ t('shifts.since', { time: formatDateTime(s.opened_at) }) }}</span>
        <span class="ml-auto text-[13px] font-semibold">{{ formatIDR(s.sales_total) }} <span class="font-normal text-muted-3">· {{ t('shifts.receipts', { count: s.order_count }) }}</span></span>
      </li>
    </ul>
  </section>
</template>
