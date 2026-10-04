<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import StatusPill from '../ui/StatusPill.vue';
import { formatIDR, parseMoney } from '../../utils/money';

/**
 * 028-partial-split-payment — ringkasan pembayaran satu transaksi (pre-order
 * atau penjualan POS): Total tagihan, Total terbayar, Sisa tagihan, status.
 * Semua angka datang dari `payment_summary` yang dihitung SERVER dari entri
 * pembayaran; komponen ini hanya menampilkan.
 */
const props = defineProps({
  // { grand_total, total_paid, remaining, status: unpaid|partially_paid|fully_paid, payment_count }
  summary: { type: Object, required: true },
});

const { t } = useI18n();

const STATUS_VARIANT = { unpaid: 'neutral', partially_paid: 'warn', fully_paid: 'mint' };

const paid = computed(() => parseMoney(props.summary.total_paid));
const statusLabel = computed(() => {
  const label = t(`payment_ledger.status_${props.summary.status}`);
  // Beda "dibayar sebagian" (1 pembayaran) dan "beberapa pembayaran" langsung
  // terlihat: setelah ≥ 2 pembayaran, jumlahnya ikut disebut.
  return props.summary.status === 'partially_paid' && props.summary.payment_count >= 2
    ? `${label} · ${t('payment_ledger.payments_count', { count: props.summary.payment_count })}`
    : label;
});
const fullyPaid = computed(() => props.summary.status === 'fully_paid');
</script>

<template>
  <div
    class="flex flex-col gap-2.5 rounded-lg border p-4"
    :class="fullyPaid ? 'border-mint-border bg-mint-50' : 'border-line-2 bg-surface-subtle'"
    data-testid="payment-summary"
  >
    <div class="flex items-center justify-between gap-3 text-[12.5px]">
      <span class="text-muted">{{ t('payment_ledger.grand_total') }}</span>
      <span class="font-semibold" data-testid="summary-grand-total">{{ formatIDR(summary.grand_total) }}</span>
    </div>
    <div class="flex items-center justify-between gap-3 text-[12.5px]">
      <span class="text-muted">{{ t('payment_ledger.total_paid') }}</span>
      <span class="font-semibold" data-testid="summary-total-paid">{{ paid > 0 ? '-' : '' }}{{ formatIDR(summary.total_paid) }}</span>
    </div>
    <div class="flex items-baseline justify-between gap-3 border-t border-dashed border-line-2 pt-2.5">
      <span class="text-[13px] font-bold">{{ t('payment_ledger.remaining') }}</span>
      <span class="text-[20px] font-extrabold tracking-tight" data-testid="summary-remaining">{{ formatIDR(summary.remaining) }}</span>
    </div>
    <div class="flex flex-wrap items-center gap-2 pt-0.5">
      <StatusPill :variant="STATUS_VARIANT[summary.status] ?? 'neutral'" data-testid="summary-status">{{ statusLabel }}</StatusPill>
    </div>
    <!-- Lunas: penanda jelas, dan pemanggil menyembunyikan tombol Tambah pembayaran. -->
    <p v-if="fullyPaid" class="flex items-center gap-1.5 text-[12.5px] font-bold text-brand-active" data-testid="summary-fully-paid">
      <i class="ph-duotone ph-check-circle text-[16px]" aria-hidden="true"></i>
      {{ t('payment_ledger.fully_paid_banner') }}
    </p>
  </div>
</template>
