<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import BaseModal from '../ui/BaseModal.vue';
import { preorderReport } from '../../api/reports';
import { useToastStore } from '../../stores/toast';
import { formatIDR } from '../../utils/money';

/**
 * 012-seller-preorder-report-detail-export (US3, T015-T017) — drill-down
 * dari satu baris laporan Pre-order (ringkasan status × payment_completeness,
 * opsional per penjual) ke daftar pre-order individual yang menyusunnya.
 * Mengikuti pola StockByArtistDetailModal.vue persis (research.md R3):
 * endpoint yang sama dengan tabel ringkasan (preorderReport), dipanggil
 * ON DEMAND saat baris diklik, dengan param filter tambahan yang membuat
 * backend mengembalikan baris per-pre-order alih-alih agregat.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  status: { type: String, default: '' },
  paymentCompleteness: { type: String, default: '' },
  artistId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const router = useRouter();
const toast = useToastStore();
const { t } = useI18n();
const loading = ref(false);
const detail = ref(null);

watch(
  () => [props.open, props.status, props.paymentCompleteness, props.artistId],
  async ([open, status, paymentCompleteness]) => {
    if (!open || !status || !paymentCompleteness) {
      detail.value = null;
      return;
    }
    loading.value = true;
    try {
      // research.md R3 — endpoint yang sama dengan tabel ringkasan
      // (preorderReport), tapi dengan status+payment_completeness (dan
      // artist_id opsional dari breakdown per penjual, US2) backend
      // mengembalikan daftar pre-order individual, bukan agregat.
      const res = await preorderReport({
        status,
        payment_completeness: paymentCompleteness,
        artist_id: props.artistId || undefined,
      });
      detail.value = res.rows ?? res;
    } catch (err) {
      toast.error(err.message || t('reports.load_preorder_detail_failed'));
    } finally {
      loading.value = false;
    }
  },
  { immediate: true }
);

// research.md R4 — reuse PreordersView.vue's existing route.query.preorder_id
// deep-link handler (onMounted → openDetailById → strips the query param)
// instead of building a second preorder-detail UI here (FR-008).
function openPreorder(row) {
  router.push({ path: '/preorders', query: { preorder_id: row.preorder_id } });
  emit('close');
}
</script>

<template>
  <BaseModal :open="open" :title="t('reports.preorder_detail_title')" max-width-class="max-w-[680px]" @close="emit('close')">
    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">{{ t('reports.loading_preorder_detail') }}</div>
    <div v-else-if="!detail || detail.length === 0" class="px-6 py-14 text-center text-[13px] text-muted-3">
      {{ t('reports.no_preorder_detail') }}
    </div>
    <div v-else class="overflow-hidden rounded-md border border-line-5 mx-6 my-5">
      <table class="w-full border-collapse text-[12.5px]">
        <thead>
          <tr class="bg-surface-subtle text-left">
            <th class="px-3 py-1.5 font-bold text-muted-2">{{ t('reports.col_preorder_number') }}</th>
            <th class="px-3 py-1.5 font-bold text-muted-2">{{ t('reports.preorder_detail_col_customer') }}</th>
            <th class="px-3 py-1.5 text-right font-bold text-muted-2">{{ t('reports.preorder_detail_col_order_value') }}</th>
            <th class="px-3 py-1.5 text-right font-bold text-muted-2">{{ t('reports.preorder_detail_col_collected') }}</th>
            <th class="px-3 py-1.5 text-right font-bold text-muted-2">{{ t('reports.preorder_detail_col_outstanding') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in detail"
            :key="row.preorder_id"
            class="cursor-pointer border-t border-line-5 transition-colors hover:bg-line-7"
            @click="openPreorder(row)"
          >
            <td class="px-3 py-1.5 font-mono">{{ row.preorder_number }}</td>
            <td class="px-3 py-1.5">{{ row.customer_name ?? t('dashboard.customer_walk_in') }}</td>
            <td class="px-3 py-1.5 text-right">{{ formatIDR(row.order_value) }}</td>
            <td class="px-3 py-1.5 text-right">{{ formatIDR(row.collected) }}</td>
            <td class="px-3 py-1.5 text-right">{{ formatIDR(row.outstanding) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </BaseModal>
</template>
