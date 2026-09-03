<script setup>
import { ref, watch, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import { getPreorderInvoice } from '../../api/preorders';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { useToastStore } from '../../stores/toast';

/**
 * 007-preorder-import-export-notify (US2) — cetak invoice/struk memakai
 * pola yang sama persis dengan ReceiptModal.vue/PurchaseOrderDetailModal.vue
 * (html2canvas -> PNG -> jsPDF); tidak ada PDF/gambar sisi server
 * (research.md R2). `document_type` datang dari backend
 * (PreorderDocumentType, satu sumber kebenaran) — komponen ini hanya
 * memilih judul/label berdasarkan nilai itu, tidak menghitung ulang
 * pemetaan status sendiri.
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

const heading = computed(() => {
  if (!invoice.value) return '';
  if (invoice.value.document_type === 'receipt') return t('preorders.document_receipt_title');
  if (invoice.value.document_type === 'cancelled') return t('preorders.document_cancelled_title');
  return t('preorders.document_invoice_title');
});

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
    const { default: html2canvas } = await import('html2canvas');
    const canvas = await html2canvas(docEl.value, { backgroundColor: '#ffffff', scale: 2 });
    const { jsPDF } = await import('jspdf');
    const imgData = canvas.toDataURL('image/png');
    const widthPt = (canvas.width * 72) / 96;
    const heightPt = (canvas.height * 72) / 96;
    const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
    pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt);
    const prefix = invoice.value.document_type === 'receipt' ? 'struk' : 'invoice';
    pdf.save(`${prefix}-${invoice.value.preorder_number}.pdf`);
  } catch {
    toast.error(t('preorders.invoice_download_failed'));
  } finally {
    downloadingPdf.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="invoice?.preorder_number" max-width-class="max-w-[480px]" @close="emit('close')">
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

      <div ref="docEl" class="flex flex-col gap-4 bg-white p-4">
        <div class="flex flex-col gap-0.5">
          <span class="text-[17px] font-extrabold tracking-tight">{{ invoice.preorder_number }}</span>
          <span class="text-[12.5px] text-muted-2">{{ invoice.customer?.name }}</span>
        </div>

        <div class="flex flex-col gap-2.5 border-y border-dashed border-line-2 py-3.5">
          <div v-for="item in invoice.items" :key="item.id" class="flex items-start gap-2.5">
            <span class="min-w-[26px] text-[13px] font-bold text-brand-active">{{ item.qty }}×</span>
            <span class="flex-1 text-[13.5px] font-semibold leading-snug">{{ item.name_snapshot }}</span>
            <span class="text-[13.5px] font-bold">{{ formatIDR(item.line_total) }}</span>
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <div class="flex justify-between text-[13px]"><span class="text-muted">{{ t('preorders.total_amount') }}</span><span class="font-bold">{{ formatIDR(invoice.total_amount) }}</span></div>
          <div class="flex justify-between text-[13px]"><span class="text-muted">{{ t('preorders.paid_amount') }}</span><span class="font-semibold">{{ formatIDR(invoice.paid_amount) }}</span></div>
          <div v-if="invoice.document_type === 'invoice'" class="flex justify-between border-t border-line-3 pt-1.5 text-[14px]">
            <span class="font-bold">{{ t('preorders.outstanding') }}</span><span class="font-extrabold text-brand-active">{{ formatIDR(invoice.outstanding) }}</span>
          </div>
        </div>

        <p v-if="invoice.document_type === 'cancelled'" class="text-center text-[12px] font-semibold text-danger-text">
          {{ t('preorders.document_cancelled_note') }}
        </p>
      </div>
    </div>

    <template #footer>
      <BaseButton variant="primary" class="w-full" @click="emit('close')">{{ t('common.close') }}</BaseButton>
    </template>
  </BaseModal>
</template>
