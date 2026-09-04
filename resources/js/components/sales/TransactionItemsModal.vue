<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import DataTable from '../ui/DataTable.vue';
import ProductDetailModal from '../product/ProductDetailModal.vue';
import { getOrder } from '../../api/orders';
import { formatIDR } from '../../utils/money';
import { useToastStore } from '../../stores/toast';

/**
 * 009-ui-ux-refinements US2 (T015-T018) — popup "Produk Terjual" yang
 * dibuka dari klik nomor transaksi di SalesView.vue, MENGGANTIKAN
 * ReceiptModal pada titik klik itu (struk cetak tetap ada di file lain,
 * hanya dicabut dari titik klik ini per FR-004/research R4).
 *
 * Sumber data GET /orders/{order} (bukan GET /reports/sales, yang hanya
 * mengembalikan header transaksi) — sesuai R4: payload item-level hanya
 * diambil saat baris transaksi benar-benar diklik (opt-in per klik),
 * bukan dibungkus eager ke endpoint daftar.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const toast = useToastStore();
const { t } = useI18n();

const order = ref(null);
const loading = ref(false);

watch(
  () => [props.open, props.orderId],
  async ([open, orderId]) => {
    if (!open || !orderId) {
      order.value = null;
      return;
    }
    loading.value = true;
    try {
      order.value = await getOrder(orderId);
    } catch (err) {
      toast.error(err.message || t('reports.sold_items_load_failed'));
    } finally {
      loading.value = false;
    }
  },
  { immediate: true }
);

const columns = [
  { key: 'name_snapshot', label: t('reports.col_product') },
  { key: 'qty', label: t('reports.col_qty') },
  { key: 'sell_price', label: t('reports.col_price') },
  { key: 'line_total', label: t('reports.col_subtotal') },
];

// T018 — klik nama produk membuka ProductDetailModal yang sudah ada
// (pola sama dengan openRowDetail() di SalesView.vue), disarangkan di
// dalam modal ini sendiri karena TransactionItemsModal sudah memegang
// seluruh state item — tidak perlu emit balik ke SalesView.
const showProductDetail = ref(false);
const detailProductId = ref(null);

function openProductDetail(item) {
  if (!item.product_id) return;
  detailProductId.value = item.product_id;
  showProductDetail.value = true;
}
</script>

<template>
  <BaseModal :open="open" :title="t('reports.sold_items_title')" max-width-class="max-w-[620px]" @close="emit('close')">
    <div class="px-6 py-5">
      <div class="overflow-hidden rounded-lg border border-line-2">
        <DataTable :columns="columns" :rows="order?.items ?? []" :loading="loading" :empty-message="t('reports.no_sold_items')">
          <template #cell-name_snapshot="{ row }">
            <button
              v-if="row.product_id"
              type="button"
              class="text-left font-semibold text-brand-active underline decoration-dotted hover:text-brand"
              @click="openProductDetail(row)"
            >
              {{ row.name_snapshot }}
            </button>
            <span v-else>{{ row.name_snapshot }}</span>
          </template>
          <template #cell-sell_price="{ row }">{{ formatIDR(row.sell_price) }}</template>
          <template #cell-line_total="{ row }">{{ formatIDR(row.line_total) }}</template>
        </DataTable>
      </div>
    </div>
  </BaseModal>

  <ProductDetailModal :open="showProductDetail" :product-id="detailProductId" @close="showProductDetail = false" />
</template>
