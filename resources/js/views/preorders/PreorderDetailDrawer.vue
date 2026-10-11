<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  getPreorder,
  updatePreorderStatus,
  deletePreorderPayment,
  createPreorderPayment,
} from '../../api/preorders';
import { getPaymentProofBlobUrl, verifyPayment } from '../../api/payments';
import { useToastStore } from '../../stores/toast';
import { useAuthStore } from '../../stores/auth';
import { formatIDR, parseMoney } from '../../utils/money';
import BaseDrawer from '../../components/ui/BaseDrawer.vue';
import BaseModal from '../../components/ui/BaseModal.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseTextarea from '../../components/ui/BaseTextarea.vue';
import ConfirmDialog from '../../components/ui/ConfirmDialog.vue';
import ImageLightbox from '../../components/ui/ImageLightbox.vue';
import AddPaymentModal from '../../components/payment/AddPaymentModal.vue';
import PaymentConfirmationModal from '../../components/payment/PaymentConfirmationModal.vue';
import PreorderStatusCard from './PreorderStatusCard.vue';
import PreorderDispatchCard from './PreorderDispatchCard.vue';
import PreorderCustomerFulfillmentCard from './PreorderCustomerFulfillmentCard.vue';
import PreorderItemsCard from './PreorderItemsCard.vue';
import PreorderPaymentCard from './PreorderPaymentCard.vue';
import PreorderNotificationCard from './PreorderNotificationCard.vue';
import PreorderShipmentCard from './PreorderShipmentCard.vue';
import { CLOSED_STATUSES, usePreorderLabels } from './preorderMeta';

/**
 * Panel detail pre-order (drawer) beserta seluruh dialog yang lahir darinya: batalkan, tambah/hapus/verifikasi
 * pembayaran, konfirmasi bukti, lightbox. Dipecah dari PreordersView.vue (041) tanpa perubahan perilaku:
 * status transisi, pembayaran, dan penanda dispatch tetap lewat endpoint yang sama dan toast yang sama.
 *
 * Kontrak dengan pemanggil (daftar):
 *  - dibuka lewat ref: `open(row)` (baris daftar), `openById(id)` (deep-link/salinan), `refresh()`;
 *  - `hasDetailFor(id)` — apakah detail yang sedang dipegang milik pre-order itu (dipakai setelah Edit);
 *  - emit `reload` { summary } agar daftar (dan kartu ringkasan bila summary) dimuat ulang;
 *  - emit `duplicate` / `split` / `print-invoice` / `print-payment` — aksi yang dimiliki daftar.
 */
defineProps({
  duplicating: { type: Boolean, default: false },
});
const emit = defineEmits(['reload', 'duplicate', 'split', 'print-invoice', 'print-payment']);

const toast = useToastStore();
const auth = useAuthStore();
const { t } = useI18n();
const { STATUS_LABEL, FULFILLMENT_LABEL } = usePreorderLabels();
// Server menegakkan isOwnerOrAdmin() secara terpisah; ini hanya cermin kosmetik.
const isOwnerOrAdmin = computed(() => ['owner', 'admin'].includes((auth.role || '').toLowerCase()));

const showDetail = ref(false);
const detail = ref(null);
const detailLoading = ref(false);
const transitioning = ref(false);
const showCancelForm = ref(false);
const cancelReason = ref('');
const showRecordPayment = ref(false);
const initialShipment = ref(null);

async function open(row) {
  showDetail.value = true;
  detailLoading.value = true;
  initialShipment.value = null;
  try {
    const full = await getPreorder(row.id);
    // customer_name masih dibawa dari baris list yang sudah dimuat, bukan
    // dari respons ini (lihat openById di bawah untuk yang itu).
    detail.value = { ...full, customer_name: row.customer_name, fulfillment: full.fulfillment ?? row.fulfillment };
    // BUG YANG DITEMUKAN & DIPERBAIKI — `shipment` di atas selalu
    // dipaksa null dan TIDAK PERNAH diisi dari `full.shipment` (yang
    // present() sudah kembalikan sejak lama), jadi data pengiriman yang
    // sudah tersimpan tidak pernah tampil, layar selalu menunjukkan
    // "Belum ada data pengiriman" walau shipment sungguhan sudah ada di
    // database — ditemukan lewat verifikasi browser sungguhan.
    initialShipment.value = full.shipment ?? null;
  } finally {
    detailLoading.value = false;
  }
}

