<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import StatusPill from '../ui/StatusPill.vue';
import { resolveAppName } from '../../utils/appName';
import { formatIDR, parseMoney } from '../../utils/money';
import { formatDate, formatDateTime, formatDateRange } from '../../utils/date';

/**
 * 029-fix-bulk-invoice-logo — dokumen invoice pre-order, SATU-SATUNYA tempat
 * tata letaknya didefinisikan. Dulu markup ini hidup di PreorderInvoiceModal.vue
 * sedangkan unduh massal memakai pembuat HTML terpisah (utils/invoiceDocument.js)
 * dengan tampilan yang berbeda; sekarang modal DAN unduh massal merender
 * komponen ini, sehingga PDF massal selalu sama dengan invoice di layar.
 * Komponen ini murni presentasi: data datang dari payload invoice
 * (GET /preorders/{id}/invoice atau /preorders/invoices/bulk — bentuknya sama),
 * klik QR hanya dipancarkan sebagai event `enlarge` (lightbox milik pemanggil).
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
  invoice: { type: Object, required: true },
});
const emit = defineEmits(['enlarge']);

const { t } = useI18n();

// Teks "Powered by …": nama aplikasi datang dari payload (bisa diubah di Pengaturan).
const appName = computed(() => resolveAppName(props.invoice.app_name));

// event_available_on_date is null both when no restriction was chosen AND
// when it's 'both' days — the raw event_available_on tells them apart, so
// 'both' renders the event's own date range instead of a single date.
const availableOnDisplay = computed(() => {
  if (props.invoice.event_available_on === 'both') return formatDateRange(props.invoice.event_start_date, props.invoice.event_end_date);
  return props.invoice.event_available_on_date ? formatDate(props.invoice.event_available_on_date) : null;
});

const statusLabel = computed(() => t(STATUS_LABEL_KEY[props.invoice.status] ?? props.invoice.status));
const statusVariant = computed(() => STATUS_VARIANT[props.invoice.status] ?? 'neutral');

// 024-invoice-layout-shipping-slip (US3, research.md Decision 4) — kanal
// pembayaran dipecah berdasarkan field `type` yang sudah ada, bukan
// kategorisasi baru.
const qrChannels = computed(() => props.invoice.payment_channels?.filter((c) => c.type === 'qr_ewallet') ?? []);
const bankChannels = computed(() => props.invoice.payment_channels?.filter((c) => c.type === 'bank_transfer') ?? []);
</script>

<template>
  <div class="flex flex-col gap-[18px] bg-white p-4">
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
        class="mb-1 h-24 w-auto max-w-[260px] rounded-md object-contain"
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
         Decision 2) — judul dokumen "Pre-Order Invoice" diletakkan
         setelah garis putus, sebelum grid dua kolom (US2). -->
    <span class="block text-center text-[14px] font-bold">{{ t('preorders.invoice_doc_title') }}</span>
    <div class="grid grid-cols-2 gap-4">
      <!-- 023-event-availability-invoice-redesign (US2, FR-004/FR-005/
           FR-006, research.md Decision 3) — MENGGANTIKAN blok footer
           kecil "Location:/Dates:" yang dulu ada di sini, bukan
           menambah di samping. Hilang seluruhnya kalau ketiganya
           kosong. -->
      <div
        v-if="invoice.event_name || availableOnDisplay || invoice.event_location"
        class="flex flex-col items-center gap-1.5 rounded-lg border border-line-3 px-4 py-3 text-center"
      >
        <span v-if="invoice.event_name" class="text-[13.5px] font-extrabold">{{ invoice.event_name }}</span>
        <div v-if="invoice.event_location" class="flex flex-col gap-0.5 text-[13px]">
          <span class="font-semibold text-muted-3">{{ t('events_sessions.location') }}</span>
          <span class="font-bold">{{ invoice.event_location }}</span>
        </div>
        <div v-if="availableOnDisplay" class="flex flex-col gap-0.5 text-[13px]">
          <span class="font-semibold text-muted-3">{{ t('events_sessions.available_on_label') }}</span>
          <span class="font-bold">{{ availableOnDisplay }}</span>
        </div>
      </div>
      <div v-else></div>

      <div class="flex flex-col items-center gap-1.5 text-center">
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

    <!-- 024-invoice-layout-shipping-slip (US3, FR-006/FR-007,
         research.md Decision 4) — kanal pembayaran dipecah dua kolom
         berdasarkan field `type` yang sudah ada: kolom QR (lebih besar
         lagi, 96px -> 128px) dan kolom transfer bank. Masing-masing
         kolom hilang sendiri-sendiri kalau kosong, tanpa penyamaran
         nomor rekening (research.md Decision 2, tetap dari 022). -->
    <div v-if="invoice.payment_channels?.length" class="flex flex-col gap-2.5 border-t border-dashed border-line-2 pt-3.5">
      <span class="text-center text-[11.5px] font-bold uppercase tracking-wide text-muted-3">{{ t('preorders.payment_terms_title') }}</span>
      <div class="grid grid-cols-2 gap-3">
        <div v-if="qrChannels.length" class="flex flex-col gap-2">
          <span class="text-center text-[10.5px] font-bold uppercase tracking-wide text-muted-3">{{ t('preorders.qr_payment_title') }}</span>
          <div v-for="channel in qrChannels" :key="channel.id" class="flex flex-col items-center gap-2 rounded-lg border border-line-3 bg-surface-subtle px-3 py-2.5">
            <button
              v-if="channel.qr_image_url"
              type="button"
              class="shrink-0"
              :aria-label="t('pos.enlarge_qr')"
              @click="emit('enlarge', { src: channel.qr_image_url, alt: channel.provider })"
            >
              <img :src="channel.qr_image_url" :alt="channel.provider" class="h-32 max-w-full cursor-zoom-in rounded-md border border-line-2 object-contain" />
            </button>
            <div class="flex min-w-0 flex-col items-center gap-0.5 text-center">
              <span class="text-[12.5px] font-bold">{{ channel.provider }}</span>
              <span v-if="channel.account_name" class="text-[11px] text-muted-3">{{ t('pos.account_holder', { name: channel.account_name }) }}</span>
            </div>
          </div>
        </div>
        <div v-if="bankChannels.length" class="flex flex-col gap-2">
          <span class="text-center text-[10.5px] font-bold uppercase tracking-wide text-muted-3">{{ t('preorders.bank_payment_title') }}</span>
          <div v-for="channel in bankChannels" :key="channel.id" class="flex flex-col gap-0.5 rounded-lg border border-line-3 bg-surface-subtle px-3 py-2.5">
            <span class="text-[12.5px] font-bold">{{ channel.provider }}</span>
            <span v-if="channel.account_number" class="break-all font-mono text-[13px] font-semibold">{{ channel.account_number }}</span>
            <span v-if="channel.account_name" class="text-[11px] text-muted-3">{{ t('pos.account_holder', { name: channel.account_name }) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- 022-preorder-invoice-crud-overhaul (US3, FR-010) — memakai
         receipt_footer_text yang sudah ada (Assumptions spec.md), bukan
         field baru. 024-invoice-layout-shipping-slip (US5) — selalu
         tampilkan footer, dengan fallback ke teks default bila belum
         dikonfigurasi. -->
    <p class="border-t border-dashed border-line-2 pt-3 text-center text-[11.5px] leading-relaxed text-muted-3">
      {{ invoice.footer_text || t('preorders.invoice_footer_default') }}
    </p>
    <p class="text-center text-[10.5px] text-muted-3">{{ t('common.powered_by', { name: appName }) }}</p>
  </div>
</template>
