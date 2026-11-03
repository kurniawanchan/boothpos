<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listSelfServiceOrders } from '../../api/selfServiceOrders';
import { usePaginatedList } from '../../composables/usePaginatedList';
import { usePolling } from '../../composables/usePolling';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { STATUS_FILTERS, statusOf } from '../../utils/selfServiceStatus';
import BaseButton from '../ui/BaseButton.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import DataTable from '../ui/DataTable.vue';
import ErrorState from '../ui/ErrorState.vue';
import StatusPill from '../ui/StatusPill.vue';
import TablePagination from '../ui/TablePagination.vue';
import SelfServiceOrderDrawer from './SelfServiceOrderDrawer.vue';

// 090 / US6 — tab Self-Service di Penjualan: antrean pesanan mandiri yang menunggu verifikasi dan pengiriman ke dapur.
// Menyegarkan diri tiap 8 detik (berhenti saat tab peramban tersembunyi) supaya pesanan baru muncul tanpa muat ulang.
const emit = defineEmits(['counts']);
const { t } = useI18n();

const statusFilter = ref('pending');
const search = ref('');
const selectedId = ref(null);

const list = usePaginatedList((params) => listSelfServiceOrders(params), { per_page: 25 });

function apply() {
  const statuses = STATUS_FILTERS[statusFilter.value] ?? [];
  list.params.status = statuses.length ? statuses : undefined;
  list.params.q = search.value.trim() || undefined;
  list.params.page = 1;
  return list.load();
}

const statusOptions = computed(() => [
  { value: 'pending', label: t('self_service_staff.filter_pending') },
  { value: 'awaiting_verification', label: t('self_service_staff.status_awaiting_verification') },
  { value: 'verified', label: t('self_service_staff.status_verified') },
  { value: 'dispatched', label: t('self_service_staff.status_dispatched') },
  { value: 'rejected', label: t('self_service_staff.status_rejected') },
  { value: 'all', label: t('self_service_staff.filter_all') },
]);

const columns = computed(() => [
  { key: 'reference_no', label: t('self_service_staff.col_ref') },
  { key: 'table', label: t('self_service_staff.col_table') },
  { key: 'created_at', label: t('self_service_staff.col_time') },
  { key: 'service_type', label: t('self_service_staff.col_type') },
  { key: 'total_amount', label: t('self_service_staff.col_total') },
  { key: 'status', label: t('self_service_staff.col_status') },
  { key: 'actions', label: '' },
]);

const emptyMessage = computed(() => (statusFilter.value === 'pending' && !search.value ? t('self_service_staff.empty_pending') : t('self_service_staff.empty')));

watch(() => list.meta.value?.counts, (counts) => counts && emit('counts', counts), { immediate: true });

let searchTimer = null;
watch(search, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(apply, 300);
});
watch(statusFilter, apply);

// Penyegaran berkala tidak mengembalikan ke halaman 1 dan tidak mengosongkan daftar saat gagal (usePaginatedList menahan galat).
const polling = usePolling(() => list.load(), { interval: 8000, immediate: false });

onMounted(async () => {
  await apply();
  polling.start();
});
onBeforeUnmount(() => {
  clearTimeout(searchTimer);
  polling.stop();
});

const typeLabel = (type) => (type === 'takeaway' ? t('self_service_staff.type_takeaway') : t('self_service_staff.type_dine_in'));
</script>

<template>
  <section class="mx-[26px] flex flex-col gap-3.5" data-testid="self-service-panel">
    <div class="flex flex-wrap items-end gap-3">
      <BaseSelect v-model="statusFilter" :label="t('self_service_staff.filter_status')" :options="statusOptions" class="w-56" />
      <label class="flex flex-col gap-1.5">
        <span class="text-[12.5px] font-semibold text-muted-4">{{ t('self_service_staff.search_label') }}</span>
        <input
          v-model="search"
          type="search"
          :placeholder="t('self_service_staff.search_placeholder')"
          class="h-[46px] w-64 rounded-lg border border-line bg-white px-3.5 text-[14px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
        />
      </label>
      <BaseButton variant="secondary" size="sm" :loading="list.loading.value" @click="list.load()">{{ t('self_service_staff.refresh') }}</BaseButton>
    </div>

    <ErrorState v-if="list.error.value && !list.items.value.length" @retry="list.load()" />

    <div v-else class="overflow-hidden border-2 border-line bg-white">
      <DataTable :columns="columns" :rows="list.items.value" :loading="list.loading.value && !list.items.value.length" :empty-message="emptyMessage">
        <template #cell-reference_no="{ row }">
          <span class="font-mono text-[12.5px] font-bold text-ink">{{ row.reference_no }}</span>
          <span v-if="row.customer" class="block text-[11.5px] text-muted-3" data-testid="row-customer">{{ row.customer.name }} · {{ row.customer.phone }}</span>
          <span v-else class="block text-[11.5px] text-muted-3">{{ t('self_service_staff.guest') }}</span>
        </template>
        <template #cell-table="{ row }"><span class="font-bold">{{ row.table.name }}</span></template>
        <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
        <template #cell-service_type="{ row }">{{ typeLabel(row.service_type) }}</template>
        <template #cell-total_amount="{ row }">
          <span class="font-bold">{{ formatIDR(row.total_amount) }}</span>
          <span class="block text-[11.5px] text-muted-3">{{ t('self_service_staff.items_count', { n: row.item_count }) }}</span>
        </template>
        <template #cell-status="{ row }">
          <StatusPill :variant="statusOf(row.status).variant">{{ t(statusOf(row.status).label) }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end">
            <button type="button" class="text-[12.5px] font-semibold text-brand-active hover:underline" @click="selectedId = row.id">{{ t('self_service_staff.open') }}</button>
          </div>
        </template>
      </DataTable>
      <TablePagination v-if="list.meta.value.total > 0" :meta="list.meta.value" @change="list.setPage" />
    </div>

    <SelfServiceOrderDrawer :order-id="selectedId" @close="selectedId = null" @changed="list.load()" />
  </section>
</template>
