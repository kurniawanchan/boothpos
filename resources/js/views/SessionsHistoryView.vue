<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { listSessions, getSession, sessionTransactions } from '../api/sessions';
import { listEvents } from '../api/events';
import { listUsers } from '../api/users';
import { usePaginatedList } from '../composables/usePaginatedList';
import { formatIDR } from '../utils/money';
import { formatDateTime } from '../utils/date';
import { paymentMethodLabel } from '../utils/paymentMethods';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseDrawer from '../components/ui/BaseDrawer.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import TransactionItemsModal from '../components/sales/TransactionItemsModal.vue';

/**
 * 054 (US12) — riwayat shift kasir: siapa membuka, kapan buka/tutup, kas awal, dan rincian (kas yang
 * diharapkan, dihitung, selisih) beserta transaksi di dalamnya. Hanya MENAMPILKAN: angka kas, rumus kas yang
 * diharapkan, dan siapa boleh melihat apa dihitung SERVER (owner/admin semua shift, selain itu hanya miliknya,
 * apa pun yang dikirim di query). Filter kasir hanya ditawarkan kepada pengelola toko (kosmetik; server tetap
 * memaksa cakupan).
 */
const { t } = useI18n();
const auth = useAuthStore();
const canSeeAll = computed(() => auth.canAccessMenu('settings'));

const filters = ref({ user_id: '', event_id: '', status: '', date_from: '', date_to: '' });
const events = ref([]);
const users = ref([]);

const { items, meta, params, loading, error, load, setPage, setFilter } = usePaginatedList(listSessions);

onMounted(async () => {
  load();
  try { events.value = (await listEvents({ per_page: 100 })).data ?? []; } catch { events.value = []; }
  if (canSeeAll.value) {
    try { users.value = (await listUsers({ per_page: 100 })).data ?? []; } catch { users.value = []; }
  }
});

function applyFilters() {
  const clean = Object.fromEntries(Object.entries(filters.value).map(([k, v]) => [k, v === '' ? undefined : v]));
  setFilter(clean);
}

const eventOptions = computed(() => [{ value: '', label: t('sessions_history.all_events') }, ...events.value.map((e) => ({ value: e.id, label: e.name }))]);
const userOptions = computed(() => [{ value: '', label: t('sessions_history.all_cashiers') }, ...users.value.map((u) => ({ value: u.id, label: u.name }))]);
const statusOptions = computed(() => [
  { value: '', label: t('sessions_history.all_status') },
  { value: 'open', label: t('sessions_history.status_open') },
  { value: 'closed', label: t('sessions_history.status_closed') },
]);

const columns = computed(() => [
  { key: 'cashier_name', label: t('sessions_history.col_cashier') },
  { key: 'event_name', label: t('sessions_history.col_event') },
  { key: 'opened_at', label: t('sessions_history.col_opened') },
  { key: 'closed_at', label: t('sessions_history.col_closed') },
  { key: 'opening_cash', label: t('sessions_history.col_opening') },
  { key: 'status', label: t('sessions_history.col_status') },
  { key: 'actions', label: '' },
]);

// --- rincian satu shift ------------------------------------------------------------------------
const selected = ref(null);
const detail = ref(null);
const detailLoading = ref(false);
const detailFailed = ref(false);
const txRows = ref([]);
const txMeta = ref({ current_page: 1, per_page: 25, total: 0, last_page: 1 });
const orderId = ref(null);

async function openDetail(row) {
  selected.value = row;
  detail.value = null;
  txRows.value = [];
  detailLoading.value = true;
  detailFailed.value = false;
  try {
    const [d] = await Promise.all([getSession(row.id), loadTransactions(1)]);
    detail.value = d;
  } catch {
    detailFailed.value = true;
  } finally {
    detailLoading.value = false;
  }
}

async function loadTransactions(page) {
  const res = await sessionTransactions(selected.value.id, { page });
  txRows.value = res.data;
  txMeta.value = res.meta;
}

const isClosed = computed(() => detail.value?.status === 'closed');
const difference = computed(() => Number(detail.value?.cash_difference ?? 0));
const txColumns = computed(() => [
  { key: 'order_number', label: t('sessions_history.col_transaction') },
  { key: 'created_at', label: t('sessions_history.col_time') },
  { key: 'kind', label: t('sessions_history.col_kind') },
  { key: 'amount', label: t('sessions_history.col_amount') },
]);
</script>

