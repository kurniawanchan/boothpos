<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import DataTable from '../../components/ui/DataTable.vue';
import TabBar from '../../components/page/TabBar.vue';
import StatusPill from '../../components/ui/StatusPill.vue';
import PreorderReportDetailModal from '../../components/report/PreorderReportDetailModal.vue';
import { sumRows } from './sumRows';

// 010-split-payment-preorder-reports (US6) — satu baris per kombinasi status × payment_completeness, persis
// bentuk agregasi API (research.md R6). 012 (US2) — toggle ringkasan vs per-penjual; `view` dipegang induk
// (v-model:view) karena induk yang memuat breakdown secara lazy dan menampilkan filter penjual hanya di
// tampilan "Per Penjual". `byArtist` sudah difilter penjual oleh induk.
const props = defineProps({
  view: { type: String, default: 'summary' }, // 'summary' | 'artist'
  stats: { type: Array, default: null },
  byArtist: { type: Array, default: null },
  subtotals: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['update:view']);
const { t } = useI18n();

// REUSE kunci label status PreordersView.vue (preorders.step_* + events_sessions.status_cancelled) supaya
// teks status konsisten di seluruh aplikasi.
const STATUS_LABEL = computed(() => ({
  ordered: t('preorders.step_ordered'),
  dp_paid: t('preorders.step_dp_paid'),
  arrived: t('preorders.step_arrived'),
  settled: t('preorders.step_settled'),
  handed_over: t('preorders.step_handed_over'),
  cancelled: t('events_sessions.status_cancelled'),
}));
const COMPLETENESS_LABEL = computed(() => ({
  unpaid: t('reports.preorder_completeness_unpaid'),
  partial: t('reports.preorder_completeness_partial'),
  paid: t('reports.preorder_completeness_paid'),
}));
const STATUS_TONE = { ordered: 'neutral', dp_paid: 'info', arrived: 'accent', settled: 'success', handed_over: 'success', cancelled: 'danger' };
const COMPLETENESS_TONE = { unpaid: 'danger', partial: 'warning', paid: 'success' };

const viewTabs = computed(() => [
  { key: 'summary', label: t('reports.preorder_view_summary') },
  { key: 'artist', label: t('reports.preorder_view_by_artist') },
]);

const summaryTotals = computed(() => sumRows(props.stats ?? [], ['total_order_value', 'total_collected', 'total_outstanding'], ['preorder_count']));
const byArtistTotals = computed(() => sumRows(props.byArtist ?? [], ['total_order_value', 'total_collected', 'total_outstanding'], ['preorder_count']));

// 039 — baris yang DITAMPILKAN pada tabel Per Penjual: baris data (sesudah filter penjual) dengan satu baris
// Subtotal sintetis disisipkan tepat sesudah baris terakhir tiap penjual (server menjamin baris satu penjual
// berurutan). Baris sintetis TIDAK pernah dipakai Grand Total (membaca baris data saja) maupun drill-down
// Detail (tombolnya disembunyikan untuk baris Subtotal).
const byArtistDisplayRows = computed(() => {
  const rows = props.byArtist ?? [];
  const bySeller = new Map(props.subtotals.map((s) => [String(s.artist_id), s]));
  const out = [];
  rows.forEach((row, i) => {
    out.push(row);
    const next = rows[i + 1];
    if (next && String(next.artist_id) === String(row.artist_id)) return;
    const sub = bySeller.get(String(row.artist_id));
    if (sub) out.push({ ...sub, id: `subtotal__${sub.artist_id}`, _subtotal: true });
  });
  return out;
});
const rowClass = (row) => (row._subtotal ? 'bg-surface-subtle font-semibold border-t border-line-2' : '');

const summaryColumns = computed(() => [
  { key: 'status', label: t('reports.col_status') },
  { key: 'payment_completeness', label: t('reports.preorder_col_payment_completeness') },
  { key: 'preorder_count', label: t('reports.preorder_col_count') },
  { key: 'total_order_value', label: t('reports.preorder_col_order_value') },
  { key: 'total_collected', label: t('reports.preorder_col_collected') },
  { key: 'total_outstanding', label: t('reports.preorder_col_outstanding') },
  { key: 'actions', label: '' },
]);
const artistColumns = computed(() => [{ key: 'artist_name', label: t('reports.col_artist') }, ...summaryColumns.value]);

// 012 (US3, T016) — drill-down ke daftar pre-order individual, dipicu HANYA saat baris diklik.
const showDetail = ref(false);
const target = ref(null);
function openDetail(row) {
  target.value = row;
  showDetail.value = true;
}
</script>

<template>
  <TabBar class="mb-1" :tabs="viewTabs" :model-value="view" :label="t('reports.tab_preorder')" @update:model-value="emit('update:view', $event)" />

  <DataTable
    v-if="view === 'summary'"
    :columns="summaryColumns"
    :rows="stats ?? []"
    :loading="loading"
    :empty-message="t('reports.no_preorder_report_data')"
  >
    <template #cell-status="{ row }"><StatusPill :variant="STATUS_TONE[row.status] ?? 'neutral'">{{ STATUS_LABEL[row.status] ?? row.status }}</StatusPill></template>
    <template #cell-payment_completeness="{ row }"><StatusPill :variant="COMPLETENESS_TONE[row.payment_completeness] ?? 'neutral'">{{ COMPLETENESS_LABEL[row.payment_completeness] ?? row.payment_completeness }}</StatusPill></template>
    <template #cell-total_order_value="{ row }">{{ formatIDR(row.total_order_value) }}</template>
    <template #cell-total_collected="{ row }">{{ formatIDR(row.total_collected) }}</template>
    <template #cell-total_outstanding="{ row }">{{ formatIDR(row.total_outstanding) }}</template>
    <template #cell-actions="{ row }">
      <div class="flex justify-end">
        <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openDetail(row)">{{ t('reports.preorder_detail_action') }}</button>
      </div>
    </template>
    <template #footer>
      <tr class="border-t-2 border-line-2 bg-surface-subtle font-bold">
        <td class="px-4 py-3 text-[13px]" colspan="2">{{ t('reports.grand_total') }}</td>
        <td class="px-4 py-3 text-[13px]">{{ summaryTotals.preorder_count }}</td>
        <td class="px-4 py-3 text-[13px]">{{ formatIDR(summaryTotals.total_order_value) }}</td>
        <td class="px-4 py-3 text-[13px]">{{ formatIDR(summaryTotals.total_collected) }}</td>
        <td class="px-4 py-3 text-[13px]">{{ formatIDR(summaryTotals.total_outstanding) }}</td>
        <td class="px-4 py-3"></td>
      </tr>
    </template>
  </DataTable>
  <DataTable
    v-else
    :columns="artistColumns"
    :rows="byArtistDisplayRows"
    :row-class="rowClass"
    :loading="loading"
    :empty-message="t('reports.no_preorder_report_data')"
  >
    <!-- 039: baris Subtotal (sintetis) — label di kolom penjual, sel status/kelengkapan kosong, tanpa Detail. -->
    <template #cell-artist_name="{ row }">
      <span v-if="row._subtotal" class="font-bold">{{ t('reports.subtotal') }} — {{ row.artist_name }}</span>
      <template v-else>{{ row.artist_name }}</template>
    </template>
    <template #cell-status="{ row }"><StatusPill v-if="!row._subtotal" :variant="STATUS_TONE[row.status] ?? 'neutral'">{{ STATUS_LABEL[row.status] ?? row.status }}</StatusPill></template>
    <template #cell-payment_completeness="{ row }"><StatusPill v-if="!row._subtotal" :variant="COMPLETENESS_TONE[row.payment_completeness] ?? 'neutral'">{{ COMPLETENESS_LABEL[row.payment_completeness] ?? row.payment_completeness }}</StatusPill></template>
    <template #cell-total_order_value="{ row }">{{ formatIDR(row.total_order_value) }}</template>
    <template #cell-total_collected="{ row }">{{ formatIDR(row.total_collected) }}</template>
    <template #cell-total_outstanding="{ row }">{{ formatIDR(row.total_outstanding) }}</template>
    <template #cell-actions="{ row }">
      <div v-if="!row._subtotal" class="flex justify-end">
        <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openDetail(row)">{{ t('reports.preorder_detail_action') }}</button>
      </div>
    </template>
    <template #footer>
      <tr class="border-t-2 border-line-2 bg-surface-subtle font-bold">
        <td class="px-4 py-3 text-[13px]" colspan="3">{{ t('reports.grand_total') }}</td>
        <td class="px-4 py-3 text-[13px]">{{ byArtistTotals.preorder_count }}</td>
        <td class="px-4 py-3 text-[13px]">{{ formatIDR(byArtistTotals.total_order_value) }}</td>
        <td class="px-4 py-3 text-[13px]">{{ formatIDR(byArtistTotals.total_collected) }}</td>
        <td class="px-4 py-3 text-[13px]">{{ formatIDR(byArtistTotals.total_outstanding) }}</td>
        <td class="px-4 py-3"></td>
      </tr>
    </template>
  </DataTable>

  <PreorderReportDetailModal
    :open="showDetail"
    :status="target?.status"
    :payment-completeness="target?.payment_completeness"
    :artist-id="target?.artist_id"
    @close="showDetail = false"
  />
</template>
