<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { getMaterial, listMaterialMovements } from '../../api/materials';
import { usePaginatedList } from '../../composables/usePaginatedList';
import BaseModal from '../ui/BaseModal.vue';
import DataTable from '../ui/DataTable.vue';
import TablePagination from '../ui/TablePagination.vue';
import StatusPill from '../ui/StatusPill.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import { MATERIAL_MOVEMENT_TYPES as TYPES, materialMovementTone } from '../../utils/materialStock';

/**
 * 087 — pemakaian satu bahan: tab "Dipakai oleh" (pilihan modifier yang memetakan bahan ini) dan tab "Riwayat"
 * (buku besar per outlet yang terlihat, dengan nomor pesanan untuk pemakaian penjualan). Hanya baca.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  material: { type: Object, default: null },
});
defineEmits(['close']);
const { t } = useI18n();

const tab = ref('usage');
const usage = ref([]);
const usageLoading = ref(false);

const typeFilter = ref('');
const typeOptions = computed(() => [{ value: '', label: t('vendors_materials.movement_all') }, ...TYPES.map((v) => ({ value: v, label: t(`vendors_materials.movement_${v}`) }))]);

const fetchMovements = (params) => listMaterialMovements(props.material.id, params);
const { items, meta, loading, error, load, setPage, setFilter } = usePaginatedList(fetchMovements);

watch(
  () => [props.open, props.material?.id],
  async ([open]) => {
    if (!open || !props.material) return;
    tab.value = 'usage';
    typeFilter.value = '';
    usageLoading.value = true;
    try {
      usage.value = (await getMaterial(props.material.id)).used_by_modifier_options ?? [];
    } finally {
      usageLoading.value = false;
    }
  },
  { immediate: true },
);

function showHistory() {
  tab.value = 'history';
  setFilter({ type: typeFilter.value ? [typeFilter.value] : undefined });
}
watch(typeFilter, () => tab.value === 'history' && setFilter({ type: typeFilter.value ? [typeFilter.value] : undefined }));

const usageColumns = computed(() => [
  { key: 'group', label: t('vendors_materials.usage_group') },
  { key: 'option', label: t('vendors_materials.usage_option') },
  { key: 'quantity', label: t('vendors_materials.usage_qty') },
]);
const historyColumns = computed(() => [
  { key: 'created_at', label: t('vendors_materials.movement_time') },
  { key: 'type', label: t('vendors_materials.movement_type') },
  { key: 'outlet_code', label: t('vendors_materials.movement_outlet') },
  { key: 'qty_change', label: t('vendors_materials.movement_qty') },
  { key: 'stock_after', label: t('vendors_materials.movement_after') },
  { key: 'reference', label: t('vendors_materials.movement_reference') },
  { key: 'user', label: t('vendors_materials.movement_user') },
]);
const fmt = (v) => Number(v).toLocaleString('id-ID', { maximumFractionDigits: 3 });
const when = (iso) => new Date(iso).toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' });
</script>

<template>
  <BaseModal :open="open" :title="t('vendors_materials.usage_title', { name: material?.name ?? '' })" max-width-class="max-w-[860px]" @close="$emit('close')">
    <div class="flex flex-col gap-3.5 px-6 py-5">
      <div class="flex gap-2" role="tablist">
        <button type="button" role="tab" :aria-selected="tab === 'usage'" class="rounded-lg px-3 py-1.5 text-[12.5px] font-semibold" :class="tab === 'usage' ? 'bg-brand text-white' : 'text-muted-4 hover:text-brand-active'" @click="tab = 'usage'">
          {{ t('vendors_materials.usage_tab') }}
        </button>
        <button type="button" role="tab" :aria-selected="tab === 'history'" class="rounded-lg px-3 py-1.5 text-[12.5px] font-semibold" :class="tab === 'history' ? 'bg-brand text-white' : 'text-muted-4 hover:text-brand-active'" data-testid="material-history-tab" @click="showHistory">
          {{ t('vendors_materials.history_tab') }}
        </button>
      </div>

      <DataTable v-if="tab === 'usage'" :columns="usageColumns" :rows="usage" :loading="usageLoading" :empty-message="t('vendors_materials.usage_none')">
        <template #cell-option="{ row }">
          {{ row.option }}
          <StatusPill v-if="!row.option_active" variant="neutral" class="ml-1">{{ t('common.inactive') }}</StatusPill>
        </template>
        <template #cell-quantity="{ row }"><span class="font-mono text-[12.5px]">{{ fmt(row.quantity) }} {{ material?.unit }}</span></template>
      </DataTable>

      <template v-else>
        <div class="max-w-[240px]">
          <BaseSelect v-model="typeFilter" :options="typeOptions" :aria-label="t('vendors_materials.movement_type')" />
        </div>
        <DataTable :columns="historyColumns" :rows="items" :loading="loading" :error="error ? t('page.load_failed') : ''" :empty-message="t('vendors_materials.movement_none')" @retry="load">
          <template #cell-created_at="{ row }"><span class="text-[12.5px]">{{ when(row.created_at) }}</span></template>
          <template #cell-type="{ row }"><StatusPill :variant="materialMovementTone(row.type)">{{ t(`vendors_materials.movement_${row.type}`) }}</StatusPill></template>
          <template #cell-qty_change="{ row }"><span class="font-mono text-[12.5px]">{{ fmt(row.qty_change) }}</span></template>
          <template #cell-stock_after="{ row }"><span class="font-mono text-[12.5px]" :class="Number(row.stock_after) < 0 ? 'text-danger-text' : ''">{{ fmt(row.stock_after) }}</span></template>
          <template #cell-reference="{ row }">
            <span v-if="row.reference?.order_number" class="font-mono text-[12.5px]">{{ row.reference.order_number }}</span>
            <span v-else-if="row.reference?.type === 'order_item'" class="text-[12px] text-muted-3">{{ t('vendors_materials.movement_line_deleted') }}</span>
            <span v-else class="text-[12px] text-muted-3">—</span>
          </template>
          <template #cell-user="{ row }"><span class="text-[12.5px]">{{ row.user?.name ?? '—' }}</span></template>
        </DataTable>
        <TablePagination :meta="meta" @change="setPage" />
      </template>
    </div>
  </BaseModal>
</template>