// 009-ui-ux-refinements US5 (T043) — deep-link entry point from
// CustomerTransactionsModal.vue (CustomersView.vue), which only has a
// preorder id, not a list row. Unlike open() above, GET
// /preorders/{id} (show()) DOES eager-load 'customer' (see
// PreorderController::show()), so customer_name/fulfillment come from the
// same response instead of a list row.
async function openById(id) {
  showDetail.value = true;
  detailLoading.value = true;
  initialShipment.value = null;
  try {
    const full = await getPreorder(id);
    detail.value = { ...full, customer_name: full.customer?.name ?? '', fulfillment: full.fulfillment };
    initialShipment.value = full.shipment ?? null;
  } catch (err) {
    showDetail.value = false;
    toast.error(err.message || t('preorders.load_failed'));
  } finally {
    detailLoading.value = false;
  }
}

async function refresh() {
  const full = await getPreorder(detail.value.id);
  detail.value = { ...detail.value, ...full };
}

function hasDetailFor(id) {
  return detail.value?.id === id;
}

defineExpose({ open, openById, refresh, hasDetailFor });

function mergeDetail(patch) {
  detail.value = { ...detail.value, ...patch };
}

async function markArrived() {
  transitioning.value = true;
  try {
    await updatePreorderStatus(detail.value.id, 'arrived');
    toast.success(t('preorders.arrived_marked'));
    emit('reload', { summary: false });
    await refresh();
  } catch {
    // 409 (lompatan status tidak valid) sudah ditoast global.
  } finally {
    transitioning.value = false;
  }
}

async function markHandedOver() {
  transitioning.value = true;
  try {
    await updatePreorderStatus(detail.value.id, 'handed_over');
    toast.success(t('preorders.handed_over_marked'));
    emit('reload', { summary: false });
    await refresh();
  } catch {
    // 409 (belum lunas) sudah ditoast global dengan pesan servernya.
  } finally {
    transitioning.value = false;
  }
}

async function submitCancel() {
  transitioning.value = true;
  try {
    await updatePreorderStatus(detail.value.id, 'cancelled', cancelReason.value || null);
    toast.success(t('preorders.preorder_cancelled'));
    showCancelForm.value = false;
    cancelReason.value = '';
    emit('reload', { summary: false });
    await refresh();
  } catch {
    // already toasted globally
  } finally {
    transitioning.value = false;
  }
}

// --- Pembayaran -------------------------------------------------------------
const paymentPurpose = computed(() => (detail.value?.status === 'arrived' ? 'settlement' : 'down_payment'));

// 028-partial-split-payment — AddPaymentModal menyimpan SATU pembayaran per klik
// lewat POST /preorders/{id}/payments dan mengembalikan pre-order terbaru; kita
// pakai itu langsung (ringkasan + riwayat segar) lalu menyegarkan list dan kartu
// ringkasan di atas tabel.
function submitPreorderPayment(payload) {
  return createPreorderPayment(detail.value.id, payload);
}

async function handlePaymentSaved(result) {
  showRecordPayment.value = false;
  if (result?.payment_summary) mergeDetail(result);
  else await refresh();
  emit('reload', { summary: true });
}

// 031-optional-payment-proof — bukti bayar opsional; bukti/referensi/catatan sebuah pembayaran
// non-tunai ditambah atau diubah belakangan lewat dialog yang sama dengan detail Sales. Server
// menentukan siapa yang boleh (`can_edit_confirmation`) dan mengembalikan pre-order terbaru.
const confirmationTarget = ref(null);

async function handleConfirmationSaved(result) {
  if (result?.id) mergeDetail(result);
  else await refresh();
  emit('reload', { summary: false });
}

