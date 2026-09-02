<script setup>
import { ref, reactive, onMounted } from 'vue';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listMovements, createAdjustment } from '../api/stock';
import { lookupVariants } from '../api/products';
import { exportMasterData } from '../api/masterData';
import { useAuthStore } from '../stores/auth';
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
import EmptyState from '../components/ui/EmptyState.vue';
import MasterDataImportModal from '../components/masterData/MasterDataImportModal.vue';

const auth = useAuthStore();
const toast = useToastStore();

const { items, meta, loading, load, setPage, setFilter } = usePaginatedList(listMovements);
onMounted(load);

const exporting = ref(false);
const showImportModal = ref(false);

async function doExport() {
  exporting.value = true;
  try {
    await exportMasterData('stock');
  } catch {
    toast.error('Gagal mengekspor data stok.');
  } finally {
    exporting.value = false;
  }
}

async function afterImport() {
  // Refresh in the background but leave the modal open so the user still
  // sees the applied summary — they close it themselves via "Tutup".
  await load();
}

const TYPE_LABEL = { purchase: 'Pembelian', sale: 'Penjualan', preorder_handover: 'Serah pre-order', adjustment: 'Penyesuaian', return: 'Retur', initial: 'Stok awal' };
const TYPE_VARIANT = { purchase: 'mint', sale: 'neutral', preorder_handover: 'warn', adjustment: 'neutral', return: 'mint', initial: 'neutral' };

const columns = [
  { key: 'sku', label: 'SKU' },
  { key: 'type', label: 'Tipe' },
  { key: 'qty_change', label: 'Perubahan' },
  { key: 'range', label: 'Sebelum → Sesudah' },
  { key: 'reason', label: 'Referensi / alasan' },
  { key: 'user_name', label: 'Oleh' },
  { key: 'created_at', label: 'Waktu' },
];

function applyFilters(patch) {
  setFilter(patch);
}

// --- Adjustment dialog -----------------------------------------------
const showAdjust = ref(false);
const adjustSearch = ref('');
const adjustResults = ref([]);
const adjustRows = ref([]);
const adjustReason = ref('');
const submitting = ref(false);

const runSearch = useDebouncedFn(async () => {
  if (!adjustSearch.value.trim()) {
    adjustResults.value = [];
    return;
  }
  const res = await lookupVariants(adjustSearch.value.trim(), 8);
  adjustResults.value = res.data;
}, 300);

function openAdjust() {
  adjustRows.value = [];
  adjustReason.value = '';
  adjustSearch.value = '';
  adjustResults.value = [];
  showAdjust.value = true;
}

function addRow(variant) {
  if (adjustRows.value.some((r) => r.variant_id === variant.variant_id)) return;
  adjustRows.value.push({ variant_id: variant.variant_id, sku: variant.sku, label: variant.label, current_stock: variant.current_stock, delta: 0 });
  adjustSearch.value = '';
  adjustResults.value = [];
}

function bump(row, step) {
  row.delta += step;
}

function removeRow(index) {
  adjustRows.value.splice(index, 1);
}

const canSubmitAdjustment = () => adjustReason.value.trim().length > 0 && adjustRows.value.some((r) => r.delta !== 0);

