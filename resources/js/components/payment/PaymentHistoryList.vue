<script setup>
import { useI18n } from 'vue-i18n';
import StatusPill from '../ui/StatusPill.vue';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { paymentMethodLabel } from '../../utils/paymentMethods';

/**
 * 028-partial-split-payment — riwayat pembayaran satu transaksi (pre-order atau
 * penjualan POS), dalam urutan dicatat. Setiap entri berdiri sendiri: metode,
 * jumlah, waktu, nomor referensi (bila ada), pencatat (bila diketahui), status.
 *
 * Entri TIDAK punya aksi ubah (tak ada jalur ubah di server). Satu-satunya aksi
 * destruktif adalah Hapus untuk owner/admin (`canDelete`), yang diaudit server.
 * Komponen ini hanya menampilkan dan memancarkan event; pemanggil yang membuka
 * bukti, mencetak invoice, dan mengonfirmasi hapus.
 */
defineProps({
  payments: { type: Array, default: () => [] },
  canDelete: { type: Boolean, default: false },
  // Penjualan POS tak punya invoice-per-pembayaran maupun bukti yang dimuat di
  // detailnya; pre-order punya keduanya.
  showProof: { type: Boolean, default: true },
  showPrint: { type: Boolean, default: true },
});
const emit = defineEmits(['view-proof', 'print', 'delete']);

const { t } = useI18n();
</script>

<template>
  <div v-if="payments.length" class="flex flex-col gap-2" data-testid="payment-history">
    <div
      v-for="p in payments"
      :key="p.id"
      class="flex flex-col gap-2 rounded-lg border border-line-2 px-3 py-2.5"
      data-testid="payment-entry"
    >
      <div class="flex items-start justify-between gap-3">
        <div class="flex min-w-0 flex-col gap-0.5">
          <span class="text-[12.5px] font-semibold">{{ paymentMethodLabel(t, p.method) }}<span v-if="p.provider" class="font-normal text-muted-3"> · {{ p.provider }}</span></span>
          <span class="text-[11px] text-muted-3">{{ formatDateTime(p.paid_at) }}</span>
          <span v-if="p.reference" class="break-all text-[11px] text-muted-4" data-testid="payment-reference">
            {{ t('payment_ledger.reference_short') }}: {{ p.reference }}
          </span>
          <span v-if="p.recorded_by_name" class="text-[11px] text-muted-3" data-testid="payment-recorder">
            {{ t('payment_ledger.recorded_by', { name: p.recorded_by_name }) }}
          </span>
        </div>
        <div class="flex flex-col items-end gap-1">
          <span class="whitespace-nowrap text-[13.5px] font-bold">{{ formatIDR(p.amount) }}</span>
          <StatusPill :variant="p.status === 'rejected' ? 'danger' : 'mint'">
            {{ p.status === 'rejected' ? t('payment_ledger.entry_rejected') : t('payment_ledger.entry_paid') }}
          </StatusPill>
        </div>
      </div>
      <div v-if="(showProof && p.proof_id) || showPrint || canDelete" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-dashed border-line-2 pt-2">
        <button
          v-if="showProof && p.proof_id"
          type="button"
          class="whitespace-nowrap text-[12px] font-semibold text-muted-4 hover:text-brand-active"
          @click="emit('view-proof', p)"
        >{{ t('preorders.view_proof') }}</button>
        <button
          v-if="showPrint"
          type="button"
          class="whitespace-nowrap text-[12px] font-semibold text-muted-4 hover:text-brand-active"
          @click="emit('print', p)"
        >{{ t('preorders.print_payment_receipt') }}</button>
        <button
          v-if="canDelete"
          type="button"
          class="ml-auto whitespace-nowrap text-[12px] font-semibold text-danger-text hover:underline"
          data-testid="delete-payment"
          @click="emit('delete', p)"
        >{{ t('common.delete') }}</button>
      </div>
    </div>
  </div>
  <p v-else class="text-[12px] text-muted-3">{{ t('preorders.payment_history_empty') }}</p>
</template>
