<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import StatusPill from '../ui/StatusPill.vue';
import { getPurchaseOrder, recordPurchaseOrderPayment } from '../../api/purchaseOrders';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { useToastStore } from '../../stores/toast';

/**
 * 006-purchase-order-and-ops (US1) — cetak faktur memakai pola yang sama
 * persis dengan ReceiptModal.vue (html2canvas -> PNG -> jsPDF), lihat
 * research.md R6. Tidak ada PDF/gambar sisi server.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  purchaseOrderId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close', 'changed']);

const { t } = useI18n();
const toast = useToastStore();

const po = ref(null);
const loading = ref(false);
const invoiceEl = ref(null);
const downloadingPdf = ref(false);

const STATUS_VARIANT = { draft: 'neutral', ordered: 'warn', received: 'mint', paid: 'dark', cancelled: 'danger' };

async function load() {
  if (!props.purchaseOrderId) {
    po.value = null;
    return;
  }
  loading.value = true;
  try {
    po.value = await getPurchaseOrder(props.purchaseOrderId);
  } catch (err) {
    toast.error(err.message);
  } finally {
    loading.value = false;
  }
}

watch(() => [props.open, props.purchaseOrderId], ([open]) => { if (open) load(); }, { immediate: true });

async function downloadInvoicePdf() {
  if (!invoiceEl.value) return;
  downloadingPdf.value = true;
  try {
    const { default: html2canvas } = await import('html2canvas');
    const canvas = await html2canvas(invoiceEl.value, { backgroundColor: '#ffffff', scale: 2 });
    const { jsPDF } = await import('jspdf');
    const imgData = canvas.toDataURL('image/png');
    const widthPt = (canvas.width * 72) / 96;
    const heightPt = (canvas.height * 72) / 96;
    const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
    pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt);
    pdf.save(`${po.value?.po_number ?? 'purchase-order'}.pdf`);
  } catch {
    toast.error(t('purchase_orders.invoice_download_failed'));
  } finally {
    downloadingPdf.value = false;
  }
}

// --- Record a payment (US1 FR-005 payment tracking) ------------------
const showPaymentForm = ref(false);
const paymentForm = ref({ method: 'cash', amount: 0, notes: '' });
const recordingPayment = ref(false);

function openPaymentForm() {
  paymentForm.value = { method: 'cash', amount: 0, notes: '' };
  showPaymentForm.value = true;
}

async function submitPayment() {
  recordingPayment.value = true;
  try {
    await recordPurchaseOrderPayment(props.purchaseOrderId, {
      method: paymentForm.value.method,
      amount: Number(paymentForm.value.amount),
      notes: paymentForm.value.notes || null,
    });
    toast.success(t('purchase_orders.payment_recorded'));
    showPaymentForm.value = false;
    await load();
    emit('changed');
  } catch (err) {
    toast.error(err.message);
  } finally {
    recordingPayment.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="po?.po_number" max-width-class="max-w-[560px]" @close="emit('close')">
    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">{{ t('common.loading_data') }}</div>
    <div v-else-if="po" class="flex flex-col gap-4 px-6 py-5">
      <div class="flex items-center justify-between">
        <StatusPill :variant="STATUS_VARIANT[po.status]">{{ t(`purchase_orders.status_${po.status}`) }}</StatusPill>
        <BaseButton variant="secondary" size="sm" :loading="downloadingPdf" @click="downloadInvoicePdf">
          <i class="ph-duotone ph-file-pdf text-[15px]" aria-hidden="true"></i>
          {{ t('purchase_orders.print_invoice') }}
        </BaseButton>
      </div>

      <div ref="invoiceEl" class="flex flex-col gap-4 bg-white p-4">
        <div class="flex flex-col gap-0.5">
          <span class="text-[17px] font-extrabold tracking-tight">{{ po.po_number }}</span>
          <span class="text-[12.5px] text-muted-2">{{ po.vendor_name }}</span>
          <span class="text-[11.5px] text-muted-3">{{ formatDateTime(po.created_at) }}</span>
        </div>

        <div class="flex flex-col gap-2.5 border-y border-dashed border-line-2 py-3.5">
          <div v-for="item in po.items" :key="item.id" class="flex items-start gap-2.5">
            <span class="min-w-[26px] text-[13px] font-bold text-brand-active">{{ item.qty }}×</span>
            <div class="flex flex-1 flex-col gap-0.5">
              <span class="text-[13.5px] font-semibold leading-snug">{{ item.line_type === 'material' ? item.material_name : item.description }}</span>
              <span v-if="item.product_name" class="text-[11.5px] text-muted-3">{{ t('purchase_orders.linked_product') }}: {{ item.product_name }}</span>
            </div>
            <span class="text-[13.5px] font-bold">{{ formatIDR(item.line_total) }}</span>
          </div>
        </div>

        <div class="flex flex-col gap-1.5">
          <div class="flex justify-between text-[13px]"><span class="text-muted">{{ t('purchase_orders.total') }}</span><span class="font-bold">{{ formatIDR(po.total_amount) }}</span></div>
          <div class="flex justify-between text-[13px]"><span class="text-muted">{{ t('purchase_orders.paid') }}</span><span class="font-semibold">{{ formatIDR(po.paid_amount) }}</span></div>
        </div>
      </div>

      <div v-if="po.payments.length" class="flex flex-col gap-2 border-t border-line-6 pt-3">
        <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">{{ t('purchase_orders.payment_history') }}</span>
        <div v-for="p in po.payments" :key="p.id" class="flex flex-col gap-0.5 text-[12.5px]">
          <div class="flex justify-between"><span>{{ p.method }}</span><span class="font-semibold">{{ formatIDR(p.amount) }}</span></div>
          <span v-if="p.notes" class="text-muted-3">{{ p.notes }}</span>
        </div>
      </div>

      <BaseButton v-if="po.status === 'received' || po.status === 'paid'" variant="secondary" @click="openPaymentForm">
        <i class="ph-duotone ph-wallet text-[15px]" aria-hidden="true"></i>
        {{ t('purchase_orders.record_payment') }}
      </BaseButton>

      <form v-if="showPaymentForm" class="flex flex-col gap-3 rounded-lg border border-line-3 bg-surface-subtle p-3.5" @submit.prevent="submitPayment">
        <BaseSelect v-model="paymentForm.method" :label="t('pos.payment_method')" :options="[{ value: 'cash', label: t('pos.method_cash') }, { value: 'bank_transfer', label: t('pos.method_transfer') }, { value: 'qr_ewallet', label: t('pos.method_qris') }]" />
        <BaseInput v-model.number="paymentForm.amount" type="number" step="1" min="0.01" :label="t('purchase_orders.amount')" required />
        <BaseInput v-model="paymentForm.notes" :label="t('purchase_orders.payment_notes')" />
        <BaseButton :loading="recordingPayment" @click="submitPayment">{{ t('common.save') }}</BaseButton>
      </form>
    </div>

    <template #footer>
      <BaseButton variant="primary" class="w-full" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
    </template>
  </BaseModal>
</template>
