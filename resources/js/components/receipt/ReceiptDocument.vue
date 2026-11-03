<script setup>
import { computed } from 'vue';
import { formatIDR } from '../../utils/money';
import { resolveAppName } from '../../utils/appName';
import { formatDate, formatDateTime, formatDateRange } from '../../utils/date';

/**
 * 054 (US9) — tata letak struk penjualan, SATU definisi yang dipakai ReceiptModal (struk di layar) DAN unduhan
 * massal di halaman Sales (dipasang di luar layar lalu dirasterisasi), seperti PreorderInvoiceDocument untuk
 * invoice pre-order — jangan membuat tata letak kedua.
 *
 * Label SENGAJA Bahasa Indonesia langsung, bukan t(): struk dibaca PEMBELI, bukan kasir (002-language-toggle
 * FR-009) — jangan "memperbaikinya" menjadi t().
 */
const props = defineProps({
  receipt: { type: Object, required: true },
});

const appName = computed(() => resolveAppName(props.receipt?.app_name));
const METHOD_LABELS = { cash: 'Tunai', bank_transfer: 'Transfer bank', qr_ewallet: 'QRIS / e-wallet' };

// event_available_on_date is null both when no restriction was chosen AND
// when it's 'both' days — the raw event_available_on tells them apart, so
// 'both' renders the event's own date range instead of a single date.
function availableOnDisplay(r) {
  if (r.event_available_on === 'both') return formatDateRange(r.event_start_date, r.event_end_date);
  return r.event_available_on_date ? formatDate(r.event_available_on_date) : null;
}
</script>

<template>
  <div class="flex flex-col gap-[18px] bg-white px-6 py-6" data-testid="receipt-document">
    <!-- 054 — penjualan batal ditandai jelas (unduh massal bisa memuatnya bila daftar menampilkannya). -->
    <p v-if="receipt.status === 'voided'" class="rounded-md border border-danger-text px-3 py-1.5 text-center text-[13px] font-extrabold uppercase tracking-wider text-danger-text" data-testid="receipt-voided">Dibatalkan</p>
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
          <span class="text-muted">{{ METHOD_LABELS[p.method] ?? p.method }}<span v-if="p.reference" class="text-[11.5px]" data-testid="receipt-payment-ref"> · Ref {{ p.reference }}</span></span><span class="font-semibold">{{ formatIDR(p.amount) }}</span>
        </div>
        <div class="flex justify-between text-[13.5px]"><span class="text-muted">Kembalian</span><span class="font-semibold">{{ formatIDR(receipt.change_amount) }}</span></div>
        <!-- 028-partial-split-payment — penjualan yang belum lunas: jumlah dibayar, sisa tagihan,
             dan status. Struk lunas tak berubah. Struk selalu berbahasa Indonesia. -->
        <template v-if="receipt.payment_status === 'partially_paid'">
          <div class="flex justify-between border-t border-dashed border-line-3 pt-2 text-[13.5px]" data-testid="receipt-paid"><span class="text-muted">Sudah dibayar</span><span class="font-semibold">{{ formatIDR(receipt.paid_amount) }}</span></div>
          <div class="flex justify-between text-[13.5px]" data-testid="receipt-balance"><span class="font-bold">Sisa tagihan</span><span class="font-extrabold text-warn-text">{{ formatIDR(receipt.balance_amount) }}</span></div>
          <p class="text-center text-[12px] font-bold uppercase tracking-wider text-warn-text" data-testid="receipt-status">Dibayar sebagian</p>
        </template>
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
</template>