// 032-mark-payment-verified — pembayaran non-tunai "Belum terverifikasi" ditandai terverifikasi
// setelah dicocokkan dengan mutasi bank/e-wallet. Satu arah dan FINAL, jadi lewat konfirmasi; siapa
// yang boleh dihitung SERVER per pembayaran (`can_verify`; pencatat tak boleh memverifikasi miliknya).
const verifyTarget = ref(null);
const verifying = ref(false);

async function performVerify() {
  if (!detail.value || !verifyTarget.value || verifying.value) return;
  verifying.value = true;
  try {
    const result = await verifyPayment('preorders', detail.value.id, verifyTarget.value.id);
    mergeDetail(result);
    toast.success(t('payment_ledger.verify_done'));
    emit('reload', { summary: true });
  } catch (err) {
    // 403/409/422 sudah di-toast interceptor global; 409 = layar basi → ambil ulang keadaan sebenarnya.
    if (err?.isConflict) {
      try { await refresh(); } catch { /* biarkan tampilan lama */ }
      emit('reload', { summary: false });
    }
  } finally {
    verifyTarget.value = null;
    verifying.value = false;
  }
}

// Tombol Tambah pembayaran: hanya selama masih ada sisa tagihan DAN pre-order
// belum ditutup (server menolak dengan 409 untuk handed_over/cancelled).
const canAddPayment = computed(
  () => !!detail.value && !CLOSED_STATUSES.includes(detail.value.status)
    && parseMoney(detail.value.payment_summary?.remaining ?? detail.value.outstanding) > 0,
);

// --- Hapus pembayaran --------------------------------------------------------
// Owner/admin saja dan hanya selama pre-order belum diserahkan/dibatalkan;
// server menegakkan keduanya (403/409), ini cermin UX supaya tombolnya tak
// ditawarkan. Status dihitung ulang server dari pembayaran yang tersisa.
const canDeletePayments = computed(
  () => isOwnerOrAdmin.value && !!detail.value && !CLOSED_STATUSES.includes(detail.value.status),
);
const paymentDeleteTarget = ref(null);
const showPaymentDeleteConfirm = ref(false);
const deletingPayment = ref(false);

function confirmDeletePayment(payment) {
  paymentDeleteTarget.value = payment;
  showPaymentDeleteConfirm.value = true;
}

async function performDeletePayment() {
  if (!detail.value || !paymentDeleteTarget.value) return;
  deletingPayment.value = true;
  try {
    const fresh = await deletePreorderPayment(detail.value.id, paymentDeleteTarget.value.id);
    mergeDetail(fresh);
    showPaymentDeleteConfirm.value = false;
    toast.success(t('preorders.payment_deleted', { status: STATUS_LABEL.value[fresh.status] ?? fresh.status }));
    emit('reload', { summary: true });
  } catch {
    // 409 sudah ditoast interceptor global; dialog tetap terbuka.
  } finally {
    deletingPayment.value = false;
  }
}

// --- Lightbox ---------------------------------------------------------------
// 024-invoice-layout-shipping-slip — bukti pembayaran (jika ada) dibuka di
// lightbox yang sama dengan yang dipakai QR pembayaran di tempat lain.
const proofLightboxSrc = ref(null);
const loadingProofId = ref(null);
async function viewPaymentProof(proofId) {
  loadingProofId.value = proofId;
  try {
    proofLightboxSrc.value = await getPaymentProofBlobUrl(proofId);
  } catch (err) {
    toast.error(err.message || t('preorders.proof_load_failed'));
  } finally {
    loadingProofId.value = null;
  }
}
function closeProofLightbox() {
  if (proofLightboxSrc.value) URL.revokeObjectURL(proofLightboxSrc.value);
  proofLightboxSrc.value = null;
}

// Thumbnail item pesanan — instance lightbox terpisah dari bukti pembayaran di atas, karena yang itu
// memiliki object URL yang harus di-revoke.
const itemLightboxSrc = ref(null);
const itemLightboxAlt = ref('');
function openItemLightbox(item) {
  if (!item.image_url) return;
  itemLightboxSrc.value = item.image_url;
  itemLightboxAlt.value = item.label ?? item.name_snapshot;
}
</script>

