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
 * Jumlah/metode/waktu entri TIDAK bisa diubah (tak ada jalur ubah di server).
 * Satu-satunya aksi destruktif adalah Hapus untuk owner/admin (`canDelete`), yang
 * diaudit server.
 *
 * 031-optional-payment-proof — satu-satunya yang bisa berubah belakangan adalah
 * KONFIRMASI pembayaran non-tunai (bukti, referensi, catatan). Entri non-tunai tanpa
 * bukti ditandai "Belum ada bukti"; aksi "Tambah/Ubah konfirmasi" dan "Lihat bukti"
 * mengikuti flag yang dihitung SERVER per entri (`can_edit_confirmation`,
 * `can_view_proof`) — komponen ini tak menebak izin dari peran. Entri dari endpoint
 * yang tak memuat bukti (tanpa `has_proof`) tampil persis seperti sebelumnya.
 * Komponen ini hanya menampilkan dan memancarkan event; pemanggil yang membuka
 * bukti, membuka dialog konfirmasi, mencetak invoice, dan mengonfirmasi hapus.
 */
defineProps({
  payments: { type: Array, default: () => [] },
  canDelete: { type: Boolean, default: false },
  // Penjualan POS tak punya invoice-per-pembayaran (showPrint=false); sejak 031 bukti dimuat di
  // detail penjualan juga, jadi keduanya menampilkan "Lihat bukti" (menurut flag server).
  showProof: { type: Boolean, default: true },
  showPrint: { type: Boolean, default: true },
});
const emit = defineEmits(['view-proof', 'print', 'delete', 'edit-confirmation']);

// "Belum ada bukti" hanya bila server MENYATAKAN has_proof === false (non-tunai).
const lacksProof = (p) => p.method !== 'cash' && p.has_proof === false;
const hasAnyConfirmation = (p) => !!(p.has_proof || p.proof_id || p.reference || p.notes);
const canViewProof = (p) => p.proof_id && p.can_view_proof !== false;

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
          <span v-if="p.notes" class="whitespace-pre-line break-words text-[11px] text-muted-4" data-testid="payment-notes">
            {{ t('payment_ledger.notes_short') }}: {{ p.notes }}
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
          <StatusPill v-if="lacksProof(p)" variant="warn" data-testid="no-proof">{{ t('payment_ledger.no_proof') }}</StatusPill>
        </div>
      </div>
      <div v-if="(showProof && canViewProof(p)) || p.can_edit_confirmation || showPrint || canDelete" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-dashed border-line-2 pt-2">
        <button
          v-if="showProof && canViewProof(p)"
          type="button"
          class="whitespace-nowrap text-[12px] font-semibold text-muted-4 hover:text-brand-active"
          @click="emit('view-proof', p)"
        >{{ t('preorders.view_proof') }}</button>
        <button
          v-if="p.can_edit_confirmation"
          type="button"
          class="whitespace-nowrap text-[12px] font-semibold text-brand-active hover:underline"
          data-testid="edit-confirmation"
          @click="emit('edit-confirmation', p)"
        >{{ hasAnyConfirmation(p) ? t('payment_ledger.edit_confirmation') : t('payment_ledger.add_confirmation') }}</button>
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
