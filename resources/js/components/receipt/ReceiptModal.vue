<script setup>
import { ref, watch, computed } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import ReceiptDocument from './ReceiptDocument.vue';
import { getReceipt } from '../../api/orders';
import { downloadElementAsPdf, downloadElementAsPng } from '../../utils/pdfCapture';
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
const receiptDoc = ref(null);
// Elemen akar dokumen struk — yang dirasterisasi untuk unduhan gambar/PDF.
const receiptEl = computed(() => receiptDoc.value?.$el ?? null);
const downloadingImage = ref(false);
const downloadingPdf = ref(false);


async function downloadAsImage() {
  if (!receiptEl.value) return;
  downloadingImage.value = true;
  try {
    await downloadElementAsPng(receiptEl.value, `struk-${receipt.value?.order_number ?? 'transaksi'}.png`);
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
    await downloadElementAsPdf(receiptEl.value, `struk-${receipt.value?.order_number ?? 'transaksi'}.pdf`);
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
    <ReceiptDocument v-else-if="receipt" ref="receiptDoc" :receipt="receipt" />

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
