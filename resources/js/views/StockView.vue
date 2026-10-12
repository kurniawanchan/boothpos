<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listMovements, createAdjustment, lowStock } from '../api/stock';
import { lookupVariants } from '../api/products';
import { exportMasterData } from '../api/masterData';
import { useAuthStore } from '../stores/auth';
import { useOutletStore } from '../stores/outlet';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import { formatDateTime } from '../utils/date';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import PageHeader from '../components/page/PageHeader.vue';
import FilterBar from '../components/page/FilterBar.vue';
import MasterDataImportModal from '../components/masterData/MasterDataImportModal.vue';
import ProductDetailModal from '../components/product/ProductDetailModal.vue';
import { MOVEMENT_TYPES, movementTypeLabelKey, movementTypeVariant } from '../utils/stockMovements';

const auth = useAuthStore();
const outletStore = useOutletStore();
// 045 Stage 5 — stok adalah saldo per outlet: kolom/filter/pilihan outlet hanya bila ada lebih dari satu outlet.
const multi = computed(() => outletStore.multi);
const { t } = useI18n();
const toast = useToastStore();

const { items, meta, loading, error, load, setPage, setFilter } = usePaginatedList(listMovements);

// 042-dashboard-revamp — butir "Item stok menipis" di Dashboard membuka layar ini dengan `?low=1`. Layar ini adalah
// buku besar pergerakan (bukan daftar stok), jadi `low=1` menampilkan DAFTAR VARIAN yang menipis dari
// GET /stock/low — predikat yang sama dengan yang dihitung Dashboard (is_active, low_stock_alert terisi,
// current_stock <= low_stock_alert) — sehingga jumlah baris = angka di butir itu. Chip "Hanya stok menipis"
// melepas filter dan kembali ke buku besar. Tanpa `?low` perilaku layar tidak berubah sama sekali.
const route = useRoute();
const router = useRouter();
const lowOnly = computed(() => {
  const raw = route?.query?.low;
  return ['1', 'true'].includes(String(Array.isArray(raw) ? raw[0] : raw));
});
const lowItems = ref([]);
const lowLoading = ref(false);
const lowError = ref(false);
async function loadLow() {
  lowLoading.value = true;
  lowError.value = false;
  try {
    lowItems.value = (await lowStock()).data ?? [];
  } catch {
    lowItems.value = [];
    lowError.value = true;
  } finally {
    lowLoading.value = false;
  }
}
function clearLowFilter() {
  const rest = { ...route.query };
  delete rest.low;
  router.replace({ query: rest });
}
const lowColumns = computed(() => [
  { key: 'product_name', label: t('dashboard.link_stock_low_col_product') },
  { key: 'sku', label: t('dashboard.link_stock_low_col_sku') },
  ...(multi.value ? [{ key: 'outlet_name', label: t('stock.col_outlet') }] : []),
  { key: 'current_stock', label: t('dashboard.link_stock_low_col_stock') },
  { key: 'low_stock_alert', label: t('dashboard.link_stock_low_col_alert') },
]);
onMounted(() => (lowOnly.value ? loadLow() : load()));
watch(lowOnly, (on) => (on ? loadLow() : load()));

const exporting = ref(false);
const showImportModal = ref(false);

async function doExport() {
  exporting.value = true;
  try {
    await exportMasterData('stock');
  } catch {
    toast.error(t('master_data.export_stock_failed'));
  } finally {
    exporting.value = false;
  }
}

async function afterImport() {
  // Refresh in the background but leave the modal open so the user still
  // sees the applied summary — they close it themselves via "Tutup".
  await load();
}

// Tipe pergerakan (warna + label) satu definisi bersama riwayat varian: utils/stockMovements.js.
// Filter tipe: nilai terikat (v-model) dan ada opsi "Semua tipe" — dulu pilihan tidak tampil di kotaknya
// (tetap "Semua tipe") dan tidak ada jalan kembali ke semua (BUG yang ditemukan lewat browser, 036).
const typeFilter = ref('');
const typeOptions = computed(() => [
  { value: '', label: t('master_data.all_types') },
  ...MOVEMENT_TYPES.map((value) => ({ value, label: t(movementTypeLabelKey(value)) })),
]);

// 036 — klik SKU membuka detail produknya dengan varian itu disorot. State-nya lokal di layar ini,
// jadi daftar, filter, dan posisi gulir tidak tersentuh saat dialog ditutup. Tanpa akses `products`
// SKU hanya teks (kontrol yang tidak bisa dipakai disembunyikan, bukan dinonaktifkan).
const canOpenProduct = computed(() => auth.canAccessMenu('products'));
const detailProductId = ref(null);
const detailVariantId = ref(null);

