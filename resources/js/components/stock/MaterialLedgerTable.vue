<script setup>
import { computed, onMounted, reactive, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listAllMaterialMovements } from '../../api/materials';
import { usePaginatedList } from '../../composables/usePaginatedList';
import { useOutletStore } from '../../stores/outlet';
import { formatDateTime } from '../../utils/date';
import { MATERIAL_MOVEMENT_TYPES, materialMovementTone } from '../../utils/materialStock';
import DataTable from '../ui/DataTable.vue';
import TablePagination from '../ui/TablePagination.vue';
import StatusPill from '../ui/StatusPill.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import BaseInput from '../ui/BaseInput.vue';
import FilterBar from '../page/FilterBar.vue';

/**
 * 2026-10-31 (permintaan pemilik: koreksi stok bahan masuk log Inventori → Stok) — buku besar SEMUA bahan di layar
 * Stok (tab Bahan). Sumber GET /materials/movements: dibatasi outlet navbar oleh server; alasan koreksi kini tersimpan
 * di pergerakan. Bentuk filter sama dengan tab Produk (tipe, outlet, tanggal).
 */
const { t } = useI18n();
const outletStore = useOutletStore();
const multi = computed(() => outletStore.multi);

const { items, meta, loading, error, load, setPage, setFilter } = usePaginatedList(listAllMaterialMovements);
const filters = reactive({ type: '', outlet: '', from: '', to: '' });
const active = computed(() => Object.values(filters).some((v) => v !== ''));
watch(() => ({ ...filters }), (f) => setFilter({
  type: f.type ? [f.type] : undefined,
  outlet_id: f.outlet ? [f.outlet] : undefined,
  from: f.from || undefined,
  to: f.to || undefined,
}));
function reset() {
  Object.assign(filters, { type: '', outlet: '', from: '', to: '' });
}
onMounted(load);
watch(() => outletStore.selected, () => load());

const typeOptions = computed(() => [{ value: '', label: t('vendors_materials.movement_all') }, ...MATERIAL_MOVEMENT_TYPES.map((v) => ({ value: v, label: t(`vendors_materials.movement_${v}`) }))]);
const outletOptions = computed(() => [{ value: '', label: t('stock.all_outlets') }, ...outletStore.outlets.map((o) => ({ value: o.id, label: o.name }))]);
const columns = computed(() => [
  { key: 'material', label: t('vendors_materials.material_name') },
  ...(multi.value ? [{ key: 'outlet_code', label: t('stock.col_outlet') }] : []),
  { key: 'type', label: t('master_data.col_type') },
  { key: 'qty_change', label: t('master_data.col_qty_change') },
  { key: 'range', label: t('master_data.col_range') },
  { key: 'reason', label: t('master_data.col_reason') },
  { key: 'user', label: t('master_data.col_by') },
  { key: 'created_at', label: t('master_data.col_time') },
]);
const fmt = (v) => Number(v).toLocaleString('id-ID', { maximumFractionDigits: 3 });
</script>

<template>
  <FilterBar :searchable="false" :count="loading || error ? null : meta.total" :active="active" @reset="reset">
    <BaseSelect v-model="filters.type" class="w-52" :options="typeOptions" data-testid="material-ledger-type" />
    <BaseSelect v-if="multi" v-model="filters.outlet" class="w-52" :options="outletOptions" />
    <BaseInput v-model="filters.from" type="date" class="w-44" />
    <BaseInput v-model="filters.to" type="date" class="w-44" />
  </FilterBar>
  <DataTable :columns="columns" :rows="items" :loading="loading" :error="error ? t('page.load_failed') : ''" :empty-message="t('vendors_materials.movement_none')" @retry="load">
    <template #cell-material="{ row }">
      <span class="font-mono text-[12px] font-bold text-brand-active">{{ row.material_code }}</span>
      <span class="block text-[12.5px]">{{ row.material_name }}</span>
    </template>
    <template #cell-outlet_code="{ row }">{{ row.outlet_code || '—' }}</template>
    <template #cell-type="{ row }"><StatusPill :variant="materialMovementTone(row.type)">{{ t(`vendors_materials.movement_${row.type}`) }}</StatusPill></template>
    <template #cell-qty_change="{ row }">
      <span class="font-mono text-[13px] font-bold" :class="Number(row.qty_change) >= 0 ? 'text-brand-active' : 'text-danger-text'">{{ Number(row.qty_change) >= 0 ? '+' : '' }}{{ fmt(row.qty_change) }} {{ row.unit }}</span>
    </template>
    <template #cell-range="{ row }">
      <span class="font-mono text-[12.5px] text-muted-4">{{ fmt(row.stock_before) }} → <span :class="Number(row.stock_after) < 0 ? 'text-danger-text' : ''">{{ fmt(row.stock_after) }}</span></span>
    </template>
    <template #cell-reason="{ row }">
      <span v-if="row.reference?.order_number" class="font-mono text-[12px] font-bold text-brand-active">{{ row.reference.order_number }}</span>
      <span v-if="row.reason" class="block text-[12.5px]">{{ row.reason }}</span>
      <span v-if="!row.reference?.order_number && !row.reason" class="text-muted-3">—</span>
    </template>
    <template #cell-user="{ row }">{{ row.user?.name ?? '—' }}</template>
    <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
  </DataTable>
  <TablePagination :meta="meta" @change="setPage" />
</template>
