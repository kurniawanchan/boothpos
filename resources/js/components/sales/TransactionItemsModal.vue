<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import StatusPill from '../ui/StatusPill.vue';
import ProductDetailModal from '../product/ProductDetailModal.vue';
import ReceiptModal from '../receipt/ReceiptModal.vue';
import { getOrder, voidOrder } from '../../api/orders';
import { formatIDR, parseMoney } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { paymentMethodLabel } from '../../utils/paymentMethods';
import { useToastStore } from '../../stores/toast';
import { useAuthStore } from '../../stores/auth';

/**
 * Detail satu transaksi, dibuka dari halaman Sales (dan dari riwayat transaksi
 * pelanggan). Dulu hanya tabel "Produk Terjual" (009-ui-ux-refinements US2); kini
 * menjawab pertanyaan yang biasanya muncul saat membuka sebuah transaksi: siapa
 * pembelinya dan kasirnya, kapan dan di event mana, produk APA (foto, varian, SKU,
 * penjual, kategori) dengan diskonnya, BAGAIMANA total dihitung, dan bagaimana
 * dibayar. Modal/untung sengaja TIDAK ada — halaman ini terbuka untuk kasir.
 *
 * Struk (ReceiptModal) dibuka dari DALAM modal ini, sama seperti detail produk,
 * supaya berfungsi di mana pun modal ini dipakai ulang tanpa perlu perantara.
 *
 * Sumber data GET /orders/{order}: payload item-level hanya diambil saat sebuah
 * transaksi benar-benar dibuka (bukan dibungkus eager ke endpoint daftar).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close', 'changed']);

const toast = useToastStore();
const auth = useAuthStore();
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

const items = computed(() => order.value?.items ?? []);
const payments = computed(() => order.value?.payments ?? []);
const voided = computed(() => order.value?.status === 'voided');

const unitCount = computed(() => items.value.reduce((sum, i) => sum + Number(i.qty ?? 0), 0));

// Cara total dibentuk: kotor − diskon item − diskon pesanan = total. Dihitung dari
// baris-barisnya sendiri (bukan dari total tersimpan) supaya rinciannya selalu
// cocok dengan apa yang tampak di daftar item di atasnya.
const grossSubtotal = computed(() => items.value.reduce((sum, i) => sum + parseMoney(i.sell_price) * Number(i.qty ?? 0), 0));
const itemDiscountTotal = computed(() => items.value.reduce((sum, i) => sum + parseMoney(i.discount_amount), 0));
const orderDiscount = computed(() => parseMoney(order.value?.discount_amount));

const changeAmount = computed(() => parseMoney(order.value?.change_amount));

// Detail produk dibuka dari dalam modal ini (bukan lewat SalesView) karena modal ini
// sudah memegang seluruh state item.
const showProductDetail = ref(false);
const detailProductId = ref(null);

function openProductDetail(item) {
  if (!item.product_id) return;
  detailProductId.value = item.product_id;
  showProductDetail.value = true;
}

const showReceipt = ref(false);

// --- Batalkan transaksi -------------------------------------------------------------------
// Digerbang menu 'settings' — persis aturan server (OrderController::void() memetakan aksi
// ini ke canAccessMenu('settings')); ini hanya cermin tampilan, server tetap yang menolak (403).
// Hanya untuk transaksi yang masih berjalan: yang sudah batal tak bisa dibatalkan dua kali.
const canVoid = computed(() => auth.canAccessMenu('settings') && order.value?.status === 'completed');
const showVoid = ref(false);
const voidReason = ref('');
const voiding = ref(false);
const voidReasonValid = computed(() => voidReason.value.trim() !== '');

function askVoid() {
  voidReason.value = '';
  showVoid.value = true;
}

async function performVoid() {
  if (!voidReasonValid.value || voiding.value) return;
  voiding.value = true;
  try {
    await voidOrder(order.value.id, voidReason.value.trim());
    toast.success(t('reports.void_success'));
    showVoid.value = false;
    order.value = await getOrder(order.value.id); // tampilkan keadaan terbaru (banner batal)
    emit('changed'); // daftar di belakang perlu dimuat ulang
  } catch (err) {
    toast.error(err.message || t('reports.void_failed'));
  } finally {
    voiding.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('reports.order_detail_title')" max-width-class="max-w-[720px]" @close="emit('close')">
    <div class="flex flex-col gap-5 px-6 py-5">
      <p v-if="loading" class="py-8 text-center text-[13px] text-muted-3">{{ t('common.loading_data') }}</p>

      <template v-else-if="order">
        <div v-if="voided" role="alert" class="rounded-lg border border-danger-border bg-danger-bg px-4 py-3 text-[13px] text-danger-text">
          <strong>{{ t('reports.order_voided') }}</strong>
          <span v-if="order.void_reason"> — {{ t('reports.order_void_reason', { reason: order.void_reason }) }}</span>
        </div>

        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <span class="font-mono text-[16px] font-extrabold tracking-tight">{{ order.order_number }}</span>
          <span class="text-[12.5px] font-semibold text-muted-3">{{ t('reports.order_items_summary', { items: items.length, units: unitCount }) }}</span>
        </div>

        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
          <div class="flex flex-col gap-0.5">
            <dt class="text-[11.5px] font-semibold text-muted-3">{{ t('reports.col_time') }}</dt>
            <dd class="text-[13.5px]">{{ formatDateTime(order.created_at) }}</dd>
          </div>
          <div v-if="order.event" class="flex flex-col gap-0.5">
            <dt class="text-[11.5px] font-semibold text-muted-3">{{ t('reports.order_event') }}</dt>
            <dd class="text-[13.5px]"><span>{{ order.event.name }}</span></dd>
          </div>
          <div class="flex flex-col gap-0.5">
            <dt class="text-[11.5px] font-semibold text-muted-3">{{ t('reports.col_customer') }}</dt>
            <dd class="flex flex-col text-[13.5px]">
              <template v-if="order.customer">
                <span class="font-semibold">{{ order.customer.name }}</span>
                <span v-if="order.customer.phone" class="text-[12px] text-muted-3">{{ order.customer.phone }}</span>
                <span v-if="order.customer.email" class="text-[12px] text-muted-3">{{ order.customer.email }}</span>
              </template>
              <span v-else class="text-muted-3">{{ t('reports.walkin') }}</span>
            </dd>
          </div>
          <div v-if="order.cashier" class="flex flex-col gap-0.5">
            <dt class="text-[11.5px] font-semibold text-muted-3">{{ t('reports.col_cashier') }}</dt>
            <dd class="text-[13.5px]"><span>{{ order.cashier.name }}</span></dd>
          </div>
          <div v-if="order.notes" class="flex flex-col gap-0.5 sm:col-span-2">
            <dt class="text-[11.5px] font-semibold text-muted-3">{{ t('reports.order_notes') }}</dt>
            <dd class="text-[13.5px]"><span>{{ order.notes }}</span></dd>
          </div>
        </dl>

        <p v-if="items.length === 0" class="text-[13px] text-muted-3">{{ t('reports.no_sold_items') }}</p>
        <ul v-else class="flex flex-col divide-y divide-line-6 rounded-lg border border-line-2">
          <li v-for="item in items" :key="item.id" class="flex gap-3.5 px-4 py-3.5">
            <img
              v-if="item.image_url"
              :src="item.image_url"
              :alt="item.name_snapshot"
              class="h-14 w-14 flex-none rounded-lg border border-line-2 object-cover"
            />
            <div v-else class="flex h-14 w-14 flex-none items-center justify-center rounded-lg border border-dashed border-line-2 bg-line-7 text-muted-4" aria-hidden="true">
              <i class="ph-duotone ph-image text-[22px]"></i>
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-1">
              <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <button
                  v-if="item.product_id"
                  type="button"
                  class="text-left text-[13.5px] font-bold text-brand-active underline decoration-dotted hover:text-brand"
                  @click="openProductDetail(item)"
                >{{ item.name_snapshot }}</button>
                <span v-else class="text-[13.5px] font-bold">{{ item.name_snapshot }}</span>
                <span v-if="item.variant_name" class="rounded-full bg-line-7 px-2 py-0.5 text-[11px] font-semibold text-muted-4">{{ item.variant_name }}</span>
              </div>
              <div class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5 text-[11.5px] text-muted-3">
                <span v-if="item.sku_snapshot" class="font-mono">{{ item.sku_snapshot }}</span>
                <span v-if="item.artist_name">{{ item.artist_name }}</span>
                <span v-if="item.category_name">{{ item.category_name }}</span>
              </div>
            </div>

            <div class="flex flex-none flex-col items-end gap-0.5 text-right">
              <span class="text-[14px] font-extrabold">{{ formatIDR(item.line_total) }}</span>
              <span class="text-[11.5px] text-muted-3">{{ item.qty }} × {{ formatIDR(item.sell_price) }}</span>
              <span v-if="parseMoney(item.discount_amount) > 0" class="text-[11.5px] font-semibold text-danger-text">{{ t('reports.item_discount', { amount: formatIDR(item.discount_amount) }) }}</span>
            </div>
          </li>
        </ul>

        <div data-testid="order-totals" class="ml-auto flex w-full max-w-[320px] flex-col gap-1.5 text-[13px]">
          <div class="flex justify-between"><span class="text-muted-4">{{ t('reports.order_subtotal') }}</span><span class="font-semibold">{{ formatIDR(grossSubtotal) }}</span></div>
          <div v-if="itemDiscountTotal > 0" class="flex justify-between"><span class="text-muted-4">{{ t('reports.order_item_discounts') }}</span><span class="font-semibold text-danger-text">−{{ formatIDR(itemDiscountTotal) }}</span></div>
          <div v-if="orderDiscount > 0" class="flex justify-between"><span class="text-muted-4">{{ t('reports.order_discount') }}</span><span class="font-semibold text-danger-text">−{{ formatIDR(orderDiscount) }}</span></div>
          <div class="flex items-baseline justify-between border-t border-dashed border-line-2 pt-2"><span class="text-[13.5px] font-bold">{{ t('reports.col_total') }}</span><span class="text-[19px] font-extrabold tracking-tight">{{ formatIDR(order.total_amount) }}</span></div>
        </div>

        <div v-if="payments.length" data-testid="order-payments" class="flex flex-col gap-2 rounded-lg border border-line-2 px-4 py-3.5">
          <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">{{ t('reports.order_payments') }}</span>
          <div v-for="payment in payments" :key="payment.id" class="flex items-baseline justify-between gap-3 text-[13px]">
            <span class="flex flex-col">
              <span class="font-semibold">{{ paymentMethodLabel(t, payment.method) }}<span v-if="payment.provider" class="font-normal text-muted-3"> · {{ payment.provider }}</span></span>
              <span v-if="payment.paid_at" class="text-[11.5px] text-muted-3">{{ formatDateTime(payment.paid_at) }}</span>
              <StatusPill v-if="payment.verification === 'pending'" variant="warn" class="mt-0.5 self-start">{{ t('reports.payment_state_pending') }}</StatusPill>
              <StatusPill v-else-if="payment.verification === 'rejected'" variant="danger" class="mt-0.5 self-start">{{ t('reports.payment_state_rejected') }}</StatusPill>
            </span>
            <span class="font-semibold">{{ formatIDR(payment.amount) }}</span>
          </div>
          <div class="mt-1 flex justify-between border-t border-dashed border-line-2 pt-2 text-[12.5px]">
            <span class="text-muted-4">{{ t('reports.order_paid') }}</span><span class="font-semibold">{{ formatIDR(order.paid_amount) }}</span>
          </div>
          <div v-if="changeAmount > 0" class="flex justify-between text-[12.5px]">
            <span class="text-muted-4">{{ t('reports.order_change') }}</span><span class="font-semibold">{{ formatIDR(changeAmount) }}</span>
          </div>
        </div>
      </template>
    </div>

    <template #footer>
      <div class="flex items-center justify-end gap-2.5">
        <BaseButton v-if="canVoid" variant="danger" class="mr-auto" @click="askVoid">{{ t('reports.void_action') }}</BaseButton>
        <BaseButton variant="secondary" :disabled="!order" @click="showReceipt = true">{{ t('reports.view_receipt') }}</BaseButton>
        <BaseButton variant="secondary" @click="emit('close')">{{ t('master_data.close') }}</BaseButton>
      </div>
    </template>
  </BaseModal>

  <BaseModal :open="showVoid" :title="t('reports.void_title')" max-width-class="max-w-[440px]" @close="showVoid = false">
    <div class="flex flex-col gap-3.5 px-6 py-5">
      <p class="text-[13px] leading-relaxed text-muted-4">{{ t('reports.void_warning') }}</p>
      <BaseTextarea v-model="voidReason" :label="t('reports.void_reason_label')" :rows="3" />
    </div>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" :disabled="voiding" @click="showVoid = false">{{ t('reports.void_back') }}</BaseButton>
        <BaseButton variant="danger" :disabled="!voidReasonValid" :loading="voiding" @click="performVoid">{{ t('reports.void_confirm') }}</BaseButton>
      </div>
    </template>
  </BaseModal>

  <ProductDetailModal :open="showProductDetail" :product-id="detailProductId" @close="showProductDetail = false" />
  <ReceiptModal :open="showReceipt" :order-id="order?.id ?? null" @close="showReceipt = false" />
</template>