function openSku(row) {
  if (!row.product_id) {
    toast.error(t('master_data.product_gone'));
    return;
  }
  detailVariantId.value = row.variant_id;
  detailProductId.value = row.product_id;
}

function closeDetail() {
  detailProductId.value = null;
  detailVariantId.value = null;
}

const columns = computed(() => [
  { key: 'sku', label: t('master_data.col_sku') },
  ...(multi.value ? [{ key: 'outlet_name', label: t('stock.col_outlet') }] : []),
  { key: 'type', label: t('master_data.col_type') },
  { key: 'qty_change', label: t('master_data.col_qty_change') },
  { key: 'range', label: t('master_data.col_range') },
  { key: 'reason', label: t('master_data.col_reason') },
  { key: 'user_name', label: t('master_data.col_by') },
  { key: 'created_at', label: t('master_data.col_time') },
]);

// 041 — tanggal kini terikat (v-model) supaya "Atur ulang filter" dan penanda filter aktif bisa dihitung;
// parameter yang dikirim ke server tetap sama persis (type/date_from/date_to, kosong = tidak dikirim).
const dateFrom = ref('');
const dateTo = ref('');
const outletFilter = ref('');
const outletFilterOptions = computed(() => [{ value: '', label: t('stock.all_outlets') }, ...outletStore.outlets.map((o) => ({ value: o.id, label: o.name }))]);
const filtersActive = computed(() => !!(typeFilter.value || dateFrom.value || dateTo.value || outletFilter.value));

function applyFilters(patch) {
  setFilter(patch);
}

function resetFilters() {
  typeFilter.value = '';
  dateFrom.value = '';
  dateTo.value = '';
  outletFilter.value = '';
  setFilter({ type: undefined, date_from: undefined, date_to: undefined, outlet_id: undefined });
}

// --- Adjustment dialog -----------------------------------------------
const showAdjust = ref(false);
const adjustSearch = ref('');
const adjustResults = ref([]);
const adjustRows = ref([]);
const adjustReason = ref('');
const adjustOutlet = ref('');
const adjustOutletOptions = computed(() => outletStore.outlets.map((o) => ({ value: o.id, label: o.name })));
const submitting = ref(false);

const runSearch = useDebouncedFn(async () => {
  if (!adjustSearch.value.trim()) {
    adjustResults.value = [];
    return;
  }
  const res = await lookupVariants(adjustSearch.value.trim(), 8, adjustOutlet.value ? { stockOutlet: adjustOutlet.value } : {});
  adjustResults.value = res.data;
}, 300);

function openAdjust() {
  adjustRows.value = [];
  adjustReason.value = '';
  // 045 Stage 5 — bawaan: outlet yang sedang dipilih di pemilih outlet; kosong bila "semua" (harus dipilih).
  adjustOutlet.value = multi.value ? (outletStore.selected ?? '') : '';
  adjustSearch.value = '';
  adjustResults.value = [];
  showAdjust.value = true;
}

function addRow(variant) {
  if (adjustRows.value.some((r) => r.variant_id === variant.variant_id)) return;
  adjustRows.value.push({ variant_id: variant.variant_id, sku: variant.sku, label: variant.label, current_stock: variant.outlet_stock ?? variant.current_stock, delta: 0 });
  adjustSearch.value = '';
  adjustResults.value = [];
}

function bump(row, step) {
  row.delta += step;
}

function removeRow(index) {
  adjustRows.value.splice(index, 1);
}

const canSubmitAdjustment = () => adjustReason.value.trim().length > 0 && adjustRows.value.some((r) => r.delta !== 0) && (!multi.value || !!adjustOutlet.value);

