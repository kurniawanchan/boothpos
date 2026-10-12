<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { usePaginatedList } from '../../composables/usePaginatedList';
import { listTransfers, getTransfer, cancelTransfer, listDiscrepancies } from '../../api/stockTransfers';
import { useAuthStore } from '../../stores/auth';
import { useOutletStore } from '../../stores/outlet';
import { useToastStore } from '../../stores/toast';
import { formatDateTime } from '../../utils/date';
import PageHeader from '../../components/page/PageHeader.vue';
import FilterBar from '../../components/page/FilterBar.vue';
import DataTable from '../../components/ui/DataTable.vue';
import TablePagination from '../../components/ui/TablePagination.vue';
import StatusPill from '../../components/ui/StatusPill.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseSelect from '../../components/ui/BaseSelect.vue';
import ConfirmDialog from '../../components/ui/ConfirmDialog.vue';
import NewTransferDrawer from '../../components/stock/NewTransferDrawer.vue';
import TransferDetail from '../../components/stock/TransferDetail.vue';
import ReceiveTransferDialog from '../../components/stock/ReceiveTransferDialog.vue';

/**
 * 045 Stage 6 (T093) — Transfer stok antar outlet: daftar (status, outlet), tab Selisih, transfer baru, detail,
 * terima, batal. Hanya dengan lebih dari satu outlet (di satu outlet halaman menjelaskan bahwa transfer tidak tersedia).
 * Tombol aksi mengikuti izin (`stock.transfer.*`, cermin kosmetik) DAN `can` per transfer dari server.
 */
const { t } = useI18n();
const auth = useAuthStore();
const outletStore = useOutletStore();
const toast = useToastStore();

const multi = computed(() => outletStore.multi);
const { items, meta, loading, error, load, setPage, setFilter } = usePaginatedList(listTransfers);
const tab = ref('all'); // all | discrepancies
const discrepancies = ref([]);
const status = ref('');
const outletFilter = ref('');

const statusOptions = computed(() => [{ value: '', label: t('transfers.all_status') }, ...['in_transit', 'received', 'cancelled'].map((s) => ({ value: s, label: t(`transfers.status_${s}`) }))]);
const outletOptions = computed(() => [{ value: '', label: t('stock.all_outlets') }, ...outletStore.outlets.map((o) => ({ value: o.id, label: o.name }))]);
const columns = computed(() => [
  { key: 'number', label: t('transfers.col_number') },
  { key: 'route', label: t('transfers.col_route') },
  { key: 'status', label: t('transfers.col_status') },
  { key: 'line_count', label: t('transfers.col_lines') },
  { key: 'dispatched_at', label: t('transfers.col_dispatched') },
]);
const discColumns = computed(() => [
  { key: 'number', label: t('transfers.col_number') }, { key: 'item', label: t('transfers.col_item') },
  { key: 'shortfall', label: t('transfers.col_shortfall') }, { key: 'reason', label: t('transfers.col_note') },
]);
const TONE = { in_transit: 'warning', received: 'success', cancelled: 'neutral' };

onMounted(() => { if (multi.value) load(); });

async function showTab(next) {
  tab.value = next;
  if (next === 'discrepancies') discrepancies.value = (await listDiscrepancies()).data ?? [];
}

// --- nieuw / detail / terima / batal ---------------------------------------------------------------------------
const showNew = ref(false);
const detail = ref(null);
const detailOpen = ref(false);
const detailLoading = ref(false);
const receiving = ref(null);
const cancelTarget = ref(null);
const cancelling = ref(false);

async function openDetail(row) {
  detailOpen.value = true;
  detailLoading.value = true;
  try {
    detail.value = await getTransfer(row.id);
  } catch {
    detailOpen.value = false;
  } finally {
    detailLoading.value = false;
  }
}
async function afterChange(res) {
  receiving.value = null;
  cancelTarget.value = null;
  if (res?.id) detail.value = await getTransfer(res.id);
  await load();
}
async function doCancel() {
  cancelling.value = true;
  try {
    const res = await cancelTransfer(cancelTarget.value.id);
    toast.success(t('transfers.cancelled_done', { number: res.number }));
    await afterChange(res);
  } catch {
    cancelTarget.value = null; // 409 sudah di-toast handler pusat
    await load();
  } finally {
    cancelling.value = false;
  }
}
</script>