<template>
  <div>
    <BaseDrawer
      :open="showDetail"
      :title="detail?.preorder_number ?? ''"
      :subtitle="detail ? `${detail.customer_name} · ${FULFILLMENT_LABEL[detail.fulfillment]}` : ''"
      max-width-class="max-w-[880px]"
      @close="showDetail = false"
    >
      <div v-if="detailLoading" class="py-14 text-center text-[13px] text-muted-3">{{ t('preorders.loading') }}</div>
      <div v-else-if="detail" class="flex flex-col gap-[18px]">
        <PreorderStatusCard
          :detail="detail"
          :transitioning="transitioning"
          :duplicating="duplicating"
          @duplicate="(id) => emit('duplicate', id)"
          @split="(id) => emit('split', id)"
          @print-invoice="(d) => emit('print-invoice', d)"
          @print-payment="(paymentId) => emit('print-payment', paymentId, detail.id)"
          @open-detail="openById"
          @mark-arrived="markArrived"
          @mark-handed-over="markHandedOver"
          @cancel="showCancelForm = true"
        />

        <PreorderDispatchCard :detail="detail" @updated="mergeDetail" @reload="(p) => emit('reload', p)" />

        <PreorderCustomerFulfillmentCard :detail="detail" />

        <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1.35fr_1fr]">
          <PreorderItemsCard :detail="detail" @enlarge="openItemLightbox" />

          <PreorderPaymentCard
            :detail="detail"
            :can-delete-payments="canDeletePayments"
            :can-add-payment="canAddPayment"
            @view-proof="(p) => viewPaymentProof(p.proof_id)"
            @edit-confirmation="(p) => (confirmationTarget = p)"
            @verify="(p) => (verifyTarget = p)"
            @print="(p) => emit('print-payment', p.id, detail.id)"
            @delete="confirmDeletePayment"
            @add="showRecordPayment = true"
          />

          <PreorderNotificationCard :detail="detail" @notified="(n) => (detail.latest_notification = n)" />
        </div>

        <PreorderShipmentCard :detail="detail" :initial-shipment="initialShipment" />
      </div>
    </BaseDrawer>

    <BaseModal :open="showCancelForm" :title="t('preorders.cancel_preorder')" max-width-class="max-w-[420px]" @close="showCancelForm = false">
      <div class="flex flex-col gap-3.5 px-6 py-5">
        <BaseTextarea v-model="cancelReason" :label="t('preorders.cancel_reason')" :rows="3" />
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showCancelForm = false">{{ t('master_data.close') }}</BaseButton>
          <BaseButton variant="danger" :loading="transitioning" @click="submitCancel">{{ t('preorders.cancel_preorder') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <AddPaymentModal
      v-if="detail"
      :open="showRecordPayment"
      :remaining="detail.payment_summary?.remaining ?? detail.outstanding"
      :purpose="paymentPurpose"
      :title="detail.payment_summary?.payment_count > 0 ? t('payment_ledger.add_another_payment') : t('payment_ledger.add_payment')"
      :submit-fn="submitPreorderPayment"
      @close="showRecordPayment = false"
      @saved="handlePaymentSaved"
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
      v-if="detail"
      :open="confirmationTarget !== null"
      :payment="confirmationTarget"
      kind="preorders"
      :target-id="detail.id"
      @close="confirmationTarget = null"
      @saved="handleConfirmationSaved"
    />

    <ConfirmDialog
      :open="showPaymentDeleteConfirm"
      :title="t('preorders.delete_payment')"
      :message="t('preorders.delete_payment_confirm', { amount: formatIDR(paymentDeleteTarget?.amount ?? 0), number: detail?.preorder_number ?? '' })"
      :confirm-label="t('common.delete')"
      :loading="deletingPayment"
      @close="showPaymentDeleteConfirm = false"
      @confirm="performDeletePayment"
    />

    <ImageLightbox :open="!!proofLightboxSrc" :src="proofLightboxSrc" :alt="t('preorders.view_proof')" @close="closeProofLightbox" />
    <ImageLightbox :open="!!itemLightboxSrc" :src="itemLightboxSrc" :alt="itemLightboxAlt" @close="itemLightboxSrc = null" />
  </div>
</template>
