<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR, parseMoney } from '../../utils/money';
import { formatCount } from '../../utils/formatters';
import KpiStrip from '../../components/page/KpiStrip.vue';
import StatusPill from '../../components/ui/StatusPill.vue';
import { STATUS_VARIANT, usePreorderLabels } from './preorderMeta';

/**
 * 041 — empat angka ringkasan dari GET /preorders/summary yang SUDAH dimuat (tanpa panggilan baru). Endpoint
 * itu memakai filter yang sama persis dengan daftar (013 US5), jadi angkanya selalu sama dengan isi tabel.
 *  - Pre-order     = transaction_count (semua status, sama dengan jumlah baris);
 *  - Nilai pesanan = grand_total (tanpa yang dibatalkan, aturan server);
 *  - Sudah dibayar = grand_total − total_outstanding (dihitung dari dua angka server yang sama-sama tanpa batal);
 *  - Belum lunas   = total_outstanding.
 * Angka yang tak dikirim server ditampilkan "—", bukan 0. Rincian jumlah per status (angka lama) tetap
 * tampil sebagai baris chip di bawah strip.
 */
const props = defineProps({
  summary: { type: Object, default: null },
});
const { t } = useI18n();
const { STATUS_LABEL } = usePreorderLabels();

const has = (v) => v !== undefined && v !== null && v !== '';
const items = computed(() => {
  const s = props.summary ?? {};
  const paid = has(s.grand_total) && has(s.total_outstanding) ? parseMoney(s.grand_total) - parseMoney(s.total_outstanding) : null;
  return [
    { key: 'count', label: t('preorders.summary_transaction_count'), value: has(s.transaction_count) ? formatCount(s.transaction_count) : null, tone: 'neutral', unavailable: !has(s.transaction_count) },
    { key: 'total', label: t('preorders.summary_grand_total'), value: has(s.grand_total) ? formatIDR(s.grand_total) : null, note: t('preorders.summary_excl_cancelled'), tone: 'info', unavailable: !has(s.grand_total) },
    { key: 'paid', label: t('preorders.summary_paid'), value: paid === null ? null : formatIDR(paid), note: t('preorders.summary_excl_cancelled'), tone: 'success', unavailable: paid === null },
    { key: 'outstanding', label: t('preorders.summary_outstanding'), value: has(s.total_outstanding) ? formatIDR(s.total_outstanding) : null, note: t('preorders.summary_excl_cancelled'), tone: 'warning', unavailable: !has(s.total_outstanding) },
  ];
});
</script>

<template>
  <div v-if="summary" data-testid="preorder-summary">
    <KpiStrip :items="items" />
    <div v-if="summary.by_status?.length" class="-mt-2 mb-4 flex flex-wrap items-center gap-1.5 px-[26px]" data-testid="preorder-status-breakdown">
      <StatusPill v-for="s in summary.by_status" :key="s.status" :variant="STATUS_VARIANT[s.status]">
        {{ STATUS_LABEL[s.status] }} · {{ s.count }} · {{ formatIDR(s.total_amount) }}
      </StatusPill>
    </div>
  </div>
</template>
