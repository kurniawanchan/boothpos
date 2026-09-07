<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { markInvoicePaid, cancelInvoice, deleteInvoice } from '../../api/invoices';
import { formatIDR } from '../../utils/money';
import { formatDate } from '../../utils/date';
import { useToastStore } from '../../stores/toast';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import StatusPill from '../ui/StatusPill.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';

/**
 * 019-billing-system (T057) — detail invoice mandiri, dibuka lewat klik
 * `invoice_number` di InvoicesView.vue. Download-as-image/PDF meniru PERSIS
 * pola ReceiptModal.vue (html2canvas → jsPDF, dynamic import, dua ref
 * loading terpisah) — lihat komentar di sana untuk alasan lengkapnya;
 * satu-satunya perbedaan adalah nama file memakai `invoice_number`.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  invoice: { type: Object, default: null },
});
const emit = defineEmits(['close', 'changed', 'edit']);

const { t } = useI18n();
const toast = useToastStore();

const detailEl = ref(null);
const downloadingImage = ref(false);
const downloadingPdf = ref(false);
const marking = ref(false);
const cancelling = ref(false);
const deleting = ref(false);
const showDeleteConfirm = ref(false);

function statusVariant(status) {
  if (status === 'paid') return 'mint';
  if (status === 'cancelled') return 'neutral';
  return 'warn';
}

const isUnpaid = computed(() => props.invoice?.status === 'unpaid');
// Edit/Delete are allowed for any status except 'paid' — mirrors
// InvoiceService::update()/delete()'s exact backend guard (only `paid`
// throws), NOT the narrower `isUnpaid` used by Mark Paid/Cancel above
// (research.md R13, T098).
const isEditable = computed(() => props.invoice?.status !== 'paid');

async function captureCanvas() {
  const { default: html2canvas } = await import('html2canvas');
  return html2canvas(detailEl.value, { backgroundColor: '#ffffff', scale: 2 });
}

async function downloadAsImage() {
  if (!detailEl.value) return;
  downloadingImage.value = true;
  try {
    const canvas = await captureCanvas();
    const link = document.createElement('a');
    link.href = canvas.toDataURL('image/png');
    link.download = `invoice-${props.invoice?.invoice_number ?? 'invoice'}.png`;
    document.body.appendChild(link);
    link.click();
    link.remove();
  } catch {
    toast.error(t('invoices.download_image_failed'));
  } finally {
    downloadingImage.value = false;
  }
}

async function downloadAsPdf() {
  if (!detailEl.value) return;
  downloadingPdf.value = true;
  try {
    const canvas = await captureCanvas();
    const { jsPDF } = await import('jspdf');
    const imgData = canvas.toDataURL('image/png');
    const widthPt = (canvas.width * 72) / 96;
    const heightPt = (canvas.height * 72) / 96;
    const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
    pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt);
    pdf.save(`invoice-${props.invoice?.invoice_number ?? 'invoice'}.pdf`);
  } catch {
    toast.error(t('invoices.download_pdf_failed'));
  } finally {
    downloadingPdf.value = false;
  }
}

async function doMarkPaid() {
  marking.value = true;
  try {
    await markInvoicePaid(props.invoice.id);
    toast.success(t('invoices.invoice_marked_paid'));
    emit('changed');
    emit('close');
  } catch {
    // 409 (transisi tidak valid) sudah ditoast oleh interceptor bersama.
  } finally {
    marking.value = false;
  }
}

async function doCancel() {
  cancelling.value = true;
  try {
    await cancelInvoice(props.invoice.id);
    toast.success(t('invoices.invoice_cancelled'));
    emit('changed');
    emit('close');
  } catch {
    // 409 sudah ditoast oleh interceptor bersama.
  } finally {
    cancelling.value = false;
  }
}

function doEdit() {
  emit('edit', props.invoice);
}

function confirmDelete() {
  showDeleteConfirm.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteInvoice(props.invoice.id);
    toast.success(t('invoices.invoice_deleted'));
    showDeleteConfirm.value = false;
    emit('changed');
    emit('close');
  } catch {
    // 409 (sudah lunas) sudah ditoast oleh interceptor bersama.
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('invoices.invoice_detail')" max-width-class="max-w-[520px]" @close="emit('close')">
    <div v-if="invoice" ref="detailEl" class="flex flex-col gap-4 bg-white px-6 py-5">
      <div class="flex items-start justify-between gap-3">
        <div class="flex flex-col gap-0.5">
          <span class="font-mono text-[15px] font-bold">{{ invoice.invoice_number }}</span>
          <span class="text-[12px] text-muted-3">{{ formatDate(invoice.created_at) }}</span>
        </div>
        <StatusPill :variant="statusVariant(invoice.status)">{{ t(`invoices.status_${invoice.status}`) }}</StatusPill>
      </div>

      <dl class="flex flex-col gap-2 border-y border-dashed border-line-2 py-4 text-[13.5px]">
        <div class="flex justify-between"><dt class="text-muted-3">{{ t('invoices.company') }}</dt><dd class="font-semibold">{{ invoice.company_name ?? invoice.company?.name ?? '—' }}</dd></div>
        <div class="flex justify-between"><dt class="text-muted-3">{{ t('invoices.business_type') }}</dt><dd class="font-semibold">{{ invoice.company?.business_type?.name ?? '—' }}</dd></div>
        <div class="flex justify-between"><dt class="text-muted-3">{{ t('invoices.license') }}</dt><dd class="font-semibold">{{ invoice.license?.name ?? '—' }}</dd></div>
        <div class="flex justify-between"><dt class="text-muted-3">{{ t('invoices.payment_type') }}</dt><dd class="font-semibold">{{ invoice.license?.payment_type ? t(`licenses.payment_type_${invoice.license.payment_type}`) : '—' }}</dd></div>
        <div class="flex justify-between"><dt class="text-muted-3">{{ t('invoices.due_date') }}</dt><dd class="font-semibold">{{ formatDate(invoice.due_date) }}</dd></div>
        <div v-if="invoice.paid_at" class="flex justify-between"><dt class="text-muted-3">{{ t('invoices.paid_at') }}</dt><dd class="font-semibold">{{ formatDate(invoice.paid_at) }}</dd></div>
      </dl>

      <div class="flex flex-col gap-2">
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">{{ t('invoices.subtotal') }}</span><span class="font-semibold">{{ formatIDR(invoice.subtotal) }}</span></div>
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">{{ t('invoices.discount') }}</span><span class="font-semibold text-danger-text">{{ formatIDR(invoice.discount) }}</span></div>
        <div class="flex items-baseline justify-between border-t border-line-3 pt-2.5">
          <span class="text-[16px] font-bold">{{ t('invoices.grand_total') }}</span>
          <span class="text-[26px] font-extrabold tracking-tight">{{ formatIDR(invoice.grand_total) }}</span>
        </div>
      </div>

      <div v-if="invoice.payment_information" class="border-t border-dashed border-line-2 pt-3">
        <span class="text-[12.5px] font-semibold text-muted-4">{{ t('invoices.payment_information') }}</span>
        <p class="whitespace-pre-line text-[13px] text-muted-3">{{ invoice.payment_information }}</p>
      </div>

      <div v-if="invoice.notes" class="border-t border-dashed border-line-2 pt-3">
        <span class="text-[12.5px] font-semibold text-muted-4">{{ t('master_data.notes') }}</span>
        <p class="whitespace-pre-line text-[13px] text-muted-3">{{ invoice.notes }}</p>
      </div>
    </div>

    <template #footer>
      <div class="flex flex-col gap-2">
        <div v-if="isUnpaid" class="flex gap-2">
          <BaseButton class="flex-1" :loading="marking" @click="doMarkPaid">{{ t('invoices.mark_paid_btn') }}</BaseButton>
          <BaseButton variant="danger" class="flex-1" :loading="cancelling" @click="doCancel">{{ t('common.cancel') }}</BaseButton>
        </div>
        <div class="flex gap-2">
          <BaseButton variant="secondary" class="flex-1" :loading="downloadingImage" :disabled="!invoice" @click="downloadAsImage">
            <i class="ph-duotone ph-image text-[16px]" aria-hidden="true"></i>
            {{ t('invoices.download_image') }}
          </BaseButton>
          <BaseButton variant="secondary" class="flex-1" :loading="downloadingPdf" :disabled="!invoice" @click="downloadAsPdf">
            <i class="ph-duotone ph-file-pdf text-[16px]" aria-hidden="true"></i>
            {{ t('invoices.download_pdf') }}
          </BaseButton>
        </div>
        <div v-if="isEditable" class="flex gap-2">
          <BaseButton variant="secondary" class="flex-1" @click="doEdit">
            <i class="ph-duotone ph-pencil-simple text-[16px]" aria-hidden="true"></i>
            {{ t('common.edit') }}
          </BaseButton>
          <BaseButton variant="danger" class="flex-1" @click="confirmDelete">
            <i class="ph-duotone ph-trash text-[16px]" aria-hidden="true"></i>
            {{ t('common.delete') }}
          </BaseButton>
        </div>
        <BaseButton variant="secondary" class="w-full" @click="emit('close')">{{ t('common.close') }}</BaseButton>
      </div>
    </template>
  </BaseModal>

  <ConfirmDialog
    :open="showDeleteConfirm"
    :title="t('invoices.delete_invoice')"
    :message="t('invoices.delete_invoice_confirm', { number: invoice?.invoice_number })"
    :confirm-label="t('vendors_materials.yes_delete')"
    :loading="deleting"
    @close="showDeleteConfirm = false"
    @confirm="performDelete"
  />
</template>
