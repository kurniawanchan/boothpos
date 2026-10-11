<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR, parseMoney } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import DataTable from '../../components/ui/DataTable.vue';
import StatusPill from '../../components/ui/StatusPill.vue';
import PreorderRowActions from '../../components/preorder/PreorderRowActions.vue';
import { CLOSED_STATUSES, DISPATCH_VARIANT, STATUS_VARIANT, usePreorderLabels } from './preorderMeta';

/**
 * Tabel daftar pre-order: kolom, sel, penanda "info pengiriman kurang" dan aksi per baris. Dipecah dari
 * PreordersView.vue (041) tanpa perubahan perilaku. Presentasional: pemilihan, urutan dan aksi dilaporkan
 * ke pemanggil. Kolom pilih tetap dirender sendiri (bukan `selectable` DataTable) supaya tiap kotak centang
 * tetap berlabel "Pilih {nomor}" untuk pembaca layar — label bawaan DataTable hanya "Pilih baris".
 */
defineProps({
  rows: { type: Array, required: true },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
  sortKey: { type: String, default: null },
  sortDir: { type: String, default: 'asc' },
  selectedIds: { type: Array, default: () => [] },
});
const emit = defineEmits(['sort', 'toggle-select', 'open-detail', 'open-customer', 'open-flag', 'row-action', 'retry']);
const { t } = useI18n();
const { STATUS_LABEL, DISPATCH_LABEL, FULFILLMENT_LABEL } = usePreorderLabels();

const columns = computed(() => [
  { key: 'select', label: '' },
  { key: 'preorder_number', label: t('preorders.col_number'), sortable: true },
  { key: 'customer_name', label: t('preorders.col_customer'), sortable: true },
  // 'sellers' has no single sortable value (a preorder can have several
  // sellers) — left without `sortable` on purpose, see
  // PreorderController::applySort()'s docblock.
  { key: 'sellers', label: t('preorders.col_seller') },
  { key: 'status', label: t('preorders.col_status'), sortable: true },
  { key: 'dispatch_status', label: t('preorders.col_dispatch_status') },
  { key: 'fulfillment', label: t('preorders.col_fulfillment'), sortable: true },
  { key: 'total_amount', label: t('preorders.col_total'), sortable: true },
  { key: 'outstanding', label: t('preorders.col_outstanding'), sortable: true },
  { key: 'created_at', label: t('preorders.col_created'), sortable: true },
  { key: 'updated_at', label: t('preorders.col_updated'), sortable: true },
  { key: 'actions', label: t('preorders.col_actions') },
]);

// Requested: automatically flag a Mail Order pre-order that's missing its
// shipping cost or the customer's address — both matter only once
// fulfillment is 'courier' (Self pickup never needs either), and both are
// realistic gaps: shipping_cost defaults to 0 unless set explicitly, and
// a customer created as a walk-in may have no address on file at all.
function needsShippingAttention(row) {
  return row.fulfillment === 'courier' && (parseMoney(row.shipping_cost) <= 0 || !row.customer_has_address);
}
function preorderRowClass(row) {
  return needsShippingAttention(row) ? 'bg-warn-bg' : '';
}

// Aksi per baris list (dirender PreorderRowActions.vue). Edit/Delete
// disembunyikan — bukan dinonaktifkan — saat pasti ditolak server (aturan
// 022 FR-001a/FR-003/FR-004); Invoice pembayaran dinonaktifkan selama belum ada pembayaran.
function rowActions(row) {
  const noPayment = parseMoney(row.paid_amount) <= 0;
  const actions = [
    { key: 'invoice', label: t('preorders.print_action') },
    {
      key: 'payment_invoice',
      label: t('preorders.print_payment_receipt'),
      disabled: noPayment,
      title: noPayment ? t('preorders.print_payment_invoice_disabled') : '',
    },
  ];
  if (!CLOSED_STATUSES.includes(row.status)) actions.push({ key: 'edit', label: t('common.edit') });
  if (['ordered', 'cancelled'].includes(row.status)) actions.push({ key: 'delete', label: t('common.delete'), danger: true });
  actions.push({ key: 'duplicate', label: t('preorders.duplicate') });
  // 027 — Split disembunyikan untuk status tertutup (pasti ditolak server) tetapi
  // DINONAKTIFKAN dengan alasan bila sudah ada pembayaran, seperti "Invoice pembayaran".
  if (!CLOSED_STATUSES.includes(row.status)) {
    const paid = !!row.has_payments || parseMoney(row.paid_amount) > 0;
    actions.push({
      key: 'split',
      label: t('preorders.split'),
      disabled: paid,
      title: paid ? t('preorders.split_disabled_has_payment') : '',
    });
  }
  actions.push({ key: 'detail', label: t('preorders.detail') });
  return actions;
}
</script>

