<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import StatusPill from '../ui/StatusPill.vue';
import ProductDetailModal from '../product/ProductDetailModal.vue';
import ReceiptModal from '../receipt/ReceiptModal.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import PaymentSummaryCard from '../payment/PaymentSummaryCard.vue';
import PaymentHistoryList from '../payment/PaymentHistoryList.vue';
import AddPaymentModal from '../payment/AddPaymentModal.vue';
import PaymentConfirmationModal from '../payment/PaymentConfirmationModal.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import { getOrder, voidOrder, addOrderPayment, deleteOrderPayment } from '../../api/orders';
import { getPaymentProofBlobUrl, verifyPayment } from '../../api/payments';
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

// --- Pembayaran (028-partial-split-payment, US5) -------------------------------------------
// Ringkasan + riwayat bersama dengan pre-order; Tambah pembayaran selama masih ada sisa dan
// penjualannya tidak batal; Hapus hanya owner/admin (server menegakkan: 403 / 409).
const isOwnerOrAdmin = computed(() => ['owner', 'admin'].includes((auth.role || '').toLowerCase()));
const canAddPayment = computed(() => !!order.value && !voided.value && parseMoney(order.value.payment_summary?.remaining) > 0);
const canDeletePayments = computed(() => isOwnerOrAdmin.value && !voided.value);
const showAddPayment = ref(false);
const paymentDeleteTarget = ref(null);
const deletingPayment = ref(false);

function submitOrderPayment(payload) {
  return addOrderPayment(order.value.id, payload);
}

function handlePaymentSaved(result) {
  showAddPayment.value = false;
  if (result?.id) order.value = result;
  emit('changed'); // daftar di belakang (status pembayaran, sisa) perlu dimuat ulang
}

async function performDeletePayment() {
  if (!paymentDeleteTarget.value || deletingPayment.value) return;
  deletingPayment.value = true;
  try {
    order.value = await deleteOrderPayment(order.value.id, paymentDeleteTarget.value.id);
    paymentDeleteTarget.value = null;
    emit('changed');
  } catch {
    // 409 (order batal / tunai shift yang sudah ditutup) sudah di-toast interceptor global.
  } finally {
    deletingPayment.value = false;
  }
}

// --- Konfirmasi pembayaran (031-optional-payment-proof) ------------------------------------
// Bukti bayar opsional saat checkout; di sini bukti, referensi, dan catatan pembayaran
// non-tunai bisa ditambah/diubah belakangan. Siapa yang boleh (owner/admin atau pencatat) dan
// siapa yang boleh membuka file bukti dihitung SERVER per pembayaran (`can_edit_confirmation`,
// `can_view_proof`); modal ini hanya menampilkan aksinya.
const confirmationTarget = ref(null);
const proofLightboxSrc = ref(null);

function handleConfirmationSaved(result) {
  if (result?.id) order.value = result;
  emit('changed');
}

async function viewPaymentProof(payment) {
  try {
    proofLightboxSrc.value = await getPaymentProofBlobUrl(payment.proof_id);
  } catch (err) {
    toast.error(err.message || t('preorders.proof_load_failed'));
  }
}

function closeProofLightbox() {
  if (proofLightboxSrc.value) URL.revokeObjectURL(proofLightboxSrc.value);
  proofLightboxSrc.value = null;
}

// --- Verifikasi pembayaran (032-mark-payment-verified) ------------------------------------
// Pembayaran non-tunai "Belum terverifikasi" ditandai terverifikasi setelah dicocokkan dengan mutasi
// bank/e-wallet. Satu arah dan FINAL (tak ada pembatalan), maka selalu lewat konfirmasi. Siapa yang
// boleh dihitung SERVER per pembayaran (`can_verify`; pencatat pembayaran tak boleh memverifikasi
// miliknya); modal ini hanya menampilkan aksinya.
const verifyTarget = ref(null);
const verifying = ref(false);

