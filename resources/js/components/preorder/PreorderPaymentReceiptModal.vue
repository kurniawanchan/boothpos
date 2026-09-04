<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import { getPreorder } from '../../api/preorders';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { useToastStore } from '../../stores/toast';

/**
 * 010-split-payment-preorder-reports (US4) — struk pembayaran PER-EVENT
 * untuk sebuah pre-order, BUKAN reuse dari ReceiptModal.vue (order POS)
 * ataupun PreorderInvoiceModal.vue (invoice/konfirmasi pesanan). Alasan
 * lengkap ada di research.md R5: ReceiptModal.vue mengasumsikan field
 * khas order (order_number, cashier_name, change_amount,
 * discount_amount) yang tidak punya padanan di Preorder, sedangkan
 * PreorderInvoiceModal.vue adalah dokumen konfirmasi pesanan (total
 * dibayar vs sisa tagihan), bukan struk per transaksi pembayaran.
 * Komponen ini murni menampilkan SATU payment event yang ditunjuk lewat
 * prop `paymentId`, meski pre-order punya banyak riwayat pembayaran (DP,
 * lalu pelunasan, dst).
 *
 * Sumber data: GET /preorders/{id} (fungsi getPreorder() yang sudah ada)
 * — endpoint ini SUDAH memuat relasi `payments` (PreorderController::
 * show()/present()), jadi tidak perlu perubahan backend sama sekali.
 *
 * Mekanisme cetak: sama persis dengan PreorderInvoiceModal.vue /
 * ReceiptModal.vue — html2canvas merender DOM struk menjadi kanvas, lalu
 * jsPDF membungkusnya jadi satu halaman PDF berukuran pas (bukan A4).
 * Tidak ada rendering PDF sisi server.
 *
 * Header toko (nama/alamat/logo) SENGAJA tidak ditampilkan di sini —
 * berbeda dari ReceiptModal.vue, GET /preorders/{id} tidak
 * mengembalikan info toko itu, dan menambahkannya berarti mengubah
 * kontrak backend di luar cakupan T019 (research.md R5: "no backend
 * change needed"). Identitas dokumen cukup dari nomor pre-order +
 * pelanggan, konsisten dengan degradasi graceful yang sudah dipakai
 * ReceiptModal.vue/PreorderInvoiceModal.vue untuk field opsional yang
 * hilang (Edge Cases spec.md baris ~113).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  preorderId: { type: [Number, String, null], default: null },
  paymentId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const toast = useToastStore();

const preorder = ref(null);
const loading = ref(false);
const receiptEl = ref(null);
const downloadingPdf = ref(false);

const METHOD_LABELS = { cash: 'Tunai', bank_transfer: 'Transfer bank', qr_ewallet: 'QRIS / e-wallet' };

const STATUS_LABEL_KEY = {
  ordered: 'preorders.step_ordered',
  dp_paid: 'preorders.step_dp_paid',
  arrived: 'preorders.step_arrived',
  settled: 'preorders.step_settled',
  handed_over: 'preorders.step_handed_over',
  cancelled: 'events_sessions.status_cancelled',
};
const STATUS_VARIANT = { ordered: 'neutral', dp_paid: 'warn', arrived: 'mint', settled: 'mint', handed_over: 'dark', cancelled: 'danger' };

const statusLabel = computed(() => (preorder.value ? t(STATUS_LABEL_KEY[preorder.value.status] ?? preorder.value.status) : ''));
const statusVariant = computed(() => STATUS_VARIANT[preorder.value?.status] ?? 'neutral');

// Payment event yang ditunjuk lewat prop `paymentId`; jika tidak
// diberikan (atau tidak ditemukan), jatuh ke pembayaran paling akhir
// supaya komponen tidak crash saat dipakai tanpa id spesifik.
const payment = computed(() => {
  const list = preorder.value?.payments ?? [];
  if (!list.length) return null;
  if (props.paymentId != null) {
    const found = list.find((p) => String(p.id) === String(props.paymentId));
    if (found) return found;
  }
  return list[list.length - 1];
});

const paymentEventLabel = computed(() => {
  if (!payment.value) return '';
  const purposeLabel = payment.value.purpose === 'settlement' ? t('preorders.payment_event_settlement') : t('preorders.payment_event_down_payment');
  return `${purposeLabel} — ${formatDateTime(payment.value.paid_at)}`;
});

async function load() {
  if (!props.preorderId) {
    preorder.value = null;
    return;
  }
  loading.value = true;
  try {
    preorder.value = await getPreorder(props.preorderId);
  } catch (err) {
    toast.error(err.message || t('preorders.receipt_load_failed'));
  } finally {
    loading.value = false;
  }
}

watch(() => [props.open, props.preorderId], ([open]) => { if (open) load(); }, { immediate: true });

async function captureCanvas() {
  const { default: html2canvas } = await import('html2canvas');
  return html2canvas(receiptEl.value, { backgroundColor: '#ffffff', scale: 2 });
}

async function downloadAsPdf() {
  if (!receiptEl.value) return;
  downloadingPdf.value = true;
  try {
    const canvas = await captureCanvas();
    const { jsPDF } = await import('jspdf');
    const imgData = canvas.toDataURL('image/png');
    const widthPt = (canvas.width * 72) / 96;
    const heightPt = (canvas.height * 72) / 96;
    const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
    pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt);
    pdf.save(`struk-pembayaran-${preorder.value?.preorder_number ?? 'preorder'}.pdf`);
  } catch {
    toast.error(t('preorders.receipt_download_failed'));
  } finally {
    downloadingPdf.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" max-width-class="max-w-[430px]" @close="emit('close')">
    <div class="flex items-center gap-3 bg-ink px-6 py-5 text-white">
      <i class="ph-duotone ph-receipt text-[30px]" aria-hidden="true"></i>
      <div class="flex flex-col gap-0.5">
        <span class="text-[17px] font-bold tracking-tight">{{ t('preorders.payment_receipt_title') }}</span>
        <span class="text-[12.5px] text-mint-100">{{ t('preorders.payment_receipt_subtitle') }}</span>
      </div>
    </div>

    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">{{ t('common.loading_data') }}</div>
    <div v-else-if="preorder" ref="receiptEl" class="flex flex-col gap-[18px] bg-white px-6 py-6">
      <div class="flex flex-col items-center gap-1.5 text-center">
        <span
          class="rounded-full bg-warn-bg px-3 py-1 text-[11.5px] font-extrabold uppercase tracking-wide text-warn-text"
        >{{ t('preorders.preorder_marking_label') }}</span>
        <span class="mt-1 font-mono text-[15px] font-bold">{{ preorder.preorder_number }}</span>
        <span v-if="preorder.customer?.name" class="text-[12.5px] text-muted-2">{{ preorder.customer.name }}</span>
        <StatusPill :variant="statusVariant">{{ statusLabel }}</StatusPill>
      </div>

      <div v-if="payment" class="flex flex-col items-center gap-0.5 border-y border-dashed border-line-2 py-3 text-center">
        <span class="text-[11.5px] font-semibold uppercase tracking-wide text-muted-3">{{ t('preorders.payment_event_label') }}</span>
        <span class="text-[14px] font-bold">{{ paymentEventLabel }}</span>
      </div>

      <div class="flex flex-col gap-3 border-b border-dashed border-line-2 pb-4">
        <span class="text-[12.5px] font-bold text-muted-3">{{ t('preorders.ordered_items') }}</span>
        <div v-for="item in preorder.items" :key="item.id" class="flex items-start gap-2.5">
          <span class="min-w-[26px] text-[15px] font-bold text-brand-active">{{ item.qty }}×</span>
          <span class="flex-1 text-[14.5px] font-semibold leading-snug">{{ item.name_snapshot }}</span>
          <span class="text-[14.5px] font-bold">{{ formatIDR(item.line_total) }}</span>
        </div>
      </div>

      <div class="flex flex-col gap-2">
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">{{ t('preorders.total_amount') }}</span><span class="font-semibold">{{ formatIDR(preorder.total_amount) }}</span></div>
        <div v-if="payment" class="flex items-baseline justify-between border-t border-line-3 pt-2.5">
          <span class="text-[16px] font-bold">{{ paymentEventLabel }}</span>
          <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(payment.amount) }}</span>
        </div>
        <div v-if="payment" class="flex justify-between pt-1.5 text-[13.5px]">
          <span class="text-muted">{{ METHOD_LABELS[payment.method] ?? payment.method }}</span>
          <span class="font-semibold">{{ formatIDR(payment.amount) }}</span>
        </div>
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">{{ t('preorders.already_paid') }}</span><span class="font-semibold">{{ formatIDR(preorder.paid_amount) }}</span></div>
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">{{ t('preorders.outstanding') }}</span><span class="font-semibold">{{ formatIDR(preorder.outstanding) }}</span></div>
      </div>

      <p class="border-t border-dashed border-line-2 pt-3 text-center text-[11px] leading-relaxed text-muted-3">
        {{ t('preorders.payment_receipt_footer_note') }}
      </p>
    </div>

    <template #footer>
      <div class="flex flex-col gap-2">
        <BaseButton variant="secondary" class="w-full" :loading="downloadingPdf" :disabled="!preorder" @click="downloadAsPdf">
          <i class="ph-duotone ph-file-pdf text-[16px]" aria-hidden="true"></i>
          {{ t('preorders.download_pdf') }}
        </BaseButton>
        <BaseButton variant="primary" class="w-full" @click="emit('close')">{{ t('common.close') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
