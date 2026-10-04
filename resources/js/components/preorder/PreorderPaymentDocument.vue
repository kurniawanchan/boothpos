<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import StatusPill from '../ui/StatusPill.vue';
import { resolveAppName } from '../../utils/appName';
import { formatIDR, parseMoney } from '../../utils/money';
import { formatDate, formatDateTime, formatDateRange } from '../../utils/date';

/**
 * 029-fix-bulk-invoice-logo — dokumen payment invoice pre-order, SATU-SATUNYA
 * tempat tata letaknya didefinisikan (dulu di PreorderPaymentReceiptModal.vue,
 * sementara unduh massal memakai pembuat HTML terpisah dengan tampilan lain).
 * Modal DAN unduh massal merender komponen ini. Murni presentasi: data dari
 * payload invoice (getPreorderInvoice / bulk), klik QR dipancarkan sebagai
 * event `enlarge`.
 *
 * Menampilkan SATU payment event: yang ditunjuk `paymentId`; bila tidak
 * diberikan (unduh massal) atau tidak ditemukan, jatuh ke pembayaran paling
 * akhir supaya tidak crash.
 */
const props = defineProps({
  invoice: { type: Object, required: true },
  paymentId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['enlarge']);

const { t } = useI18n();

const appName = computed(() => resolveAppName(props.invoice.app_name));

const METHOD_LABELS = { cash: 'Tunai', bank_transfer: 'Transfer bank', qr_ewallet: 'QRIS / e-wallet' };

// event_available_on_date is null both when no restriction was chosen AND
// when it's 'both' days — the raw event_available_on tells them apart, so
// 'both' renders the event's own date range instead of a single date.
const availableOnDisplay = computed(() => {
  if (props.invoice.event_available_on === 'both') return formatDateRange(props.invoice.event_start_date, props.invoice.event_end_date);
  return props.invoice.event_available_on_date ? formatDate(props.invoice.event_available_on_date) : null;
});

const STATUS_LABEL_KEY = {
  ordered: 'preorders.step_ordered',
  dp_paid: 'preorders.step_dp_paid',
  arrived: 'preorders.step_arrived',
  settled: 'preorders.step_settled',
  handed_over: 'preorders.step_handed_over',
  cancelled: 'events_sessions.status_cancelled',
};
const STATUS_VARIANT = { ordered: 'neutral', dp_paid: 'warn', arrived: 'mint', settled: 'mint', handed_over: 'dark', cancelled: 'danger' };

const statusLabel = computed(() => t(STATUS_LABEL_KEY[props.invoice.status] ?? props.invoice.status));
const statusVariant = computed(() => STATUS_VARIANT[props.invoice.status] ?? 'neutral');

const payment = computed(() => {
  const list = props.invoice.payments ?? [];
  if (!list.length) return null;
  if (props.paymentId != null) {
    const found = list.find((p) => String(p.id) === String(props.paymentId));
    if (found) return found;
  }
  return list[list.length - 1];
});

const paymentEventLabel = computed(() => {
  if (!payment.value) return '';
  const purposeLabel = payment.value.purpose === 'settlement'
    ? t('preorders.payment_event_settlement')
    : (payment.value.purpose === 'full' ? t('preorders.full_payment') : t('preorders.payment_event_down_payment'));
  return `${purposeLabel} — ${formatDateTime(payment.value.paid_at)}`;
});

const qrChannels = computed(() => props.invoice.payment_channels?.filter((c) => c.type === 'qr_ewallet') ?? []);
const bankChannels = computed(() => props.invoice.payment_channels?.filter((c) => c.type === 'bank_transfer') ?? []);
</script>

<template>
  <div class="flex flex-col gap-[18px] bg-white px-6 py-6">

    <!-- 022-preorder-invoice-crud-overhaul (US4, FR-012) — identitas toko
         SAMA PERSIS dengan invoice utama (research.md Decision 1). -->
    <div v-if="invoice.store_identity" class="flex flex-col items-center gap-1 border-b border-dashed border-line-2 pb-4 text-center">
      <img
        v-if="invoice.store_identity.logo_url"
        :src="invoice.store_identity.logo_url"
        :alt="t('preorders.store_logo_alt')"
        class="mb-1 h-24 w-auto max-w-[260px] rounded-md object-contain"
      />
      <span class="text-[17px] font-extrabold tracking-tight">{{ invoice.store_identity.name }}</span>
      <span v-if="invoice.store_identity.address" class="max-w-[300px] text-[11.5px] leading-snug text-muted-3">{{ invoice.store_identity.address }}</span>
    </div>

    <!-- 024-invoice-layout-shipping-slip (US1, dicerminkan dari
         PreorderInvoiceModal.vue, research.md Decision 2) — judul
         "Pre-Order Invoice" setelah garis putus, sebelum grid. -->
    <span class="block text-center text-[14px] font-bold">{{ t('preorders.invoice_doc_title') }}</span>
    <div class="grid grid-cols-2 gap-4">
      <!-- 023-event-availability-invoice-redesign (US2) — bg-brand diganti
           border rounded, teks putih diganti default. -->
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
        <span v-if="invoice.created_at" class="text-[11px] text-muted-3">{{ t('preorders.created_at_label') }}: {{ formatDateTime(invoice.created_at) }}</span>
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

    <div v-if="payment" class="flex flex-col items-center gap-0.5 border-y border-dashed border-line-2 py-3 text-center">
      <span class="text-[11.5px] font-semibold uppercase tracking-wide text-muted-3">{{ t('preorders.payment_event_label') }}</span>
      <span class="text-[14px] font-bold">{{ paymentEventLabel }}</span>
    </div>

    <!-- 023-event-availability-invoice-redesign (US3) — tabel yang sama
         dengan PreorderInvoiceModal.vue. -->
    <div class="flex flex-col gap-2 border-b border-dashed border-line-2 pb-4">
      <span class="text-[12.5px] font-bold text-muted-3">{{ t('preorders.ordered_items') }}</span>
      <table class="w-full border-collapse text-[13px]">
        <thead>
          <tr class="border-b border-line-2 text-left text-[11px] font-bold uppercase tracking-wide text-muted-3">
            <th class="py-2 pr-2">{{ t('preorders.col_product') }}</th>
            <th class="py-2 pr-2 text-right">{{ t('preorders.col_qty') }}</th>
            <th class="py-2 text-right">{{ t('preorders.col_line_total') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in invoice.items" :key="item.id" class="border-b border-dashed border-line-2 last:border-b-0">
            <td class="py-2.5 pr-2 font-semibold leading-snug">{{ item.name_snapshot }}</td>
            <td class="py-2.5 pr-2 text-right text-brand-active font-bold">{{ item.qty }}</td>
            <td class="py-2.5 text-right font-bold">{{ formatIDR(item.line_total) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- FR-012 — distinguishes what THIS payment covered from the
         order's overall total/remaining balance: the highlighted line
         is always "this payment", never the order's lifetime figures. -->
    <div class="flex flex-col gap-2">
      <div class="flex justify-between text-[13.5px]"><span class="text-muted">{{ t('preorders.total_amount') }}</span><span class="font-semibold">{{ formatIDR(invoice.total_amount) }}</span></div>
      <div v-if="parseMoney(invoice.shipping_cost) > 0" class="flex justify-between text-[13.5px]">
        <span class="text-muted">{{ t('preorders.shipping_cost_label') }}</span>
        <span class="font-semibold">{{ formatIDR(invoice.shipping_cost) }}</span>
      </div>
      <div v-if="payment" class="flex items-baseline justify-between rounded-lg bg-mint-50 px-3 py-2.5">
        <span class="text-[13px] font-bold text-brand-active">{{ t('preorders.paid_this_time') }}</span>
        <span class="text-[26px] font-extrabold tracking-tight text-brand-active">{{ formatIDR(payment.amount) }}</span>
      </div>
      <div v-if="payment" class="flex justify-between pt-1.5 text-[13.5px]">
        <span class="text-muted">{{ METHOD_LABELS[payment.method] ?? payment.method }}</span>
        <span class="font-semibold">{{ formatIDR(payment.amount) }}</span>
      </div>
      <div class="flex justify-between border-t border-line-3 pt-2.5 text-[13.5px]"><span class="text-muted">{{ t('preorders.already_paid') }}</span><span class="font-semibold">{{ formatIDR(invoice.paid_amount) }}</span></div>
      <div class="flex justify-between text-[13.5px]"><span class="text-muted">{{ t('preorders.outstanding') }}</span><span class="font-semibold">{{ formatIDR(invoice.outstanding) }}</span></div>
    </div>

    <!-- 024-invoice-layout-shipping-slip (US3, dicerminkan dari
         PreorderInvoiceModal.vue) — dua kolom QR/transfer bank. -->
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

    <!-- 024-invoice-layout-shipping-slip (US5) — footer selalu tampil,
         fallback ke teks default bila belum dikonfigurasi. -->
    <p class="border-t border-dashed border-line-2 pt-3 text-center text-[11.5px] leading-relaxed text-muted-3">
      {{ t('preorders.payment_receipt_footer_note') }}
    </p>
    <p class="border-t border-dashed border-line-2 pt-3 text-center text-[11.5px] leading-relaxed text-muted-3">
      {{ invoice.footer_text || t('preorders.invoice_footer_default') }}
    </p>
    <p class="text-center text-[10.5px] text-muted-3">{{ t('common.powered_by', { name: appName }) }}</p>
  
  </div>
</template>