<template>
  <div class="pb-10">
    <PageHeader :title="t('transfers.title')" :description="t('transfers.subtitle')">
      <template v-if="multi && auth.hasPermission('stock.transfer.dispatch')" #actions>
        <BaseButton data-testid="new-transfer" @click="showNew = true">{{ t('transfers.new') }}</BaseButton>
      </template>
    </PageHeader>

    <p v-if="!multi" class="mx-[26px] border-2 border-line bg-white px-5 py-10 text-center text-[13px] text-muted-3" data-testid="transfers-single-outlet">{{ t('transfers.single_outlet') }}</p>

    <section v-else class="mx-[26px] border-2 border-line bg-white">
      <div role="tablist" class="flex border-b-2 border-line">
        <button v-for="k in ['all', 'discrepancies']" :key="k" type="button" role="tab" :aria-selected="tab === k" :data-testid="`tab-${k}`" class="px-4 py-2.5 text-[13px] font-semibold" :class="tab === k ? 'bg-brand text-white' : 'text-muted-5'" @click="showTab(k)">{{ t(`transfers.tab_${k}`) }}</button>
      </div>

      <template v-if="tab === 'all'">
        <FilterBar :searchable="false" :count="loading || error ? null : meta.total">
          <BaseSelect v-model="status" class="w-48" :options="statusOptions" @update:model-value="(v) => setFilter({ status: v || undefined })" />
          <BaseSelect v-model="outletFilter" class="w-52" :options="outletOptions" @update:model-value="(v) => setFilter({ outlet_id: v ? [v] : undefined })" />
        </FilterBar>
        <DataTable :columns="columns" :rows="items" :loading="loading" :error="error ? t('page.load_failed') : ''" :empty-message="t('transfers.empty')" @retry="load">
          <template #cell-number="{ row }"><button type="button" class="font-mono text-[12.5px] font-bold text-brand-active underline decoration-dotted" data-testid="open-transfer" @click="openDetail(row)">{{ row.number }}</button></template>
          <template #cell-route="{ row }">{{ row.from_outlet?.code }} → {{ row.to_outlet?.code }}</template>
          <template #cell-status="{ row }">
            <StatusPill :variant="TONE[row.status]">{{ t(`transfers.status_${row.status}`) }}</StatusPill>
            <StatusPill v-if="row.has_discrepancy" variant="danger" class="ml-1.5">{{ t('transfers.discrepancy') }}</StatusPill>
          </template>
          <template #cell-dispatched_at="{ row }">{{ formatDateTime(row.dispatched_at) }}</template>
        </DataTable>
        <TablePagination :meta="meta" @change="setPage" />
      </template>

      <DataTable v-else :columns="discColumns" :rows="discrepancies" :empty-message="t('transfers.no_discrepancies')">
        <template #cell-item="{ row }">{{ row.item?.name }}</template>
        <template #cell-shortfall="{ row }"><span class="font-mono font-bold text-danger-text">{{ row.shortfall }}</span> <span class="text-muted-3">/ {{ row.qty_sent }}</span></template>
      </DataTable>
    </section>

    <NewTransferDrawer :open="showNew" @close="showNew = false" @created="load" />
    <TransferDetail :open="detailOpen" :transfer="detail" :loading="detailLoading" @close="detailOpen = false" @receive="(tr) => (receiving = tr)" @cancel="(tr) => (cancelTarget = tr)" />
    <ReceiveTransferDialog :open="receiving !== null" :transfer="receiving" @close="receiving = null" @done="afterChange" />
    <ConfirmDialog :open="cancelTarget !== null" :title="t('transfers.cancel_title')" :message="t('transfers.cancel_confirm', { number: cancelTarget?.number ?? '' })" :confirm-label="t('transfers.cancel')" :loading="cancelling" @close="cancelTarget = null" @confirm="doCancel" />
  </div>
</template>
