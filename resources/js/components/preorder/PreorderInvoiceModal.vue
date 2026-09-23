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
        <!-- 022-preorder-invoice-crud-overhaul (US3, FR-008, research.md
             Decision 1) — identitas toko disalin dari assembly yang sama
             persis dengan ReceiptModal.vue (struk POS); hilang seluruhnya
             per field yang belum dikonfigurasi, tidak pernah placeholder
             kosong (Edge Cases spec.md). -->
        <div v-if="invoice.store_identity" class="flex flex-col items-center gap-1 border-b border-dashed border-line-2 pb-4 text-center">
          <img
            v-if="invoice.store_identity.logo_url"
            :src="invoice.store_identity.logo_url"
            :alt="t('preorders.store_logo_alt')"
            class="mb-1 h-12 w-12 rounded-md object-contain"
          />
          <span class="text-[17px] font-extrabold tracking-tight">{{ invoice.store_identity.name }}</span>
          <span v-if="invoice.store_identity.address" class="max-w-[300px] text-[11.5px] leading-snug text-muted-3">{{ invoice.store_identity.address }}</span>
          <span
            v-if="invoice.store_identity.contact_person || invoice.store_identity.contact_phone || invoice.store_identity.contact_email"
            class="text-[11px] text-muted-3"
          >
            {{ [invoice.store_identity.contact_person, invoice.store_identity.contact_phone, invoice.store_identity.contact_email].filter(Boolean).join(' · ') }}
          </span>
        </div>

        <!-- 024-invoice-layout-shipping-slip (US1, FR-001, research.md
             Decision 2) — header dua kolom: kiri = info event (nama,
             lokasi, tersedia pada — semuanya terpusat, FR-002), kanan =
             identitas pesanan (nomor/status/tanggal dibuat) + blok
             "Kepada:" penerima. Identitas toko TETAP full-width di atas
             (bukan bagian dari salah satu kolom). -->
        <div class="grid grid-cols-2 gap-4">
          <!-- 023-event-availability-invoice-redesign (US2, FR-004/FR-005/
               FR-006, research.md Decision 3) — MENGGANTIKAN blok footer
               kecil "Location:/Dates:" yang dulu ada di sini, bukan
               menambah di samping. Hilang seluruhnya kalau ketiganya
               kosong. -->
          <div
            v-if="invoice.event_name || invoice.event_available_on_date || invoice.event_location"
            class="flex flex-col items-center gap-1.5 rounded-lg bg-brand px-4 py-3 text-center text-white"
          >
            <span v-if="invoice.event_name" class="text-[13.5px] font-extrabold">{{ invoice.event_name }}</span>
            <div v-if="invoice.event_location" class="flex flex-col gap-0.5 text-[13px]">
              <span class="font-semibold text-mint-100">{{ t('events_sessions.location') }}</span>
              <span class="font-bold">{{ invoice.event_location }}</span>
            </div>
            <div v-if="invoice.event_available_on_date" class="flex flex-col gap-0.5 text-[13px]">
              <span class="font-semibold text-mint-100">{{ t('events_sessions.available_on_label') }}</span>
              <span class="font-bold">{{ formatDate(invoice.event_available_on_date) }}</span>
            </div>
          </div>
          <div v-else></div>

          <div class="flex flex-col items-center gap-1.5 text-center">
            <span
              class="rounded-full bg-warn-bg px-3 py-1 text-[11.5px] font-extrabold uppercase tracking-wide text-warn-text"
            >{{ t('preorders.preorder_marking_label') }}</span>
            <span class="mt-1 font-mono text-[15px] font-bold">{{ invoice.preorder_number }}</span>
            <StatusPill :variant="statusVariant">{{ statusLabel }}</StatusPill>
            <!-- 024-invoice-layout-shipping-slip (US2, FR-004) -->
            <span v-if="invoice.created_at" class="text-[11px] text-muted-3">{{ t('preorders.created_at_label') }}: {{ formatDateTime(invoice.created_at) }}</span>
            <!-- 024-invoice-layout-shipping-slip (US1, FR-003) — blok
                 "Kepada:" memakai field CustomerResource yang sudah ada di
                 invoice.customer, masing-masing baris hilang sendiri-
                 sendiri kalau kosong (research.md Decision 3). -->
            <div v-if="invoice.customer" class="mt-1 flex w-full flex-col gap-0.5 rounded-lg bg-surface-subtle px-3 py-2 text-left text-[11.5px]">
              <span class="text-center text-[10.5px] font-bold uppercase tracking-wide text-muted-3">{{ t('preorders.to_label') }}</span>
              <span v-if="invoice.customer.name" class="font-bold">{{ invoice.customer.name }}</span>
              <span v-if="invoice.customer.email" class="text-muted-2">{{ invoice.customer.email }}</span>
              <span v-if="invoice.customer.phone" class="text-muted-2">{{ invoice.customer.phone }}</span>
              <span v-if="invoice.customer.social_handle" class="text-muted-2">{{ invoice.customer.social_handle }}</span>
              <span v-if="invoice.customer.address" class="text-muted-2">{{ invoice.customer.address }}</span>
            </div>
          </div>
        </div>

        <!-- 023-event-availability-invoice-redesign (US3, FR-007/FR-008,
             research.md Decision 5) — tabel sungguhan menggantikan daftar
             flex bertumpuk, di dalam struktur dokumen header/tabel/footer
             yang lebih jelas, dilebarkan supaya empat kolom bisa dibaca
             nyaman tanpa membungkus. -->
        <table class="w-full border-collapse text-[13px]">
          <thead>
            <tr class="border-b border-line-2 text-left text-[11px] font-bold uppercase tracking-wide text-muted-3">
              <th class="py-2 pr-2">{{ t('preorders.col_product') }}</th>
              <th class="py-2 pr-2 text-right">{{ t('preorders.col_qty') }}</th>
              <th class="py-2 pr-2 text-right">{{ t('preorders.col_unit_price') }}</th>
              <th class="py-2 text-right">{{ t('preorders.col_line_total') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in invoice.items" :key="item.id" class="border-b border-dashed border-line-2 last:border-b-0">
              <td class="py-2.5 pr-2">
                <span class="font-semibold leading-snug">{{ item.name_snapshot }}</span>
                <span v-if="item.artist_name" class="block text-[11px] text-muted-3">{{ item.artist_name }}</span>
              </td>
              <td class="py-2.5 pr-2 text-right text-brand-active font-bold">{{ item.qty }}</td>
              <td class="py-2.5 pr-2 text-right text-muted-3">{{ formatIDR(item.sell_price) }}</td>
              <td class="py-2.5 text-right font-bold">{{ formatIDR(item.line_total) }}</td>
            </tr>
          </tbody>
        </table>

        <!-- 021-preorder-form-updates (US3, FR-009) — hari jemput
             ditampilkan sebagai tanggal ASLI (bukan label generik),
             hanya kalau terisi (preorder Self Pickup dengan event
             ditautkan). Kurir tidak wajib tampil di invoice (baris
             pengiriman sungguhan ada di Shipment terpisah), tapi
             ditampilkan di sini juga sebagai info awal yang relevan. -->
        <div v-if="invoice.pickup_day || invoice.courier_name" class="flex flex-col gap-1 rounded-lg bg-mint-50 px-3 py-2 text-[12.5px]">
          <div v-if="invoice.pickup_day" class="flex justify-between"><span class="text-muted">{{ t('preorders.pickup_day_label') }}</span><span class="font-semibold">{{ formatDate(invoice.pickup_day) }}</span></div>
          <div v-if="invoice.courier_name" class="flex justify-between"><span class="text-muted">{{ t('preorders.courier') }}</span><span class="font-semibold">{{ invoice.courier_name }}</span></div>
        </div>

        <div class="flex flex-col gap-2">
          <!-- 021-preorder-form-updates (US2, FR-005) — hanya tampil kalau
               ada diskon; preorder lama (diskon 0) tampil persis seperti
               sebelumnya, tanpa baris ini (Edge Cases). -->
          <div v-if="parseMoney(invoice.discount) > 0" class="flex justify-between text-[13.5px]">
            <span class="text-muted">{{ t('preorders.discount_label') }}</span>
            <span class="font-semibold text-danger-text">-{{ formatIDR(invoice.discount) }}</span>
          </div>
          <!-- 023-event-availability-invoice-redesign (US3, FR-009,
               research.md Decision 6) — datanya sudah selalu dikembalikan
               oleh invoicePayload() sejak fitur 022, hanya belum pernah
               dirender di sini; hilang seluruhnya kalau nol, bukan "Rp 0". -->
          <div v-if="parseMoney(invoice.shipping_cost) > 0" class="flex justify-between text-[13.5px]">
            <span class="text-muted">{{ t('preorders.shipping_cost_label') }}</span>
            <span class="font-semibold">{{ formatIDR(invoice.shipping_cost) }}</span>
          </div>
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
