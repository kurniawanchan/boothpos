<script setup>
import { ref, watch, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import { getPreorderInvoice } from '../../api/preorders';
import { formatIDR } from '../../utils/money';
import { formatDate, formatDateTime } from '../../utils/date';
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
 * 013-preorder-list-filters-receipt (US3, research.md R5) — restyle
 * memakai konvensi visual yang sudah ditetapkan
 * PreorderPaymentReceiptModal.vue (010): header terpusat dengan badge
 * "Pre-order" + StatusPill status granular yang berjalan (bukan sekadar
 * document_type badge yang sudah ada), pemisah item bergaris putus-putus,
 * dan tipografi total yang menonjol, mengikuti ReceiptModal.vue. Logika
 * heading/badge/footer berbasis document_type TETAP dipertahankan —
 * StatusPill ini TAMBAHAN, bukan pengganti. STATUS_LABEL_KEY/STATUS_VARIANT
 * sengaja diduplikasi dari PreorderPaymentReceiptModal.vue (belum ada
 * composable bersama untuk ini; menciptakannya di luar cakupan tugas ini).
 */
const STATUS_LABEL_KEY = {
  ordered: 'preorders.step_ordered',
  dp_paid: 'preorders.step_dp_paid',
  arrived: 'preorders.step_arrived',
  settled: 'preorders.step_settled',
  handed_over: 'preorders.step_handed_over',
  cancelled: 'events_sessions.status_cancelled',
};
const STATUS_VARIANT = { ordered: 'neutral', dp_paid: 'warn', arrived: 'mint', settled: 'mint', handed_over: 'dark', cancelled: 'danger' };
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

const statusLabel = computed(() => (invoice.value ? t(STATUS_LABEL_KEY[invoice.value.status] ?? invoice.value.status) : ''));
const statusVariant = computed(() => STATUS_VARIANT[invoice.value?.status] ?? 'neutral');

// 014-sales-receipt-event-footer (US2, FR-005/006/007) — logika sama
// dengan ReceiptModal.vue (research.md R3/R4), diduplikasi sengaja
// karena tidak ada shared component untuk footer dua-baris ini.
const eventInfoLine = computed(() => {
  if (!invoice.value) return '';
  const start = invoice.value.event_start_date;
  const end = invoice.value.event_end_date;
  if (start && end) {
    return start === end ? formatDate(start) : `${formatDate(start)} – ${formatDate(end)}`;
  }
  return formatDate(start || end || null) === '—' ? '' : formatDate(start || end);
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

      <div ref="docEl" class="flex flex-col gap-[18px] bg-white p-4">
        <div class="flex flex-col items-center gap-1.5 text-center">
          <span
            class="rounded-full bg-warn-bg px-3 py-1 text-[11.5px] font-extrabold uppercase tracking-wide text-warn-text"
          >{{ t('preorders.preorder_marking_label') }}</span>
          <span class="mt-1 font-mono text-[15px] font-bold">{{ invoice.preorder_number }}</span>
          <span v-if="invoice.customer?.name" class="text-[12.5px] text-muted-2">{{ invoice.customer.name }}</span>
          <StatusPill :variant="statusVariant">{{ statusLabel }}</StatusPill>
        </div>

        <div class="flex flex-col gap-3 border-y border-dashed border-line-2 py-4">
          <div v-for="item in invoice.items" :key="item.id" class="flex items-start gap-2.5">
            <span class="min-w-[26px] text-[15px] font-bold text-brand-active">{{ item.qty }}×</span>
            <div class="flex flex-1 flex-col gap-0.5">
              <span class="text-[14.5px] font-semibold leading-snug">{{ item.name_snapshot }}</span>
              <span class="text-[12px] text-muted-3">
                {{ formatIDR(item.sell_price) }}
                <template v-if="item.artist_name"> · {{ item.artist_name }}</template>
              </span>
            </div>
            <span class="text-[14.5px] font-bold">{{ formatIDR(item.line_total) }}</span>
          </div>
        </div>

        <div class="flex flex-col gap-2">
          <div class="flex items-baseline justify-between border-t border-line-3 pt-2.5">
            <span class="text-[16px] font-bold">{{ t('preorders.total_amount') }}</span>
            <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(invoice.total_amount) }}</span>
          </div>
          <div class="flex justify-between text-[13.5px]"><span class="text-muted">{{ t('preorders.paid_amount') }}</span><span class="font-semibold">{{ formatIDR(invoice.paid_amount) }}</span></div>
          <div v-if="invoice.document_type === 'invoice'" class="flex justify-between border-t border-line-3 pt-1.5 text-[14px]">
            <span class="font-bold">{{ t('preorders.outstanding') }}</span><span class="font-extrabold text-brand-active">{{ formatIDR(invoice.outstanding) }}</span>
          </div>
        </div>

        <p v-if="invoice.document_type === 'cancelled'" class="text-center text-[12px] font-semibold text-danger-text">
          {{ t('preorders.document_cancelled_note') }}
        </p>

        <!-- 014-sales-receipt-event-footer (US2) — hilang seluruhnya jika
             preorder tidak terikat event sama sekali (FR-005), atau jika
             event ada tapi tidak punya lokasi maupun tanggal (FR-006). -->
        <div
          v-if="invoice.event_name && (invoice.event_location || eventInfoLine)"
          class="flex flex-col items-center gap-0.5 border-t border-dashed border-line-2 pt-3 text-center text-[11px] text-muted-3"
        >
          <span v-if="invoice.event_location">{{ t('events_sessions.location') }}: {{ invoice.event_location }}</span>
          <span v-if="eventInfoLine">{{ t('events_sessions.col_dates') }}: {{ eventInfoLine }}</span>
        </div>
      </div>
    </div>

    <template #footer>
      <BaseButton variant="primary" class="w-full" @click="emit('close')">{{ t('common.close') }}</BaseButton>
    </template>
  </BaseModal>
</template>
