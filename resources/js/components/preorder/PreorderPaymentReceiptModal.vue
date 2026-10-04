<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import PreorderPaymentDocument from './PreorderPaymentDocument.vue';
import { getPreorderInvoice } from '../../api/preorders';
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
 *
 * 029-fix-bulk-invoice-logo — isi dokumen dipindah ke PreorderPaymentDocument.vue
 * agar unduh massal merender dokumen yang SAMA; modal ini tinggal memegang
 * header, tombol unduh, dan lightbox QR.
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
const lightboxSrc = ref(null);
const lightboxAlt = ref('');
function openLightbox({ src, alt }) {
  lightboxSrc.value = src;
  lightboxAlt.value = alt;
}

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
    <div v-else-if="preorder" ref="receiptEl">
      <PreorderPaymentDocument :invoice="preorder" :payment-id="paymentId" @enlarge="openLightbox" />
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
