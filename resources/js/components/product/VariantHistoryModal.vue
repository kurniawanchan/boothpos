<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import DataTable from '../ui/DataTable.vue';
import StatusPill from '../ui/StatusPill.vue';
import TablePagination from '../ui/TablePagination.vue';
import { usePaginatedList } from '../../composables/usePaginatedList';
import { listMovements } from '../../api/stock';
import { MOVEMENT_TYPES, movementTypeLabelKey, movementTypeVariant } from '../../utils/stockMovements';
import { formatDateTime } from '../../utils/date';

/**
 * 036-bom-variant-stock-ux (US3) — riwayat transaksi SATU varian: tampilan
 * hanya-baca atas buku besar stok (stock_movements, append-only) lewat endpoint
 * yang sama dengan layar Stok, jadi angka "sebelum → sesudah" tidak pernah bisa
 * berbeda antar layar. Nomor referensi (order / pre-order) sudah diselesaikan
 * server; di sini hanya ditampilkan.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  variantId: { type: [Number, String, null], default: null },
  variantSku: { type: String, default: '' },
  variantName: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const { items, meta, params, loading, error, load, setPage, setFilter } = usePaginatedList(listMovements);

const typeFilter = ref('');
const dateFrom = ref('');
const dateTo = ref('');

const typeOptions = computed(() => [
  { value: '', label: t('master_data.all_types') },
  ...MOVEMENT_TYPES.map((type) => ({ value: type, label: t(movementTypeLabelKey(type)) })),
]);

const columns = computed(() => [
  { key: 'type', label: t('master_data.col_type') },
  { key: 'qty_change', label: t('master_data.col_qty_change') },
  { key: 'range', label: t('master_data.col_range') },
  { key: 'reason', label: t('master_data.col_reason') },
  { key: 'user_name', label: t('master_data.col_by') },
  { key: 'created_at', label: t('master_data.col_time') },
]);

// Setiap pembukaan mulai bersih: filter kosong, halaman 1, varian yang diminta.
watch(
  () => [props.open, props.variantId],
  ([open, variantId]) => {
    if (!open || !variantId) return;
    typeFilter.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    Object.assign(params, { variant_id: variantId, type: undefined, date_from: undefined, date_to: undefined, page: 1 });
    load();
  },
  { immediate: true },
);
</script>

<template>
  <BaseModal :open="open" :title="t('master_data.history_title', { sku: variantSku || variantName })" max-width-class="max-w-[920px]" @close="emit('close')">
    <div class="flex flex-col gap-4 px-6 py-5">
      <div class="flex flex-wrap items-end gap-2.5">
        <BaseSelect
          v-model="typeFilter"
          class="w-52"
          :options="typeOptions"
          :placeholder="t('master_data.all_types')"
          @update:model-value="(v) => setFilter({ type: v || undefined })"
        />
        <BaseInput
          v-model="dateFrom"
          type="date"
          class="w-44"
          :label="t('master_data.history_date_from')"
          @update:model-value="(v) => setFilter({ date_from: v || undefined })"
        />
        <BaseInput
          v-model="dateTo"
          type="date"
          class="w-44"
          :label="t('master_data.history_date_to')"
          @update:model-value="(v) => setFilter({ date_to: v || undefined })"
        />
      </div>

      <div v-if="error" role="alert" class="flex flex-col items-center gap-3 rounded-lg border border-dashed border-danger-border px-4 py-9 text-center">
        <span class="text-[13px] font-semibold text-danger-text">{{ error.message || t('master_data.history_load_failed') }}</span>
        <BaseButton variant="secondary" size="sm" @click="load">{{ t('common.retry') }}</BaseButton>
      </div>
      <div v-else class="overflow-hidden rounded-lg border border-line-2 bg-white">
        <DataTable :columns="columns" :rows="items" :loading="loading" :empty-message="t('master_data.history_empty')">
          <template #cell-type="{ row }">
            <StatusPill :variant="movementTypeVariant(row.type)">{{ t(movementTypeLabelKey(row.type)) }}</StatusPill>
          </template>
          <template #cell-qty_change="{ row }">
            <span class="font-mono text-[13px] font-bold" :class="row.qty_change >= 0 ? 'text-brand-active' : 'text-danger-text'">{{ row.qty_change >= 0 ? '+' : '' }}{{ row.qty_change }}</span>
          </template>
          <template #cell-range="{ row }"><span class="font-mono text-[12.5px] text-muted-4">{{ row.stock_before }} → {{ row.stock_after }}</span></template>
          <template #cell-reason="{ row }">
            <span v-if="row.reference" class="font-mono text-[12px] font-bold text-brand-active">{{ row.reference.number }}</span>
            <span v-if="row.reason" class="block text-[12px] text-muted-4">{{ row.reason }}</span>
            <span v-if="!row.reference && !row.reason" class="text-muted-3">—</span>
          </template>
          <template #cell-user_name="{ row }">{{ row.user_name || '—' }}</template>
          <template #cell-created_at="{ row }">{{ formatDateTime(row.created_at) }}</template>
        </DataTable>
        <TablePagination :meta="meta" @change="setPage" />
      </div>
    </div>
  </BaseModal>
</template>
