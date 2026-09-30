<script setup>
import { ref, watch, computed } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import { getReceipt } from '../../api/orders';
import { formatIDR } from '../../utils/money';
import { resolveAppName } from '../../utils/appName';
import { formatDate, formatDateTime, formatDateRange } from '../../utils/date';
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
const appName = computed(() => resolveAppName(receipt.value?.app_name));
const loading = ref(false);
const receiptEl = ref(null);
const downloadingImage = ref(false);
const downloadingPdf = ref(false);

const METHOD_LABELS = { cash: 'Tunai', bank_transfer: 'Transfer bank', qr_ewallet: 'QRIS / e-wallet' };

// event_available_on_date is null both when no restriction was chosen AND
// when it's 'both' days — the raw event_available_on tells them apart, so
// 'both' renders the event's own date range instead of a single date.
function availableOnDisplay(r) {
  if (r.event_available_on === 'both') return formatDateRange(r.event_start_date, r.event_end_date);
  return r.event_available_on_date ? formatDate(r.event_available_on_date) : null;
}

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
    <div v-else-if="receipt" ref="receiptEl" class="flex flex-col gap-[18px] bg-white px-6 py-6">
      <div class="flex flex-col items-center gap-1 text-center">
        <img
          v-if="receipt.store_logo_url"
          :src="receipt.store_logo_url"
          alt="Logo toko"
          class="mb-1 h-24 w-auto max-w-[260px] rounded-md object-contain"
        />
        <span class="text-[17px] font-extrabold tracking-tight">{{ receipt.store_name }}</span>
        <span v-if="receipt.store_address" class="max-w-[300px] text-[11.5px] leading-snug text-muted-3">{{ receipt.store_address }}</span>
        <span class="text-[12.5px] text-muted-2">{{ receipt.event_name }}</span>
        <span class="mt-1.5 font-mono text-[13px] font-semibold">{{ receipt.order_number }}</span>
        <span class="text-[12px] text-muted-2">{{ formatDateTime(receipt.created_at) }} · Kasir {{ receipt.cashier_name }}</span>
      </div>

      <!-- 023-event-availability-invoice-redesign (US2, FR-004/FR-005/
           FR-006, research.md Decision 3) — MENGGANTIKAN blok footer kecil
           "Lokasi:/Tanggal:" yang dulu ada di bawah, sama seperti
           PreorderInvoiceModal.vue. Teks tetap Bahasa Indonesia langsung
           (bukan t()) — struk ini SELALU Indonesia untuk pembeli, terlepas
           preferensi bahasa kasir (lihat komentar di atas file ini). -->
      <div
        v-if="availableOnDisplay(receipt) || receipt.event_location"
        class="flex flex-col gap-1.5 rounded-lg bg-brand px-4 py-3 text-white"
      >
        <div v-if="receipt.event_location" class="flex items-center justify-between gap-3 text-[13px]">
          <span class="font-semibold text-mint-100">Lokasi</span>
          <span class="font-bold">{{ receipt.event_location }}</span>
        </div>
        <div v-if="availableOnDisplay(receipt)" class="flex items-center justify-between gap-3 text-[13px]">
          <span class="font-semibold text-mint-100">Tersedia pada</span>
          <span class="font-bold">{{ availableOnDisplay(receipt) }}</span>
        </div>
      </div>

      <div class="flex flex-col gap-3 border-y border-dashed border-line-2 py-4">
        <div v-for="(item, idx) in receipt.items" :key="idx" class="flex items-start gap-2.5">
          <span class="min-w-[26px] text-[15px] font-bold text-brand-active">{{ item.qty }}×</span>
          <div class="flex flex-1 flex-col gap-0.5">
            <span class="text-[14.5px] font-semibold leading-snug">{{ item.name }}</span>
            <span class="text-[12px] text-muted-3">
              {{ formatIDR(item.price) }}
              <template v-if="item.artist_name"> · {{ item.artist_name }}</template>
            </span>
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

      <!-- Follow-up 2 (FR-024) — pembeli transaksi INI, bukan kontak toko
           (yang sebelumnya di sini terlihat seperti data contoh palsu).
           Kosong sama sekali untuk order walk-in, apa adanya. -->
      <div
        v-if="receipt.customer_name || receipt.customer_phone || receipt.customer_email"
        class="flex flex-col items-center gap-0.5 border-t border-dashed border-line-2 pt-3 text-center text-[11px] text-muted-3"
      >
        <span v-if="receipt.customer_name">{{ receipt.customer_name }}</span>
        <span v-if="receipt.customer_phone || receipt.customer_email">
          {{ [receipt.customer_phone, receipt.customer_email].filter(Boolean).join(' · ') }}
        </span>
      </div>

      <!-- 006-purchase-order-and-ops (US7) — omitted entirely when unset,
           not an empty block, per spec Acceptance Scenario 3. -->
      <p v-if="receipt.receipt_footer_text" class="border-t border-dashed border-line-2 pt-3 text-center text-[11.5px] leading-relaxed text-muted-3">
        {{ receipt.receipt_footer_text }}
      </p>

      <p class="text-center text-[10.5px] text-muted-3">Powered by {{ appName }}</p>

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