<template>
  <DataTable
    :columns="columns"
    :rows="rows"
    :loading="loading"
    :error="error"
    :empty-message="t('preorders.no_preorders')"
    :sort-key="sortKey"
    :sort-dir="sortDir"
    :row-class="preorderRowClass"
    @sort="(s) => emit('sort', s)"
    @retry="emit('retry')"
  >
    <template #cell-select="{ row }">
      <input
        type="checkbox"
        class="h-4 w-4 cursor-pointer accent-brand"
        :checked="selectedIds.includes(row.id)"
        :aria-label="t('preorders.select_row', { number: row.preorder_number })"
        @change="emit('toggle-select', row.id)"
      />
    </template>
    <template #cell-preorder_number="{ row }">
      <!-- 013-preorder-list-filters-receipt (US2, T019) — reuses the
           existing openDetail(row) handler (research.md R7), a second
           entry point into the row's already-existing "Detail" action. -->
      <span class="inline-flex items-center gap-1.5">
        <button
          v-if="needsShippingAttention(row)"
          type="button"
          class="flex h-5 w-5 items-center justify-center hover:bg-warn-border"
          :title="t('preorders.missing_shipping_info_flag')"
          :aria-label="t('preorders.missing_shipping_info_flag')"
          @click="emit('open-flag', row)"
        >
          <i class="ph-duotone ph-flag text-[14px] text-warn-text" aria-hidden="true"></i>
        </button>
        <button type="button" class="font-mono text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="emit('open-detail', row)">{{ row.preorder_number }}</button>
      </span>
    </template>
    <template #cell-customer_name="{ row }">
      <!-- Requested: clicking the customer's name opens their full
           contact/address info (name/phone/email/social/address/notes),
           fetched on demand since the list row only carries the name. -->
      <button type="button" class="text-left text-[13.5px] font-semibold text-muted-4 hover:text-brand-active hover:underline" @click="emit('open-customer', row.customer_id)">{{ row.customer_name }}</button>
    </template>
    <template #cell-status="{ row }"><StatusPill :variant="STATUS_VARIANT[row.status]">{{ STATUS_LABEL[row.status] }}</StatusPill></template>
    <template #cell-dispatch_status="{ row }">
      <div class="flex flex-col items-start gap-1">
        <StatusPill :variant="DISPATCH_VARIANT[row.dispatch_status ?? 'pending']">{{ DISPATCH_LABEL[row.dispatch_status ?? 'pending'] }}</StatusPill>
        <!-- Tanggal ditandai, di kolom yang sama (bukan kolom baru). -->
        <span v-if="row.invoice_sent_at" class="text-[11px] leading-tight text-muted-3">{{ t('preorders.dispatch_invoice_sent_on', { date: formatDateTime(row.invoice_sent_at) }) }}</span>
        <span v-if="row.shipping_at" class="text-[11px] leading-tight text-muted-3">{{ t('preorders.dispatch_shipping_on', { date: formatDateTime(row.shipping_at) }) }}</span>
      </div>
    </template>
    <template #cell-fulfillment="{ row }">{{ FULFILLMENT_LABEL[row.fulfillment] }}</template>
    <template #cell-sellers="{ row }">{{ row.sellers?.length ? row.sellers.map((s) => s.name).join(', ') : '—' }}</template>
    <template #cell-total_amount="{ row }">{{ formatIDR(row.total_amount) }}</template>
    <template #cell-outstanding="{ row }">{{ formatIDR(row.outstanding) }}</template>
    <template #cell-created_at="{ row }"><span class="whitespace-nowrap text-[12.5px] text-muted-4">{{ formatDateTime(row.created_at) }}</span></template>
    <template #cell-updated_at="{ row }"><span class="whitespace-nowrap text-[12.5px] text-muted-4">{{ formatDateTime(row.updated_at) }}</span></template>
    <template #cell-actions="{ row }">
      <!-- Sampai 3 aksi tampil sebagai tautan; lebih dari itu, Detail tetap
           terlihat dan sisanya masuk dropdown "Lainnya" (lihat
           PreorderRowActions.vue). Daftar aksi per baris: rowActions(). -->
      <PreorderRowActions :actions="rowActions(row)" @select="(key) => emit('row-action', row, key)" />
    </template>
  </DataTable>
</template>
