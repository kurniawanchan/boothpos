<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import { getPreorderInvoice } from '../../api/preorders';
import { resolveAppName } from '../../utils/appName';
import { formatIDR, parseMoney } from '../../utils/money';
import { formatDate, formatDateTime, formatDateRange } from '../../utils/date';
import { downloadElementAsPdf } from '../../utils/pdfCapture';
import { useToastStore } from '../../stores/toast';

/**
 * 010-split-payment-preorder-reports (US4) — struk pembayaran PER-EVENT
 * untuk sebuah pre-order, BUKAN reuse dari ReceiptModal.vue (order POS).
 * Komponen ini murni menampilkan SATU payment event yang ditunjuk lewat
 * prop `paymentId`, meski pre-order punya banyak riwayat pembayaran (DP,
 * lalu pelunasan, dst).
 *
 * 022-preorder-invoice-crud-overhaul (US4, FR-012) — "Payment receipt" →
 * "Payment invoice", direstyle memakai shell visual yang SAMA dengan
 * PreorderInvoiceModal.vue (header identitas toko, daftar kanal
 * pembayaran, footer_text) — dicapai dengan mengganti sumber data dari
 * `getPreorder()` ke `getPreorderInvoice()` (endpoint yang SUDAH
 * memuat `payments` DAN field store_identity/payment_channels/
 * footer_text baru — lihat research.md Decision 1), bukan membangun
 * endpoint kedua. Perbedaan dengan invoice utama tetap dipertahankan:
 * blok "dibayar hari ini" untuk payment.value ini secara spesifik,
 * terpisah dari total/sisa tagihan keseluruhan order (FR-012).
 *
 * Mekanisme cetak: sama persis dengan PreorderInvoiceModal.vue —
 * html2canvas merender DOM menjadi kanvas, lalu jsPDF membungkusnya jadi
 * satu halaman PDF berukuran pas (bukan A4). Tidak ada rendering PDF sisi
 * server.
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
const appName = computed(() => resolveAppName(preorder.value?.app_name));
const loading = ref(false);
const receiptEl = ref(null);
const downloadingPdf = ref(false);
const lightboxSrc = ref(null);
const lightboxAlt = ref('');

const METHOD_LABELS = { cash: 'Tunai', bank_transfer: 'Transfer bank', qr_ewallet: 'QRIS / e-wallet' };

// event_available_on_date is null both when no restriction was chosen AND
// when it's 'both' days — the raw event_available_on tells them apart, so
// 'both' renders the event's own date range instead of a single date.
const availableOnDisplay = computed(() => {
  if (!preorder.value) return null;
  if (preorder.value.event_available_on === 'both') return formatDateRange(preorder.value.event_start_date, preorder.value.event_end_date);
  return preorder.value.event_available_on_date ? formatDate(preorder.value.event_available_on_date) : null;
});

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
  const purposeLabel = payment.value.purpose === 'settlement'
    ? t('preorders.payment_event_settlement')
    : (payment.value.purpose === 'full' ? t('preorders.full_payment') : t('preorders.payment_event_down_payment'));
  return `${purposeLabel} — ${formatDateTime(payment.value.paid_at)}`;
});

// 024-invoice-layout-shipping-slip — dicerminkan dari PreorderInvoiceModal.vue
// (research.md Decision 4/5), dokumen ini berbagi payload yang sama lewat
// getPreorderInvoice().
const qrChannels = computed(() => preorder.value?.payment_channels?.filter((c) => c.type === 'qr_ewallet') ?? []);
const bankChannels = computed(() => preorder.value?.payment_channels?.filter((c) => c.type === 'bank_transfer') ?? []);

async function load() {
  if (!props.preorderId) {
    preorder.value = null;
    return;
  }
  loading.value = true;
  try {
    preorder.value = await getPreorderInvoice(props.preorderId);
  } catch (err) {
    toast.error(err.message || t('preorders.receipt_load_failed'));
  } finally {
    loading.value = false;
  }
}

watch(() => [props.open, props.preorderId], ([open]) => { if (open) load(); }, { immediate: true });

async function downloadAsPdf() {
  if (!receiptEl.value) return;
  downloadingPdf.value = true;
  try {
    await downloadElementAsPdf(receiptEl.value, `invoice-pembayaran-${preorder.value?.preorder_number ?? 'preorder'}.pdf`);
  } catch {
    toast.error(t('preorders.receipt_download_failed'));
  } finally {
    downloadingPdf.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" max-width-class="max-w-[720px]" @close="emit('close')">
    <div class="flex items-center gap-3 bg-ink px-6 py-5 text-white">
      <i class="ph-duotone ph-receipt text-[30px]" aria-hidden="true"></i>
      <div class="flex flex-col gap-0.5">
        <span class="text-[17px] font-bold tracking-tight">{{ t('preorders.payment_receipt_title') }}</span>
        <span class="text-[12.5px] text-mint-100">{{ t('preorders.payment_receipt_subtitle') }}</span>
      </div>
    </div>

    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">{{ t('common.loading_data') }}</div>
    <div v-else-if="preorder" ref="receiptEl" class="flex flex-col gap-[18px] bg-white px-6 py-6">
      <!-- 022-preorder-invoice-crud-overhaul (US4, FR-012) — identitas toko
           SAMA PERSIS dengan invoice utama (research.md Decision 1). -->
      <div v-if="preorder.store_identity" class="flex flex-col items-center gap-1 border-b border-dashed border-line-2 pb-4 text-center">
        <img
          v-if="preorder.store_identity.logo_url"
          :src="preorder.store_identity.logo_url"
          :alt="t('preorders.store_logo_alt')"
          class="mb-1 h-24 w-auto max-w-[260px] rounded-md object-contain"
        />
        <span class="text-[17px] font-extrabold tracking-tight">{{ preorder.store_identity.name }}</span>
        <span v-if="preorder.store_identity.address" class="max-w-[300px] text-[11.5px] leading-snug text-muted-3">{{ preorder.store_identity.address }}</span>
      </div>

      <!-- 024-invoice-layout-shipping-slip (US1, dicerminkan dari
           PreorderInvoiceModal.vue, research.md Decision 2) — judul
           "Pre-Order Invoice" setelah garis putus, sebelum grid. -->
      <span class="block text-center text-[14px] font-bold">{{ t('preorders.invoice_doc_title') }}</span>
      <div class="grid grid-cols-2 gap-4">
        <!-- 023-event-availability-invoice-redesign (US2) — bg-brand diganti
             border rounded, teks putih diganti default. -->
        <div
          v-if="preorder.event_name || availableOnDisplay || preorder.event_location"
          class="flex flex-col items-center gap-1.5 rounded-lg border border-line-3 px-4 py-3 text-center"
        >
          <span v-if="preorder.event_name" class="text-[13.5px] font-extrabold">{{ preorder.event_name }}</span>
          <div v-if="preorder.event_location" class="flex flex-col gap-0.5 text-[13px]">
            <span class="font-semibold text-muted-3">{{ t('events_sessions.location') }}</span>
            <span class="font-bold">{{ preorder.event_location }}</span>
          </div>
          <div v-if="availableOnDisplay" class="flex flex-col gap-0.5 text-[13px]">
            <span class="font-semibold text-muted-3">{{ t('events_sessions.available_on_label') }}</span>
            <span class="font-bold">{{ availableOnDisplay }}</span>
          </div>
        </div>
        <div v-else></div>

        <div class="flex flex-col items-center gap-1.5 text-center">
          <span class="mt-1 font-mono text-[15px] font-bold">{{ preorder.preorder_number }}</span>
          <StatusPill :variant="statusVariant">{{ statusLabel }}</StatusPill>
          <span v-if="preorder.created_at" class="text-[11px] text-muted-3">{{ t('preorders.created_at_label') }}: {{ formatDateTime(preorder.created_at) }}</span>
          <div v-if="preorder.customer" class="mt-1 flex w-full flex-col gap-0.5 rounded-lg bg-surface-subtle px-3 py-2 text-left text-[11.5px]">
            <span class="text-center text-[10.5px] font-bold uppercase tracking-wide text-muted-3">{{ t('preorders.to_label') }}</span>
            <span v-if="preorder.customer.name" class="font-bold">{{ preorder.customer.name }}</span>
            <span v-if="preorder.customer.email" class="text-muted-2">{{ preorder.customer.email }}</span>
            <span v-if="preorder.customer.phone" class="text-muted-2">{{ preorder.customer.phone }}</span>
            <span v-if="preorder.customer.social_handle" class="text-muted-2">{{ preorder.customer.social_handle }}</span>
            <span v-if="preorder.customer.address" class="text-muted-2">{{ preorder.customer.address }}</span>
          </div>
        </div>
      </div>

      <div v-if="payment" class="flex flex-col items-center gap-0.5 border-y border-dashed border-line-2 py-3 text-center">
        <span class="text-[11.5px] font-semibold uppercase tracking-wide text-muted-3">{{ t('preorders.payment_event_label') }}</span>
        <span class="text-[14px] font-bold">{{ paymentEventLabel }}</span>
      </div>

      <!-- 023-event-availability-invoice-redesign (US3) — tabel yang sama
           dengan PreorderInvoiceModal.vue. -->
      <div class="flex flex-col gap-2 border-b border-dashed border-line-2 pb-4">
        <span class="text-[12.5px] font-bold text-muted-3">{{ t('preorders.ordered_items') }}</span>
        <table class="w-full border-collapse text-[13px]">
          <thead>
            <tr class="border-b border-line-2 text-left text-[11px] font-bold uppercase tracking-wide text-muted-3">
              <th class="py-2 pr-2">{{ t('preorders.col_product') }}</th>
              <th class="py-2 pr-2 text-right">{{ t('preorders.col_qty') }}</th>
              <th class="py-2 text-right">{{ t('preorders.col_line_total') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in preorder.items" :key="item.id" class="border-b border-dashed border-line-2 last:border-b-0">
              <td class="py-2.5 pr-2 font-semibold leading-snug">{{ item.name_snapshot }}</td>
              <td class="py-2.5 pr-2 text-right text-brand-active font-bold">{{ item.qty }}</td>
              <td class="py-2.5 text-right font-bold">{{ formatIDR(item.line_total) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- FR-012 — distinguishes what THIS payment covered from the
           order's overall total/remaining balance: the highlighted line
           is always "this payment", never the order's lifetime figures. -->
      <div class="flex flex-col gap-2">
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">{{ t('preorders.total_amount') }}</span><span class="font-semibold">{{ formatIDR(preorder.total_amount) }}</span></div>
        <div v-if="parseMoney(preorder.shipping_cost) > 0" class="flex justify-between text-[13.5px]">
          <span class="text-muted">{{ t('preorders.shipping_cost_label') }}</span>
          <span class="font-semibold">{{ formatIDR(preorder.shipping_cost) }}</span>
        </div>
        <div v-if="payment" class="flex items-baseline justify-between rounded-lg bg-mint-50 px-3 py-2.5">
          <span class="text-[13px] font-bold text-brand-active">{{ t('preorders.paid_this_time') }}</span>
          <span class="text-[26px] font-extrabold tracking-tight text-brand-active">{{ formatIDR(payment.amount) }}</span>
        </div>
        <div v-if="payment" class="flex justify-between pt-1.5 text-[13.5px]">
          <span class="text-muted">{{ METHOD_LABELS[payment.method] ?? payment.method }}</span>
          <span class="font-semibold">{{ formatIDR(payment.amount) }}</span>
        </div>
        <div class="flex justify-between border-t border-line-3 pt-2.5 text-[13.5px]"><span class="text-muted">{{ t('preorders.already_paid') }}</span><span class="font-semibold">{{ formatIDR(preorder.paid_amount) }}</span></div>
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">{{ t('preorders.outstanding') }}</span><span class="font-semibold">{{ formatIDR(preorder.outstanding) }}</span></div>
      </div>

      <!-- 024-invoice-layout-shipping-slip (US3, dicerminkan dari
           PreorderInvoiceModal.vue) — dua kolom QR/transfer bank. -->
      <div v-if="preorder.payment_channels?.length" class="flex flex-col gap-2.5 border-t border-dashed border-line-2 pt-3.5">
        <span class="text-center text-[11.5px] font-bold uppercase tracking-wide text-muted-3">{{ t('preorders.payment_terms_title') }}</span>
        <div class="grid grid-cols-2 gap-3">
          <div v-if="qrChannels.length" class="flex flex-col gap-2">
            <span class="text-center text-[10.5px] font-bold uppercase tracking-wide text-muted-3">{{ t('preorders.qr_payment_title') }}</span>
            <div v-for="channel in qrChannels" :key="channel.id" class="flex flex-col items-center gap-2 rounded-lg border border-line-3 bg-surface-subtle px-3 py-2.5">
              <button
                v-if="channel.qr_image_url"
                type="button"
                class="shrink-0"
                :aria-label="t('pos.enlarge_qr')"
                @click="lightboxSrc = channel.qr_image_url; lightboxAlt = channel.provider"
              >
                <img :src="channel.qr_image_url" :alt="channel.provider" class="h-32 max-w-full cursor-zoom-in rounded-md border border-line-2 object-contain" />
              </button>
              <div class="flex min-w-0 flex-col items-center gap-0.5 text-center">
                <span class="text-[12.5px] font-bold">{{ channel.provider }}</span>
                <span v-if="channel.account_name" class="text-[11px] text-muted-3">{{ t('pos.account_holder', { name: channel.account_name }) }}</span>
              </div>
            </div>
          </div>
          <div v-if="bankChannels.length" class="flex flex-col gap-2">
            <span class="text-center text-[10.5px] font-bold uppercase tracking-wide text-muted-3">{{ t('preorders.bank_payment_title') }}</span>
            <div v-for="channel in bankChannels" :key="channel.id" class="flex flex-col gap-0.5 rounded-lg border border-line-3 bg-surface-subtle px-3 py-2.5">
              <span class="text-[12.5px] font-bold">{{ channel.provider }}</span>
              <span v-if="channel.account_number" class="break-all font-mono text-[13px] font-semibold">{{ channel.account_number }}</span>
              <span v-if="channel.account_name" class="text-[11px] text-muted-3">{{ t('pos.account_holder', { name: channel.account_name }) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- 024-invoice-layout-shipping-slip (US5) — footer selalu tampil,
           fallback ke teks default bila belum dikonfigurasi. -->
      <p class="border-t border-dashed border-line-2 pt-3 text-center text-[11.5px] leading-relaxed text-muted-3">
        {{ t('preorders.payment_receipt_footer_note') }}
      </p>
      <p class="border-t border-dashed border-line-2 pt-3 text-center text-[11.5px] leading-relaxed text-muted-3">
        {{ preorder.footer_text || t('preorders.invoice_footer_default') }}
      </p>
      <p class="text-center text-[10.5px] text-muted-3">{{ t('common.powered_by', { name: appName }) }}</p>
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

    <ImageLightbox :open="!!lightboxSrc" :src="lightboxSrc" :alt="lightboxAlt" @close="lightboxSrc = null" />
  </BaseModal>
</template>
