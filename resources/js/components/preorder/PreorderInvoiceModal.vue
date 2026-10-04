<script setup>
import { ref, watch, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import PreorderInvoiceDocument from './PreorderInvoiceDocument.vue';
import { getPreorderInvoice } from '../../api/preorders';
import { downloadElementAsPdf } from '../../utils/pdfCapture';
import { useToastStore } from '../../stores/toast';

/**
 * 007-preorder-import-export-notify (US2) — cetak invoice/struk memakai
 * pola yang sama persis dengan ReceiptModal.vue/PurchaseOrderDetailModal.vue
 * (html2canvas -> PNG -> jsPDF); tidak ada PDF/gambar sisi server
 * (research.md R2). `document_type` datang dari backend
 * (PreorderDocumentType, satu sumber kebenaran) — komponen ini hanya
 * memilih judul/label berdasarkan nilai itu, tidak menghitung ulang
 * pemetaan status sendiri.
 *
 * 029-fix-bulk-invoice-logo — isi dokumen (logo/identitas toko, header,
 * tabel, kanal pembayaran, footer) dipindah ke PreorderInvoiceDocument.vue
 * agar unduh massal merender dokumen yang SAMA persis; modal ini tinggal
 * memegang judul, tombol unduh, dan lightbox QR.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  preorderId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const toast = useToastStore();

const invoice = ref(null);
const loading = ref(false);
const docEl = ref(null);
const downloadingPdf = ref(false);
// 022-preorder-invoice-crud-overhaul (US3, FR-011) — QR pembayaran yang
// sedang diperbesar; ImageLightbox.vue-nya sama persis dengan yang dipakai
// ChannelPicker.vue (research.md Decision 8).
const lightboxSrc = ref(null);
const lightboxAlt = ref('');
function openLightbox({ src, alt }) {
  lightboxSrc.value = src;
  lightboxAlt.value = alt;
}

const heading = computed(() => {
  if (!invoice.value) return '';
  if (invoice.value.document_type === 'receipt') return t('preorders.document_receipt_title');
  if (invoice.value.document_type === 'cancelled') return t('preorders.document_cancelled_title');
  return t('preorders.document_invoice_title');
});

// 024-invoice-layout-shipping-slip (US2, research.md Decision 6) — judul
// jendela dokumen, TERPISAH dari badge "Invoice" kecil di dalam badan
// dokumen (heading di atas, document_invoice_title) — bukan reuse.
const documentTitle = computed(() => (invoice.value ? `${t('preorders.document_title_invoice')} — ${invoice.value.preorder_number}` : ''));

async function load() {
  if (!props.preorderId) {
    invoice.value = null;
    return;
  }
  loading.value = true;
  try {
    invoice.value = await getPreorderInvoice(props.preorderId);
  } catch (err) {
    toast.error(err.message);
  } finally {
    loading.value = false;
  }
}

watch(() => [props.open, props.preorderId], ([open]) => { if (open) load(); }, { immediate: true });

async function downloadPdf() {
  if (!docEl.value) return;
  downloadingPdf.value = true;
  try {
    await downloadElementAsPdf(docEl.value, `invoice-${invoice.value.preorder_number}.pdf`);
  } catch {
    toast.error(t('preorders.invoice_download_failed'));
  } finally {
    downloadingPdf.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="documentTitle" max-width-class="max-w-[720px]" @close="emit('close')">
    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">{{ t('common.loading_data') }}</div>
    <div v-else-if="invoice" class="flex flex-col gap-4 px-6 py-5">
      <div class="flex items-center justify-between">
        <span
          class="rounded-full px-3 py-1 text-[11.5px] font-bold"
          :class="invoice.document_type === 'cancelled' ? 'bg-danger-bg text-danger-text' : 'bg-mint-100 text-brand-active'"
        >{{ heading }}</span>
        <BaseButton variant="secondary" size="sm" :loading="downloadingPdf" @click="downloadPdf">
          <i class="ph-duotone ph-file-pdf text-[15px]" aria-hidden="true"></i>
          {{ t('preorders.download_pdf') }}
        </BaseButton>
      </div>

      <div ref="docEl">
        <PreorderInvoiceDocument :invoice="invoice" @enlarge="openLightbox" />
      </div>
    </div>

    <template #footer>
      <BaseButton variant="primary" class="w-full" @click="emit('close')">{{ t('common.close') }}</BaseButton>
    </template>

    <ImageLightbox :open="!!lightboxSrc" :src="lightboxSrc" :alt="lightboxAlt" @close="lightboxSrc = null" />
  </BaseModal>
</template>
