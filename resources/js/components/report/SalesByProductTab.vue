<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { salesByProductReport, salesByProductTransactions, exportSalesByProduct } from '../../api/reports';
import { saveBlob } from '../../utils/saveBlob';
import { useToastStore } from '../../stores/toast';
import BaseMultiSelect from '../ui/BaseMultiSelect.vue';
import { useDebouncedFn } from '../../composables/useDebouncedFn';
import { formatIDR } from '../../utils/money';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import BaseButton from '../ui/BaseButton.vue';
import ReportTransactionsModal from './ReportTransactionsModal.vue';

/**
 * 054 (US10) — tab "Penjualan per produk": unit & nilai per produk, penanda Terlaris, pencarian nama
 * produk/varian, dan daftar transaksi per produk. Semua angka, peringkat, dan penanda dihitung SERVER
 * (aturan Terlaris ditampilkan apa adanya dari `best_seller_rule`); layar hanya menampilkan. Peringkat
 * tidak berubah saat mencari — pencarian hanya menyempitkan baris.
 */
const props = defineProps({ eventId: { type: [Number, String], default: '' } });
const { t } = useI18n();

const report = ref(null);
const loading = ref(false);
const failed = ref(false);
const search = ref('');
const toast = useToastStore();
// Filter seller / kategori / produk (multi-pilih; kosong = semua). Pilihan datang dari server (`options`)
// supaya hanya menawarkan yang punya penjualan pada cakupan event ini.
const artistIds = ref([]);
const categoryIds = ref([]);
const productIds = ref([]);
const exporting = ref(false);
const expanded = ref(new Set());
const detail = ref(null);

function scopeParams() {
  return {
    event_id: props.eventId || undefined,
    q: search.value.trim() || undefined,
    artist_id: artistIds.value.length ? artistIds.value : undefined,
    category_id: categoryIds.value.length ? categoryIds.value : undefined,
    product_id: productIds.value.length ? productIds.value : undefined,
  };
}

async function exportExcel() {
  exporting.value = true;
  try {
    saveBlob(await exportSalesByProduct(scopeParams()), 'penjualan-per-produk.xlsx');
  } catch {
    toast.error(t('sales_reports.export_failed'));
  } finally {
    exporting.value = false;
  }
}

let seq = 0;
async function load() {
  const mine = ++seq;
  loading.value = true;
  failed.value = false;
  try {
    const res = await salesByProductReport(scopeParams());
    if (mine === seq) report.value = res; // respons lama tak boleh menimpa yang terbaru
  } catch {
    if (mine === seq) failed.value = true;
  } finally {
    if (mine === seq) loading.value = false;
  }
}
const loadDebounced = useDebouncedFn(load, 300);

onMounted(load);
watch(() => props.eventId, load);
watch(search, loadDebounced);
watch([artistIds, categoryIds, productIds], load);

const rows = computed(() => report.value?.data ?? []);
const columns = computed(() => [
  { key: 'rank', label: t('sales_reports.col_rank') },
  { key: 'product_name', label: t('sales_reports.col_product') },
  { key: 'units', label: t('sales_reports.col_units') },
  { key: 'sales', label: t('sales_reports.col_sales') },
  { key: 'stock_in', label: t('sales_reports.col_stock_in') },
  { key: 'stock_remaining', label: t('sales_reports.col_stock_remaining') },
  { key: 'transaction_count', label: t('sales_reports.col_transactions') },
  { key: 'actions', label: '' },
]);

function toggleVariants(id) {
  const next = new Set(expanded.value);
  if (next.has(id)) next.delete(id); else next.add(id);
  expanded.value = next;
}

const detailLoader = computed(() => (page) => salesByProductTransactions(detail.value.product_id, { ...scopeParams(), page }));
const stats = computed(() => {
  const x = report.value?.totals;
  if (!x) return [];
  return [
    { id: 'transactions', label: t('sales_reports.stat_transactions'), value: x.transaction_count },
    { id: 'units', label: t('sales_reports.stat_units'), value: x.units },
    { id: 'sales', label: t('sales_reports.stat_sales'), value: formatIDR(x.sales) },
    { id: 'stock_in', label: t('sales_reports.stat_stock_in'), value: x.stock_in },
    { id: 'stock_remaining', label: t('sales_reports.stat_stock_remaining'), value: x.stock_remaining },
  ];
});
const options = computed(() => report.value?.options ?? { artists: [], categories: [], products: [] });
</script>

