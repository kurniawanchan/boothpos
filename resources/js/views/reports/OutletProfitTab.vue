<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import EmptyState from '../../components/ui/EmptyState.vue';
import DataTable from '../../components/ui/DataTable.vue';
import { sumRows } from './sumRows';

// F9.5 — laporan modal & laba kotor PER OUTLET (owner/admin saja). Baris sudah difilter outlet oleh induk.
const props = defineProps({
  rows: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  eventId: { type: [String, Number], default: '' },
});
const { t } = useI18n();

const totals = computed(() => sumRows(props.rows, [
  'total_sales', 'modal', 'gross_profit',
  'sales_pos', 'sales_preorder', 'modal_pos', 'modal_preorder', 'gross_profit_pos', 'gross_profit_preorder',
]));
// 033 — respons lama (tanpa rincian POS/pre-order) tidak menampilkan sub-baris di footer.
const hasSplit = computed(() => props.rows.some((r) => r.sales_pos != null));

const columns = computed(() => [
  { key: 'outlet_name', label: t('reports.col_artist') },
  { key: 'total_sales', label: t('reports.col_sales') },
  { key: 'modal', label: t('reports.col_modal') },
  { key: 'gross_profit', label: t('reports.col_gross_profit') },
]);

const splitLine = (pos, preorder) => t('reports.split_line', { pos: formatIDR(pos), preorder: formatIDR(preorder) });
</script>

<template>
  <EmptyState v-if="!eventId" icon="ph-calendar-dots" :message="t('reports.pick_event_for_artist_profit')" />
  <template v-else>
    <!-- Penjelasan ini WAJIB ada — angka di sini sengaja tidak dikurangi event_cost (lihat kriteria penerimaan
         F9.5), dan tanpa catatan ini pengguna gampang membacanya sebagai versi turunan/salah dari
         "Modal & Untung" tingkat event, padahal itu laporan lain. -->
    <div class="mx-5 mb-4 flex items-start gap-2.5 border border-line-2 bg-surface-subtle px-4 py-3">
      <i class="ph-duotone ph-info text-[16px] text-muted-3" aria-hidden="true"></i>
      <div class="flex flex-col gap-1.5">
        <p class="text-[12px] leading-relaxed text-muted-3">
          <i18n-t keypath="reports.artist_profit_note" tag="span">
            <template #bold><span class="font-semibold text-muted-4">{{ t('reports.artist_profit_note_bold') }}</span></template>
          </i18n-t>
        </p>
        <!-- 033 — laporan ini kini juga menghitung bagian pre-order yang terbayar. -->
        <p class="text-[12px] leading-relaxed text-muted-3">{{ t('reports.artist_profit_includes_preorder') }}</p>
      </div>
    </div>
    <DataTable :columns="columns" :rows="rows" :loading="loading" row-key="outlet_id" :empty-message="t('reports.no_artist_sales')">
      <!-- 033 — total (POS + pre-order) dengan rincian ringkas di bawahnya; tiga metrik x tiga kolom akan
           terlalu lebar, jadi rincian dibuat sub-baris. Respons lama tanpa field rincian: sub-baris disembunyikan. -->
      <template #cell-outlet_name="{ row }">
        {{ row.outlet_name }}
        <span v-if="row.historical" class="ml-1.5 border border-line px-1.5 py-0.5 text-[11px] font-semibold text-muted-4" :title="t('reports.historical_hint')" data-testid="historical-tag">{{ t('reports.historical') }}</span>
      </template>
      <template #cell-total_sales="{ row }">
        {{ formatIDR(row.total_sales) }}
        <div v-if="row.sales_pos != null" class="text-[11.5px] font-normal text-muted-3">{{ splitLine(row.sales_pos, row.sales_preorder) }}</div>
      </template>
      <template #cell-modal="{ row }">
        {{ formatIDR(row.modal) }}
        <div v-if="row.modal_pos != null" class="text-[11.5px] font-normal text-muted-3">{{ splitLine(row.modal_pos, row.modal_preorder) }}</div>
      </template>
      <template #cell-gross_profit="{ row }">
        <span class="font-semibold text-brand-active">{{ formatIDR(row.gross_profit) }}</span>
        <div v-if="row.gross_profit_pos != null" class="text-[11.5px] font-normal text-muted-3">{{ splitLine(row.gross_profit_pos, row.gross_profit_preorder) }}</div>
      </template>
      <template #footer>
        <tr class="border-t-2 border-line-2 bg-surface-subtle font-bold">
          <td class="px-4 py-3 text-[13px]">{{ t('reports.grand_total') }}</td>
          <td class="px-4 py-3 text-[13px]">
            {{ formatIDR(totals.total_sales) }}
            <div v-if="hasSplit" class="text-[11.5px] font-normal text-muted-3">{{ splitLine(totals.sales_pos, totals.sales_preorder) }}</div>
          </td>
          <td class="px-4 py-3 text-[13px]">
            {{ formatIDR(totals.modal) }}
            <div v-if="hasSplit" class="text-[11.5px] font-normal text-muted-3">{{ splitLine(totals.modal_pos, totals.modal_preorder) }}</div>
          </td>
          <td class="px-4 py-3 text-[13px] text-brand-active">
            {{ formatIDR(totals.gross_profit) }}
            <div v-if="hasSplit" class="text-[11.5px] font-normal text-muted-3">{{ splitLine(totals.gross_profit_pos, totals.gross_profit_preorder) }}</div>
          </td>
        </tr>
      </template>
    </DataTable>
  </template>
</template>