async function submitAdjustment() {
  submitting.value = true;
  try {
    await createAdjustment({
      reason: adjustReason.value.trim(),
      items: adjustRows.value.filter((r) => r.delta !== 0).map((r) => ({ variant_id: r.variant_id, qty_change: r.delta })),
    });
    toast.success('Penyesuaian stok tersimpan.');
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
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <BaseSelect
        class="w-52"
        placeholder="Semua tipe"
        :options="Object.entries(TYPE_LABEL).map(([value, label]) => ({ value, label }))"
        @update:model-value="(v) => applyFilters({ type: v || undefined })"
      />
      <BaseInput type="date" class="w-44" @update:model-value="(v) => applyFilters({ date_from: v || undefined })" />
      <BaseInput type="date" class="w-44" @update:model-value="(v) => applyFilters({ date_to: v || undefined })" />
      <span class="flex-1"></span>
      <template v-if="auth.canAccessMenu('stock')">
        <BaseButton variant="secondary" :loading="exporting" @click="doExport">
          <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
          Ekspor .xlsx
        </BaseButton>
        <BaseButton variant="secondary" @click="showImportModal = true">
          <i class="ph-duotone ph-file-arrow-up text-[16px]" aria-hidden="true"></i>
          Impor massal
        </BaseButton>
        <BaseButton @click="openAdjust">
          <i class="ph-duotone ph-sliders-horizontal text-[16px]" aria-hidden="true"></i>
          Penyesuaian stok
        </BaseButton>
      </template>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" empty-message="Belum ada pergerakan stok.">
        <template #cell-sku="{ row }"><span class="font-mono text-[12px] font-semibold">{{ row.sku }}</span></template>
        <template #cell-type="{ row }"><StatusPill :variant="TYPE_VARIANT[row.type]">{{ TYPE_LABEL[row.type] ?? row.type }}</StatusPill></template>
        <template #cell-qty_change="{ row }">
          <span class="font-mono text-[13px] font-bold" :class="row.qty_change >= 0 ? 'text-brand-active' : 'text-danger-text'">{{ row.qty_change >= 0 ? '+' : '' }}{{ row.qty_change }}</span>
        </template>
        <template #cell-range="{ row }"><span class="font-mono text-[12.5px] text-muted-4">{{ row.stock_before }} → {{ row.stock_after }}</span></template>
        <template #cell-reason="{ row }">{{ row.reason || row.reference_type || '—' }}</template>
        <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showAdjust" title="Penyesuaian stok" max-width-class="max-w-[640px]" @close="showAdjust = false">
      <div class="flex flex-col gap-4 px-6 py-5">
        <div v-for="(row, idx) in adjustRows" :key="row.variant_id" class="flex items-center gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3.5">
          <div class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="text-[13.5px] font-semibold">{{ row.label }}</span>
            <span class="font-mono text-[11px] text-muted-3">{{ row.sku }} · {{ row.current_stock }} → {{ row.current_stock + row.delta }}</span>
          </div>
          <div class="flex items-center gap-0.5 overflow-hidden rounded-lg border border-line bg-white">
            <button type="button" class="flex h-[34px] w-[34px] items-center justify-center text-muted-5 hover:bg-line-7" aria-label="Kurangi" @click="bump(row, -1)"><i class="ph-duotone ph-minus text-[14px]" aria-hidden="true"></i></button>
            <span class="min-w-[36px] text-center text-[13.5px] font-bold">{{ row.delta >= 0 ? '+' : '' }}{{ row.delta }}</span>
            <button type="button" class="flex h-[34px] w-[34px] items-center justify-center text-muted-5 hover:bg-line-7" aria-label="Tambah" @click="bump(row, 1)"><i class="ph-duotone ph-plus text-[14px]" aria-hidden="true"></i></button>
          </div>
          <button type="button" class="flex h-[34px] w-[34px] items-center justify-center rounded-md border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="`Hapus ${row.label}`" @click="removeRow(idx)">
            <i class="ph-duotone ph-trash text-[14px]" aria-hidden="true"></i>
          </button>
        </div>

        <div class="relative">
          <BaseInput v-model="adjustSearch" label="Cari varian" placeholder="Nama produk atau SKU…" @input="runSearch" />
          <div v-if="adjustResults.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-line-2 bg-white shadow-lg">
            <button v-for="v in adjustResults" :key="v.variant_id" type="button" class="flex w-full flex-col gap-0.5 px-3.5 py-2.5 text-left hover:bg-line-7" @click="addRow(v)">
              <span class="text-[13px] font-semibold">{{ v.label }}</span>
              <span class="font-mono text-[11px] text-muted-3">{{ v.sku }} · stok {{ v.current_stock }}</span>
            </button>
          </div>
        </div>

        <BaseTextarea
          v-model="adjustReason"
          label="Alasan penyesuaian"
          required
          placeholder="Contoh: stok opname 25 Okt, satu unit cacat kemasan"
          :rows="3"
        />
        <div class="flex items-start gap-2.5 rounded-lg border border-mint-border bg-mint-50 px-3.5 py-3">
          <i class="ph-duotone ph-shield-check text-[18px] text-brand" aria-hidden="true"></i>
          <span class="text-[12px] leading-relaxed text-muted-4">
            Setiap baris menulis satu <span class="font-mono text-[11px]">stock_movements</span> bertipe
            <span class="font-mono text-[11px]">adjustment</span>. Tidak ada update langsung ke kolom stok.
          </span>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showAdjust = false">Batal</BaseButton>
          <BaseButton :disabled="!canSubmitAdjustment()" :loading="submitting" @click="submitAdjustment">Simpan penyesuaian</BaseButton>
        </div>
      </template>
    </BaseModal>

    <MasterDataImportModal :open="showImportModal" @close="showImportModal = false" @imported="afterImport" />
  </div>
</template>
