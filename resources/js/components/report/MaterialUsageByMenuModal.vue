<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { getMaterialUsageByMenu } from '../../api/reports';
import BaseModal from '../ui/BaseModal.vue';
import ErrorState from '../ui/ErrorState.vue';

/** 088 US4 — menu dan pilihan yang memakai satu bahan di periode (GET /reports/materials/{id}/usage-by-menu). */
const props = defineProps({
  material: { type: Object, default: null }, // { material_id, name, unit }
  dateFrom: { type: String, required: true },
  dateTo: { type: String, required: true },
  outletIds: { type: Array, default: () => [] }, // saringan outlet tab Bahan
});
defineEmits(['close']);
const { t } = useI18n();

const rows = ref([]);
const loading = ref(false);
const failed = ref(false);

async function load() {
  if (!props.material) return;
  loading.value = true;
  failed.value = false;
  try {
    rows.value = (await getMaterialUsageByMenu(props.material.material_id, { date_from: props.dateFrom, date_to: props.dateTo, ...(props.outletIds.length ? { outlet_id: props.outletIds } : {}) })).data ?? [];
  } catch {
    failed.value = true;
  } finally {
    loading.value = false;
  }
}
watch(() => props.material?.material_id, load, { immediate: true });

const qty = (v) => Number(v).toLocaleString('id-ID', { maximumFractionDigits: 3 });
</script>

<template>
  <BaseModal :open="material !== null" :title="t('reports.mat_by_menu_title', { name: material?.name ?? '' })" max-width-class="max-w-[620px]" @close="$emit('close')">
    <div class="px-6 py-5">
      <ErrorState v-if="failed" @retry="load" />
      <p v-else-if="loading" class="text-[13px] text-muted-4" role="status">{{ t('common.loading_data') }}</p>
      <p v-else-if="!rows.length" class="text-[13px] text-muted-4">{{ t('reports.mat_by_menu_empty') }}</p>
      <table v-else class="w-full text-[13px]">
        <thead class="text-left text-[11.5px] font-bold uppercase tracking-wide text-muted-4">
          <tr><th class="py-2">{{ t('reports.mat_by_menu_product') }}</th><th class="py-2">{{ t('reports.mat_by_menu_options') }}</th><th class="py-2 text-right">{{ t('reports.mat_by_menu_qty') }}</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.product_id" class="border-t border-line">
            <td class="py-2 font-semibold">{{ r.product }}</td>
            <td class="py-2 text-muted-4">{{ r.options.join(', ') || '—' }}</td>
            <td class="py-2 text-right font-mono tabular-nums">{{ qty(r.qty) }} {{ material?.unit }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </BaseModal>
</template>
