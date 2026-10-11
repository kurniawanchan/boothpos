<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import EmptyState from '../../components/ui/EmptyState.vue';
import DataTable from '../../components/ui/DataTable.vue';
import ArtistTransactionsModal from '../../components/report/ArtistTransactionsModal.vue';
import { sumRows } from './sumRows';

// Tab "Rekap Penjual" — baris sudah difilter penjual oleh induk; Grand Total dijumlah dari baris yang TAMPIL
// (memilih satu penjual otomatis menjadikan Grand Total subtotal penjual itu).
const props = defineProps({
  rows: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  eventId: { type: [String, Number], default: '' },
});
const { t } = useI18n();

const totals = computed(() => sumRows(props.rows, ['total_sales'], ['total_units']));

const columns = computed(() => [
  { key: 'artist_name', label: t('reports.col_artist') },
  { key: 'total_units', label: t('reports.col_unit') },
  { key: 'total_sales', label: t('reports.col_sales') },
  { key: 'actions', label: '' },
]);

// F11.6 — drill-down transaksi per penjual.
const showTransactions = ref(false);
const target = ref(null);
function openTransactions(row) {
  target.value = row;
  showTransactions.value = true;
}
</script>

<template>
  <EmptyState v-if="!eventId" icon="ph-calendar-dots" :message="t('reports.pick_event_for_settlement')" />
  <DataTable
    v-else
    :columns="columns"
    :rows="rows"
    :loading="loading"
    row-key="artist_id"
    :empty-message="t('reports.no_active_artists_settlement')"
  >
    <!-- 040 — rekap ini POS-saja: tidak ada lagi kolom POS/pre-order. -->
    <template #cell-total_sales="{ row }">{{ formatIDR(row.total_sales) }}</template>
    <template #cell-actions="{ row }">
      <div class="flex items-center justify-end gap-3">
        <!-- F11.6 — drill-down tersedia untuk penjual manapun di rekap ini (memakai artist_id, yang selalu
             ada — lihat row-key di atas), bukan hanya yang sudah punya baris settlement, supaya "belum ada
             penjualan" juga bisa diverifikasi langsung sebagai daftar kosong. -->
        <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openTransactions(row)">{{ t('reports.transaction_detail') }}</button>
      </div>
    </template>
    <template #footer>
      <tr class="border-t-2 border-line-2 bg-surface-subtle font-bold">
        <td class="px-4 py-3 text-[13px]">{{ t('reports.grand_total') }}</td>
        <td class="px-4 py-3 text-[13px]">{{ totals.total_units }}</td>
        <td class="px-4 py-3 text-[13px]">{{ formatIDR(totals.total_sales) }}</td>
        <td class="px-4 py-3"></td>
      </tr>
    </template>
  </DataTable>

  <ArtistTransactionsModal
    :open="showTransactions"
    :artist-id="target?.artist_id"
    :artist-name="target?.artist_name"
    :event-id="eventId"
    @close="showTransactions = false"
  />
</template>