async function performVerify() {
  if (!verifyTarget.value || verifying.value) return;
  verifying.value = true;
  try {
    order.value = await verifyPayment('orders', order.value.id, verifyTarget.value.id);
    toast.success(t('payment_ledger.verify_done'));
    emit('changed'); // badge "Belum terverifikasi" di daftar perlu dimuat ulang
  } catch (err) {
    // 403/409/422 sudah di-toast interceptor global. 409 = layar basi (sudah terverifikasi oleh orang
    // lain, atau transaksi batal): ambil ulang supaya entri menampilkan keadaan sebenarnya.
    if (err?.isConflict) {
      try { order.value = await getOrder(order.value.id); } catch { /* biarkan tampilan lama */ }
      emit('changed');
    }
  } finally {
    verifyTarget.value = null;
    verifying.value = false;
  }
}

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

        <div v-if="payments.length || order.payment_summary" data-testid="order-payments" class="flex flex-col gap-3 rounded-lg border border-line-2 px-4 py-3.5">
          <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">{{ t('reports.order_payments') }}</span>
          <PaymentSummaryCard v-if="order.payment_summary" :summary="order.payment_summary" />
          <PaymentHistoryList
            :payments="payments"
            :show-proof="true"
            :show-print="false"
            :can-delete="canDeletePayments"
            @delete="(p) => (paymentDeleteTarget = p)"
            @view-proof="viewPaymentProof"
            @verify="(p) => (verifyTarget = p)"
            @edit-confirmation="(p) => (confirmationTarget = p)"
          />
          <div v-if="changeAmount > 0" class="flex justify-between text-[12.5px]">
            <span class="text-muted-4">{{ t('reports.order_change') }}</span><span class="font-semibold">{{ formatIDR(changeAmount) }}</span>
          </div>
          <BaseButton v-if="canAddPayment" data-testid="add-payment" @click="showAddPayment = true">
            <i class="ph-duotone ph-plus-circle text-[17px]" aria-hidden="true"></i>
            {{ order.payment_summary?.payment_count > 0 ? t('payment_ledger.add_another_payment') : t('payment_ledger.add_payment') }}
          </BaseButton>
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

  <AddPaymentModal
    v-if="order"
    :open="showAddPayment"
    :remaining="order.payment_summary?.remaining ?? '0.00'"
    purpose="full"
    :title="order.payment_summary?.payment_count > 0 ? t('payment_ledger.add_another_payment') : t('payment_ledger.add_payment')"
    :submit-fn="submitOrderPayment"
    @close="showAddPayment = false"
    @saved="handlePaymentSaved"
  />
  <ConfirmDialog
    :open="paymentDeleteTarget !== null"
    :title="t('preorders.delete_payment')"
    :message="t('payment_ledger.delete_confirm_order', { amount: formatIDR(paymentDeleteTarget?.amount ?? 0), number: order?.order_number ?? '' })"
    :confirm-label="t('common.delete')"
    :loading="deletingPayment"
    @close="paymentDeleteTarget = null"
    @confirm="performDeletePayment"
  />

  <ConfirmDialog
    :open="verifyTarget !== null"
    :title="t('payment_ledger.verify_confirm_title')"
    :message="t('payment_ledger.verify_confirm_message', { amount: formatIDR(verifyTarget?.amount ?? 0) })"
    :confirm-label="t('payment_ledger.verify_confirm_label')"
    :loading="verifying"
    @close="verifyTarget = null"
    @confirm="performVerify"
  />
  <PaymentConfirmationModal
    v-if="order"
    :open="confirmationTarget !== null"
    :payment="confirmationTarget"
    kind="orders"
    :target-id="order.id"
    @close="confirmationTarget = null"
    @saved="handleConfirmationSaved"
  />
  <ImageLightbox :open="!!proofLightboxSrc" :src="proofLightboxSrc" :alt="t('preorders.view_proof')" @close="closeProofLightbox" />

  <ProductDetailModal :open="showProductDetail" :product-id="detailProductId" @close="showProductDetail = false" />
  <ReceiptModal :open="showReceipt" :order-id="order?.id ?? null" @close="showReceipt = false" />
</template>
