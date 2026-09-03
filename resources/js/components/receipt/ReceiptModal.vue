<script setup>
import { ref, watch } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import { getReceipt } from '../../api/orders';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { useToastStore } from '../../stores/toast';

/**
 * 002-language-toggle FR-009 — labels in this component's template
 * ("Subtotal", "Diskon", "Kembalian", "Kasir", dst.) are SENGAJA hardcoded
 * Indonesian, not wrapped in t(). The receipt is read by the CUSTOMER, not
 * the cashier operating the app — it must always be Indonesian regardless
 * of the logged-in cashier's language preference. Do not "fix" these into
 * t() calls.
 *
 * Also doubles as a historical-receipt viewer (Task 3, Sales report
 * "Lihat struk" click-through) — GET /orders/{order}/receipt works
 * identically for a just-completed order or an old one, so no separate
 * component was needed.
 *
 * Download-as-image/PDF is client-side rasterization of this same DOM
 * (html2canvas → PNG, then jsPDF wraps that raster into a single-page
 * PDF) — no backend PDF/image generation exists or is planned. This keeps
 * the receipt layout above as the single source of truth rather than
 * duplicating it into a second PDF-specific template.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [Number, String, null], default: null },
  closeLabel: { type: String, default: 'Transaksi berikutnya' },
});
const emit = defineEmits(['close']);

const toast = useToastStore();
const receipt = ref(null);
const loading = ref(false);
const receiptEl = ref(null);
const downloadingImage = ref(false);
const downloadingPdf = ref(false);

const METHOD_LABELS = { cash: 'Tunai', bank_transfer: 'Transfer bank', qr_ewallet: 'QRIS / e-wallet' };

async function captureCanvas() {
  const { default: html2canvas } = await import('html2canvas');
  return html2canvas(receiptEl.value, { backgroundColor: '#ffffff', scale: 2 });
}

async function downloadAsImage() {
  if (!receiptEl.value) return;
  downloadingImage.value = true;
  try {
    const canvas = await captureCanvas();
    const link = document.createElement('a');
    link.href = canvas.toDataURL('image/png');
    link.download = `struk-${receipt.value?.order_number ?? 'transaksi'}.png`;
    document.body.appendChild(link);
    link.click();
    link.remove();
  } catch {
    toast.error('Gagal mengunduh struk sebagai gambar.');
  } finally {
    downloadingImage.value = false;
  }
}

async function downloadAsPdf() {
  if (!receiptEl.value) return;
  downloadingPdf.value = true;
  try {
    const canvas = await captureCanvas();
    const { jsPDF } = await import('jspdf');
    const imgData = canvas.toDataURL('image/png');
    // Single-page PDF sized to the raster — a receipt is a strip, not an
    // A4 page, so we fit the page to the content instead of the reverse.
    const widthPt = (canvas.width * 72) / 96;
    const heightPt = (canvas.height * 72) / 96;
    const pdf = new jsPDF({ orientation: heightPt >= widthPt ? 'portrait' : 'landscape', unit: 'pt', format: [widthPt, heightPt] });
    pdf.addImage(imgData, 'PNG', 0, 0, widthPt, heightPt);
    pdf.save(`struk-${receipt.value?.order_number ?? 'transaksi'}.pdf`);
  } catch {
    toast.error('Gagal mengunduh struk sebagai PDF.');
  } finally {
    downloadingPdf.value = false;
  }
}


watch(
  () => [props.open, props.orderId],
  async ([open, orderId]) => {
    if (!open || !orderId) {
      receipt.value = null;
      return;
    }
    loading.value = true;
    try {
      receipt.value = await getReceipt(orderId);
    } catch (err) {
      toast.error(err.message || 'Gagal memuat struk.');
    } finally {
      loading.value = false;
    }
  },
  { immediate: true }
);
</script>

<template>
  <BaseModal :open="open" max-width-class="max-w-[430px]" @close="emit('close')">
    <div class="flex items-center gap-3 bg-brand px-6 py-5 text-white">
      <i class="ph-duotone ph-check-circle text-[30px]" aria-hidden="true"></i>
      <div class="flex flex-col gap-0.5">
        <span class="text-[17px] font-bold tracking-tight">Transaksi tersimpan</span>
        <span class="text-[12.5px] text-mint-100">Silakan foto struk ini</span>
      </div>
    </div>

    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">Memuat struk…</div>
    <div v-else-if="receipt" ref="receiptEl" class="flex flex-col gap-[18px] bg-white px-6 py-6">
      <div class="flex flex-col items-center gap-1 text-center">
        <img
          v-if="receipt.store_logo_url"
          :src="receipt.store_logo_url"
          alt="Logo toko"
          class="mb-1 h-12 w-12 rounded-md object-contain"
        />
        <span class="text-[17px] font-extrabold tracking-tight">{{ receipt.store_name }}</span>
        <span v-if="receipt.store_address" class="max-w-[300px] text-[11.5px] leading-snug text-muted-3">{{ receipt.store_address }}</span>
        <span class="text-[12.5px] text-muted-2">{{ receipt.event_name }}</span>
        <span class="mt-1.5 font-mono text-[13px] font-semibold">{{ receipt.order_number }}</span>
        <span class="text-[12px] text-muted-2">{{ formatDateTime(receipt.created_at) }} · Kasir {{ receipt.cashier_name }}</span>
      </div>

      <div class="flex flex-col gap-3 border-y border-dashed border-line-2 py-4">
        <div v-for="(item, idx) in receipt.items" :key="idx" class="flex items-start gap-2.5">
          <span class="min-w-[26px] text-[15px] font-bold text-brand-active">{{ item.qty }}×</span>
          <div class="flex flex-1 flex-col gap-0.5">
            <span class="text-[14.5px] font-semibold leading-snug">{{ item.name }}</span>
            <span class="text-[12px] text-muted-3">{{ formatIDR(item.price) }}</span>
          </div>
          <span class="text-[14.5px] font-bold">{{ formatIDR(item.line_total) }}</span>
        </div>
      </div>

      <div class="flex flex-col gap-2">
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">Subtotal</span><span class="font-semibold">{{ formatIDR(receipt.subtotal) }}</span></div>
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">Diskon</span><span class="font-semibold text-danger-text">{{ formatIDR(receipt.discount_amount) }}</span></div>
        <div class="flex items-baseline justify-between border-t border-line-3 pt-2.5">
          <span class="text-[16px] font-bold">Total</span>
          <span class="text-[30px] font-extrabold tracking-tight">{{ formatIDR(receipt.total_amount) }}</span>
        </div>
        <div v-for="(p, idx) in receipt.payment_summary" :key="idx" class="flex justify-between pt-1.5 text-[13.5px]">
          <span class="text-muted">{{ METHOD_LABELS[p.method] ?? p.method }}</span><span class="font-semibold">{{ formatIDR(p.amount) }}</span>
        </div>
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">Kembalian</span><span class="font-semibold">{{ formatIDR(receipt.change_amount) }}</span></div>
      </div>

      <div
        v-if="receipt.store_contact_person || receipt.store_contact_phone || receipt.store_contact_email"
        class="flex flex-col items-center gap-0.5 border-t border-dashed border-line-2 pt-3 text-center text-[11px] text-muted-3"
      >
        <span v-if="receipt.store_contact_person">{{ receipt.store_contact_person }}</span>
        <span v-if="receipt.store_contact_phone || receipt.store_contact_email">
          {{ [receipt.store_contact_phone, receipt.store_contact_email].filter(Boolean).join(' · ') }}
        </span>
      </div>
    </div>

    <template #footer>
      <div class="flex flex-col gap-2">
        <div class="flex gap-2">
          <BaseButton variant="secondary" class="flex-1" :loading="downloadingImage" :disabled="!receipt" @click="downloadAsImage">
            <i class="ph-duotone ph-image text-[16px]" aria-hidden="true"></i>
            Unduh gambar
          </BaseButton>
          <BaseButton variant="secondary" class="flex-1" :loading="downloadingPdf" :disabled="!receipt" @click="downloadAsPdf">
            <i class="ph-duotone ph-file-pdf text-[16px]" aria-hidden="true"></i>
            Unduh PDF
          </BaseButton>
        </div>
        <BaseButton variant="primary" class="w-full" @click="emit('close')">{{ closeLabel }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
