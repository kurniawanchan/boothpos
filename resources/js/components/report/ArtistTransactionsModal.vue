<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import { artistSettlementTransactions } from '../../api/reports';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { useToastStore } from '../../stores/toast';

/**
 * F11.6 — drill-down transaksi yang menyusun rekap satu artist, dibuka dari
 * baris "Rekap Artist" di ReportsView. Setiap order di sini HANYA memuat
 * item MILIK ARTIST INI (backend sudah menyaring order_items.artist_id,
 * bukan seluruh isi order) — sengaja TIDAK disaring ulang di sini supaya
 * tidak ada dua sumber kebenaran soal isolasi antar-artist dalam satu order.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  artistId: { type: [Number, String, null], default: null },
  artistName: { type: String, default: '' },
  eventId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const toast = useToastStore();
const { t } = useI18n();
const loading = ref(false);
const transactions = ref([]);

watch(
  () => [props.open, props.artistId, props.eventId],
  async ([open, artistId, eventId]) => {
    if (!open || !artistId || !eventId) {
      transactions.value = [];
      return;
    }
    loading.value = true;
    try {
      const res = await artistSettlementTransactions(artistId, eventId);
      transactions.value = res.transactions ?? [];
    } catch (err) {
      toast.error(err.message || t('reports.load_artist_transactions_failed'));
    } finally {
      loading.value = false;
    }
  },
  { immediate: true }
);
</script>

<template>
  <BaseModal :open="open" :title="t('reports.transaction_detail_for', { artist: artistName })" max-width-class="max-w-[640px]" @close="emit('close')">
    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">{{ t('reports.loading_transaction_detail') }}</div>
    <div v-else-if="transactions.length === 0" class="px-6 py-14 text-center text-[13px] text-muted-3">
      {{ t('reports.no_transactions_contributing') }}
    </div>
    <div v-else class="flex flex-col gap-3.5 px-6 py-5">
      <div
        v-for="tx in transactions"
        :key="tx.key"
        class="flex flex-col gap-2.5 rounded-lg border border-line-2 p-3.5"
      >
        <div class="flex items-center justify-between gap-2">
          <div class="flex flex-col gap-1">
            <div class="flex items-center gap-1.5">
              <span class="font-mono text-[12.5px] font-bold text-brand-active">{{ tx.number }}</span>
              <span
                class="rounded-full px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide"
                :class="tx.source === 'preorder' ? 'bg-warn-bg text-warn-text' : 'bg-mint-100 text-brand-active'"
              >
                {{ tx.source === 'preorder' ? t('reports.transaction_type_preorder') : t('reports.transaction_type_order') }}
              </span>
            </div>
            <span class="text-[11.5px] text-muted-3">{{ tx.created_at ? formatDateTime(tx.created_at) : '—' }}</span>
          </div>
          <span class="text-[14px] font-extrabold tracking-tight">{{ formatIDR(tx.amount_for_artist) }}</span>
        </div>
        <div class="overflow-hidden rounded-md border border-line-5">
          <table class="w-full border-collapse text-[12.5px]">
            <thead>
              <tr class="bg-surface-subtle text-left">
                <th class="px-3 py-1.5 font-bold text-muted-2">{{ t('master_data.col_sku') }}</th>
                <th class="px-3 py-1.5 font-bold text-muted-2">{{ t('master_data.col_name') }}</th>
                <th class="px-3 py-1.5 text-right font-bold text-muted-2">{{ t('reports.col_qty') }}</th>
                <th class="px-3 py-1.5 text-right font-bold text-muted-2">{{ t('reports.col_subtotal') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, idx) in tx.items" :key="idx" class="border-t border-line-5 transition-colors hover:bg-line-7">
                <td class="px-3 py-1.5 font-mono">{{ item.sku }}</td>
                <td class="px-3 py-1.5">{{ item.name }}</td>
                <td class="px-3 py-1.5 text-right">{{ item.qty }}</td>
                <td class="px-3 py-1.5 text-right">{{ formatIDR(item.line_total) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </BaseModal>
</template>