<template>
  <div class="flex flex-col gap-3" data-testid="sales-by-product">
    <div class="flex flex-wrap items-center gap-3">
      <div class="relative w-full max-w-[320px]">
        <i class="ph-duotone ph-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-[14px] text-muted-3" aria-hidden="true"></i>
        <input
          v-model="search"
          type="search"
          :placeholder="t('sales_reports.search_product')"
          :aria-label="t('sales_reports.search_product')"
          class="h-10 w-full rounded-lg border border-line-2 bg-white py-2 pl-8 pr-3 text-[12.5px] outline-none focus:border-brand-active"
        />
      </div>
      <BaseButton variant="secondary" size="sm" :disabled="exporting || !rows.length" data-testid="export-product-report" @click="exportExcel">
        <i class="ph-duotone ph-microsoft-excel-logo" aria-hidden="true"></i> {{ t('sales_reports.export_excel') }}
      </BaseButton>
      <span v-if="report" class="text-[12px] text-muted-3" data-testid="stock-note">{{ t('sales_reports.stock_note') }}</span>
      <span v-if="report" class="text-[12px] text-muted-3" data-testid="best-seller-rule">
        {{ t('sales_reports.best_seller_rule', { top: report.best_seller_rule.top }) }}
      </span>
    </div>

    <div v-if="stats.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-testid="product-stats">
      <div v-for="st in stats" :key="st.id" class="rounded-card border border-line-2 bg-white px-4 py-3" :data-testid="`stat-${st.id}`">
        <div class="text-[11.5px] font-semibold text-muted-3">{{ st.label }}</div>
        <div class="mt-1 text-[20px] font-bold leading-tight">{{ st.value }}</div>
      </div>
    </div>

    <div class="flex flex-wrap items-end gap-3" data-testid="product-filters">
      <BaseMultiSelect v-model="artistIds" class="w-[220px]" :label="t('sales_reports.filter_seller')" :options="options.artists" :all-label="t('master_data.all_artists')" :placeholder="t('master_data.all_artists')" />
      <BaseMultiSelect v-model="categoryIds" class="w-[220px]" :label="t('sales_reports.filter_category')" :options="options.categories" :all-label="t('master_data.all_categories')" :placeholder="t('master_data.all_categories')" />
      <BaseMultiSelect v-model="productIds" class="w-[260px]" :label="t('sales_reports.filter_product')" :options="options.products" :all-label="t('sales_reports.all_products')" :placeholder="t('sales_reports.all_products')" />
    </div>

    <div v-if="failed" class="flex flex-col items-start gap-2 text-[13px]">
      <span class="text-danger-text">{{ t('sales_reports.load_failed') }}</span>
      <BaseButton variant="secondary" size="sm" @click="load">{{ t('common.retry') }}</BaseButton>
    </div>

    <div v-else class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="rows" row-key="product_id" :loading="loading" :empty-message="search ? t('sales_reports.no_matching_products') : t('sales_reports.no_sales')">
        <template #cell-rank="{ row }"><span class="font-semibold">{{ row.rank }}</span></template>
        <template #cell-product_name="{ row }">
          <div class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-semibold">{{ row.product_name }}</span>
              <StatusPill v-if="row.is_best_seller" variant="mint" data-testid="best-seller-badge">
                <i class="ph-duotone ph-crown-simple text-[12px]" aria-hidden="true"></i> {{ t('sales_reports.best_seller') }}
              </StatusPill>
            </div>
            <span class="text-[11.5px] text-muted-3" data-testid="product-meta">{{ row.artist_name }}<template v-if="row.category_name"> · {{ row.category_name }}</template></span>
            <button v-if="row.variants.length > 1" type="button" class="w-fit text-[11.5px] font-semibold text-brand-active hover:underline" @click="toggleVariants(row.product_id)">
              {{ expanded.has(row.product_id) ? t('sales_reports.hide_variants') : t('sales_reports.show_variants', { count: row.variants.length }) }}
            </button>
            <ul v-if="expanded.has(row.product_id)" class="mt-1 flex flex-col gap-0.5 text-[11.5px] text-muted-4">
              <li v-for="v in row.variants" :key="v.variant_id" class="flex flex-wrap items-center gap-1.5" :class="v.is_top ? 'font-semibold text-ink' : ''" :data-testid="v.is_top ? 'top-variant' : 'variant'">
                <span>{{ v.variant_name || '—' }} · {{ v.units }} · {{ formatIDR(v.sales) }} · {{ t('sales_reports.col_stock_in') }} {{ v.stock_in }} · {{ t('sales_reports.col_stock_remaining') }} {{ v.stock_remaining }}</span>
                <StatusPill v-if="v.is_top" variant="warn"><i class="ph-duotone ph-fire text-[11px]" aria-hidden="true"></i> {{ t('sales_reports.top_variant') }}</StatusPill>
              </li>
            </ul>
          </div>
        </template>
        <template #cell-units="{ row }">
          <div class="flex flex-col">
            <span>{{ row.units }}</span>
            <span v-if="row.preorder_units > 0" class="whitespace-nowrap text-[11px] text-muted-3">{{ t('sales_reports.pos_preorder_split', { pos: row.pos_units, pre: row.preorder_units }) }}</span>
          </div>
        </template>
        <template #cell-sales="{ row }"><span class="whitespace-nowrap">{{ formatIDR(row.sales) }}</span></template>
        <template #cell-stock_in="{ row }"><span data-testid="stock-in">{{ row.stock_in }}</span></template>
        <template #cell-stock_remaining="{ row }"><span class="font-semibold" data-testid="stock-remaining">{{ row.stock_remaining }}</span></template>
        <template #cell-actions="{ row }">
          <button type="button" class="whitespace-nowrap text-[12.5px] font-semibold text-brand-active hover:underline" data-testid="open-transactions" @click="detail = row">{{ t('sales_reports.view_transactions') }}</button>
        </template>
      </DataTable>
      <div v-if="report && rows.length" class="flex flex-wrap items-baseline justify-between gap-2 border-t border-line-3 bg-surface-subtle px-4 py-3 text-[12.5px] font-semibold" data-testid="product-totals">
        <span>{{ t('sales_reports.grand_total') }}</span>
        <span>{{ report.totals.units }} {{ t('sales_reports.units_short') }} · {{ formatIDR(report.totals.sales) }} · {{ t('sales_reports.transactions_n', { count: report.totals.transaction_count }) }}</span>
      </div>
    </div>

    <ReportTransactionsModal
      :open="detail !== null"
      :title="detail ? t('sales_reports.transactions_of', { name: detail.product_name }) : ''"
      :loader="detailLoader"
      :amount-label="t('sales_reports.col_product_amount')"
      show-qty
      @close="detail = null"
    />
  </div>
</template>