async function submitAdjustment() {
  submitting.value = true;
  try {
    await createAdjustment({
      reason: adjustReason.value.trim(),
      ...(multi.value ? { outlet_id: adjustOutlet.value } : {}),
      items: adjustRows.value.filter((r) => r.delta !== 0).map((r) => ({ variant_id: r.variant_id, qty_change: r.delta })),
    });
    toast.success(t('master_data.adjustment_saved'));
    showAdjust.value = false;
    await load();
  } catch (err) {
    if (!err.isValidation) return;
    toast.error(Object.values(err.errors)[0]?.[0] ?? err.message);
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <div class="pb-10">
    <PageHeader :title="t('nav.stock_page_title')" :description="t('nav.stock_subtitle')">
      <template v-if="auth.canAccessMenu('stock')" #actions>
        <RouterLink v-if="multi" :to="{ name: 'stock-transfers' }" class="inline-flex h-10 items-center gap-2 border-2 border-line bg-white px-3.5 text-[13px] font-bold text-muted-5 hover:border-brand hover:text-brand-active" data-testid="open-transfers">
          <i class="ph-duotone ph-arrows-left-right text-[16px]" aria-hidden="true"></i>
          {{ t('transfers.title') }}
        </RouterLink>
        <BaseButton variant="secondary" :loading="exporting" @click="doExport">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          {{ t('common.export_xlsx') }}
        </BaseButton>
        <BaseButton variant="secondary" @click="showImportModal = true">
          <i class="ph-duotone ph-file-arrow-up text-[16px]" aria-hidden="true"></i>
          {{ t('common.bulk_import') }}
        </BaseButton>
        <BaseButton @click="openAdjust">
          <i class="ph-duotone ph-sliders-horizontal text-[16px]" aria-hidden="true"></i>
          {{ t('master_data.stock_adjustment') }}
        </BaseButton>
      </template>
    </PageHeader>

    <section v-if="lowOnly" class="mx-[26px] border-2 border-line bg-white" data-testid="stock-low-section">
      <div class="flex flex-wrap items-center gap-3 px-5 py-4">
        <button
          type="button"
          data-testid="low-chip"
          class="inline-flex items-center gap-1.5 border-2 border-line bg-white px-2 py-0.5 text-[12px] font-semibold text-muted-5 transition-colors hover:border-brand focus-visible:outline-none focus-visible:border-brand"
          :aria-label="t('dashboard.orders_chip_remove', { label: t('dashboard.link_stock_low_chip') })"
          @click="clearLowFilter"
        >
          {{ t('dashboard.link_stock_low_chip') }}
          <i class="ph-duotone ph-x text-[12px]" aria-hidden="true"></i>
        </button>
        <span v-if="!lowLoading && !lowError" class="ml-auto text-[12.5px] font-semibold text-muted" data-testid="data-count">{{ t('page.data_count', { count: lowItems.length }) }}</span>
      </div>
      <DataTable
        :columns="lowColumns"
        :rows="lowItems"
        row-key="variant_id"
        :loading="lowLoading"
        :error="lowError ? t('dashboard.link_stock_low_failed') : ''"
        :empty-message="t('dashboard.link_stock_low_empty')"
        @retry="loadLow"
      >
        <template #cell-sku="{ row }"><span class="font-mono text-[12px] font-semibold">{{ row.sku }}</span></template>
        <template #cell-outlet_name="{ row }">{{ row.outlet_name || '—' }}</template>
        <template #cell-current_stock="{ row }"><span class="font-mono text-[13px] font-bold text-danger-text">{{ row.current_stock }}</span></template>
      </DataTable>
    </section>

    <section v-else class="mx-[26px] border-2 border-line bg-white">
      <FilterBar :searchable="false" :count="loading || error ? null : meta.total" :active="filtersActive" @reset="resetFilters">
        <BaseSelect
          v-model="typeFilter"
          class="w-52"
          :placeholder="t('master_data.all_types')"
          :options="typeOptions"
          @update:model-value="(v) => applyFilters({ type: v || undefined })"
        />
        <BaseSelect
          v-if="multi"
          v-model="outletFilter"
          class="w-52"
          :placeholder="t('stock.all_outlets')"
          :options="outletFilterOptions"
          data-testid="stock-outlet-filter"
          @update:model-value="(v) => applyFilters({ outlet_id: v || undefined })"
        />
        <BaseInput v-model="dateFrom" type="date" class="w-44" @update:model-value="(v) => applyFilters({ date_from: v || undefined })" />
        <BaseInput v-model="dateTo" type="date" class="w-44" @update:model-value="(v) => applyFilters({ date_to: v || undefined })" />
      </FilterBar>

      <DataTable
        :columns="columns"
        :rows="items"
        :loading="loading"
        :error="error ? t('page.load_failed') : ''"
        :empty-message="t('master_data.no_stock_movements')"
        @retry="load"
      >
        <template #cell-sku="{ row }">
          <button
            v-if="canOpenProduct"
            type="button"
            class="font-mono text-[12px] font-semibold underline decoration-dotted hover:text-brand-active"
            @click="openSku(row)"
          >{{ row.sku }}</button>
          <span v-else class="font-mono text-[12px] font-semibold">{{ row.sku }}</span>
        </template>
        <template #cell-outlet_name="{ row }">{{ row.outlet_name || '—' }}</template>
        <template #cell-type="{ row }"><StatusPill :variant="movementTypeVariant(row.type)">{{ t(movementTypeLabelKey(row.type)) }}</StatusPill></template>
        <template #cell-qty_change="{ row }">
          <span class="font-mono text-[13px] font-bold" :class="row.qty_change >= 0 ? 'text-brand-active' : 'text-danger-text'">{{ row.qty_change >= 0 ? '+' : '' }}{{ row.qty_change }}</span>
        </template>
        <template #cell-range="{ row }"><span class="font-mono text-[12.5px] text-muted-4">{{ row.stock_before }} → {{ row.stock_after }}</span></template>
        <template #cell-reason="{ row }">
          <span v-if="row.reference" class="font-mono text-[12px] font-bold text-brand-active">{{ row.reference.number }}</span>
          <span v-if="row.reason" class="block text-[12.5px]">{{ row.reason }}</span>
          <span v-if="!row.reference && !row.reason" class="text-muted-3">—</span>
        </template>
        <template #cell-user_name="{ row }">{{ row.user_name || '—' }}</template>
        <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </section>

    <ProductDetailModal :open="detailProductId !== null" :product-id="detailProductId" :highlight-variant-id="detailVariantId" @close="closeDetail" />

    <BaseModal :open="showAdjust" :title="t('master_data.stock_adjustment')" max-width-class="max-w-[640px]" @close="showAdjust = false">
      <div class="flex flex-col gap-4 px-6 py-5">
        <div v-for="(row, idx) in adjustRows" :key="row.variant_id" class="flex items-center gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
          <div class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="text-[13.5px] font-semibold">{{ row.label }}</span>
            <span class="font-mono text-[11px] text-muted-3">{{ row.sku }} · {{ row.current_stock }} → {{ row.current_stock + row.delta }}</span>
          </div>
          <div class="flex items-center gap-0.5 overflow-hidden rounded-lg border border-line bg-white">
            <button type="button" class="flex h-[34px] w-[34px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('master_data.decrease')" @click="bump(row, -1)"><i class="ph-duotone ph-minus text-[14px]" aria-hidden="true"></i></button>
            <span class="min-w-[36px] text-center text-[13.5px] font-bold">{{ row.delta >= 0 ? '+' : '' }}{{ row.delta }}</span>
            <button type="button" class="flex h-[34px] w-[34px] items-center justify-center text-muted-5 hover:bg-line-7" :aria-label="t('master_data.increase')" @click="bump(row, 1)"><i class="ph-duotone ph-plus text-[14px]" aria-hidden="true"></i></button>
          </div>
          <button type="button" class="flex h-[34px] w-[34px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('master_data.delete_row', { label: row.label })" @click="removeRow(idx)">
            <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
          </button>
        </div>

        <BaseSelect
          v-if="multi"
          v-model="adjustOutlet"
          :label="t('stock.adjust_outlet')"
          :placeholder="t('stock.adjust_outlet_placeholder')"
          :options="adjustOutletOptions"
          data-testid="adjust-outlet"
          @update:model-value="() => { adjustRows = []; }"
        />

        <div class="relative">
          <BaseInput v-model="adjustSearch" :label="t('master_data.search_variant')" :placeholder="t('master_data.search_variant_placeholder')" @input="runSearch" />
          <div v-if="adjustResults.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-line-2 bg-white shadow-lg">
            <button v-for="v in adjustResults" :key="v.variant_id" type="button" class="flex w-full flex-col gap-0.5 px-3.5 py-2.5 text-left hover:bg-line-7" @click="addRow(v)">
              <span class="text-[13px] font-semibold">{{ v.label }}</span>
              <span class="font-mono text-[11px] text-muted-3">{{ v.sku }} · stok {{ v.outlet_stock ?? v.current_stock }}</span>
            </button>
          </div>
        </div>

        <BaseTextarea
          v-model="adjustReason"
          :label="t('master_data.adjustment_reason')"
          required
          :placeholder="t('master_data.adjustment_reason_placeholder')"
          :rows="3"
        />
        <div class="flex items-start gap-2.5 rounded-lg border border-mint-border bg-mint-50 px-3.5 py-3">
          <i class="ph-duotone ph-shield-check text-[18px] text-brand" aria-hidden="true"></i>
          <span class="text-[12px] leading-relaxed text-muted-4">
            {{ t('master_data.adjustment_note') }} <span class="font-mono text-[11px]">stock_movements</span>
            <span class="font-mono text-[11px]">adjustment</span>. {{ t('master_data.adjustment_note_end') }}
          </span>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showAdjust = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :disabled="!canSubmitAdjustment()" :loading="submitting" @click="submitAdjustment">{{ t('master_data.save_adjustment') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <MasterDataImportModal :open="showImportModal" @close="showImportModal = false" @imported="afterImport" />
  </div>
</template>