<template>
  <div class="flex flex-col gap-4 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <BaseSelect v-if="canSeeAll" v-model="filters.user_id" class="w-48" :placeholder="t('sessions_history.all_cashiers')" :options="userOptions" @update:model-value="applyFilters" />
      <BaseSelect v-model="filters.event_id" class="w-48" :placeholder="t('sessions_history.all_events')" :options="eventOptions" @update:model-value="applyFilters" />
      <BaseSelect v-model="filters.status" class="w-44" :placeholder="t('sessions_history.all_status')" :options="statusOptions" @update:model-value="applyFilters" />
      <input v-model="filters.date_from" type="date" :aria-label="t('sessions_history.date_from')" class="h-10 rounded-lg border border-line-2 bg-white px-3 text-[12.5px] outline-none focus:border-brand-active" @change="applyFilters" />
      <input v-model="filters.date_to" type="date" :aria-label="t('sessions_history.date_to')" class="h-10 rounded-lg border border-line-2 bg-white px-3 text-[12.5px] outline-none focus:border-brand-active" @change="applyFilters" />
    </div>

    <div v-if="error" class="flex flex-col items-start gap-2 text-[13px]">
      <span class="text-danger-text">{{ t('sessions_history.load_failed') }}</span>
      <BaseButton variant="secondary" size="sm" @click="load">{{ t('common.retry') }}</BaseButton>
    </div>

    <div v-else class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('sessions_history.empty')">
        <template #cell-opened_at="{ row }">{{ formatDateTime(row.opened_at) }}</template>
        <template #cell-closed_at="{ row }">
          <span v-if="row.closed_at">{{ formatDateTime(row.closed_at) }}</span>
          <span v-else class="text-muted-3">—</span>
        </template>
        <template #cell-opening_cash="{ row }"><span class="whitespace-nowrap">{{ formatIDR(row.opening_cash) }}</span></template>
        <template #cell-status="{ row }">
          <StatusPill :variant="row.status === 'open' ? 'mint' : 'neutral'">{{ row.status === 'open' ? t('sessions_history.status_open') : t('sessions_history.status_closed') }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <button type="button" class="whitespace-nowrap text-[12.5px] font-semibold text-brand-active hover:underline" @click="openDetail(row)">{{ t('sessions_history.view_detail') }}</button>
        </template>
      </DataTable>
      <TablePagination v-if="meta.total > 0" :meta="meta" @change="setPage" />
    </div>

    <BaseDrawer :open="selected !== null" :title="selected ? t('sessions_history.detail_title', { name: selected.cashier_name }) : ''" max-width-class="max-w-[720px]" @close="selected = null">
      <div class="flex flex-col gap-4 p-6" data-testid="session-detail">
        <p v-if="detailLoading" class="text-[13px] text-muted-3">{{ t('sessions_history.loading') }}</p>
        <div v-else-if="detailFailed" class="flex flex-col items-start gap-2 text-[13px]">
          <span class="text-danger-text">{{ t('sessions_history.load_failed') }}</span>
          <BaseButton variant="secondary" size="sm" @click="openDetail(selected)">{{ t('common.retry') }}</BaseButton>
        </div>
        <template v-else-if="detail">
          <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="flex flex-col gap-1 rounded-card border border-line-2 p-3"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('sessions_history.opening_cash') }}</span><span class="font-extrabold">{{ formatIDR(detail.opening_cash) }}</span></div>
            <div class="flex flex-col gap-1 rounded-card border border-line-2 p-3"><span class="text-[11.5px] font-semibold text-muted-2">{{ isClosed ? t('sessions_history.expected_cash') : t('sessions_history.expected_so_far') }}</span><span class="font-extrabold">{{ formatIDR(detail.expected_cash) }}</span></div>
            <template v-if="isClosed">
              <div class="flex flex-col gap-1 rounded-card border border-line-2 p-3"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('sessions_history.counted_cash') }}</span><span class="font-extrabold">{{ formatIDR(detail.closing_cash) }}</span></div>
              <div class="flex flex-col gap-1 rounded-card border border-line-2 p-3"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('sessions_history.difference') }}</span><span class="font-extrabold" :class="difference === 0 ? '' : 'text-danger-text'">{{ formatIDR(detail.cash_difference) }}</span></div>
            </template>
            <div v-else class="col-span-2 flex items-center rounded-card bg-line-7 p-3 text-[12.5px] text-muted-4">{{ t('sessions_history.not_closed_yet') }}</div>
          </div>
          <p class="text-[12.5px] text-muted-4">{{ t('sessions_history.sales_summary', { count: detail.order_count, total: formatIDR(detail.total_sales) }) }}</p>
          <ul v-if="detail.by_method.length" class="flex flex-wrap gap-2 text-[12px]">
            <li v-for="m in detail.by_method" :key="m.method" class="rounded-full bg-line-7 px-2.5 py-1 font-semibold text-muted-4">{{ paymentMethodLabel(t, m.method) }} · {{ formatIDR(m.amount) }}</li>
          </ul>

          <div class="overflow-hidden rounded-card border border-line-2">
            <DataTable :columns="txColumns" :rows="txRows" row-key="order_id" :empty-message="t('sessions_history.no_transactions')">
              <template #cell-order_number="{ row }">
                <button type="button" class="whitespace-nowrap font-mono text-[12.5px] font-bold text-brand-active underline decoration-dotted" :class="{ 'line-through': row.status === 'voided' }" @click="orderId = row.order_id">{{ row.order_number }}</button>
              </template>
              <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
              <template #cell-kind="{ row }">
                <span v-if="row.kind === 'payment_received'" class="text-[12px] text-muted-4">{{ t('sessions_history.kind_payment_received', { method: paymentMethodLabel(t, row.method) }) }}</span>
                <span v-else class="text-[12px] text-muted-4">{{ row.status === 'voided' ? t('sessions_history.kind_voided') : t('sessions_history.kind_sale') }}</span>
              </template>
              <template #cell-amount="{ row }"><span class="whitespace-nowrap">{{ formatIDR(row.amount) }}</span></template>
            </DataTable>
            <TablePagination v-if="txMeta.total > 0" :meta="txMeta" @change="loadTransactions" />
          </div>
        </template>
      </div>
    </BaseDrawer>

    <TransactionItemsModal :open="orderId !== null" :order-id="orderId" @close="orderId = null" />
  </div>
</template>
