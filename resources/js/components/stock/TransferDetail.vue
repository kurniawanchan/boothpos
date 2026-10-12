<script setup>
import { useI18n } from 'vue-i18n';
import { formatDateTime } from '../../utils/date';
import StatusPill from '../ui/StatusPill.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';

/**
 * 045 Stage 6 (T093) — detail transfer: status, langkah (siapa/kapan), baris (dikirim/diterima/selisih + peringatan
 * "tidak dijual di tujuan") dan jejak buku besar. Tombol Terima/Batal hanya bila server mengizinkan (`can`) — UI tidak
 * menurunkan hak dari peran.
 */
defineProps({
  open: { type: Boolean, default: false },
  transfer: { type: Object, default: null },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'receive', 'cancel']);
const { t } = useI18n();
const TONE = { in_transit: 'warning', received: 'success', cancelled: 'neutral' };
</script>

<template>
  <BaseModal :open="open" :title="transfer ? t('transfers.detail_title', { number: transfer.number }) : t('transfers.title')" max-width-class="max-w-[760px]" @close="emit('close')">
    <p v-if="loading || !transfer" class="px-6 py-8 text-center text-[13px] text-muted-3">{{ t('page.loading') }}</p>
    <div v-else class="flex flex-col gap-4 px-6 py-5" data-testid="transfer-detail">
      <div class="flex flex-wrap items-center gap-3">
        <StatusPill :variant="TONE[transfer.status]">{{ t(`transfers.status_${transfer.status}`) }}</StatusPill>
        <span class="text-[13.5px] font-semibold">{{ transfer.from_outlet?.name }} → {{ transfer.to_outlet?.name }}</span>
        <StatusPill v-if="transfer.has_discrepancy" variant="danger" data-testid="discrepancy-flag">{{ t('transfers.discrepancy') }}</StatusPill>
      </div>
      <p v-if="transfer.note" class="text-[12.5px] text-muted-4">{{ transfer.note }}</p>

      <table class="w-full border-collapse text-left text-[13px]" data-testid="transfer-lines">
        <thead>
          <tr class="border-b-2 border-line text-[12px] text-muted-4">
            <th class="py-2 pr-3">{{ t('transfers.col_item') }}</th>
            <th class="py-2 pr-3 text-right">{{ t('transfers.col_sent') }}</th>
            <th class="py-2 pr-3 text-right">{{ t('transfers.col_received') }}</th>
            <th class="py-2">{{ t('transfers.col_note') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in transfer.lines" :key="l.id" class="border-b border-line align-top">
            <td class="py-2 pr-3">
              <span class="font-semibold">{{ l.item?.name }}</span>
              <span v-if="l.warnings?.includes('not_carried_at_destination')" class="block text-[12px] text-warn-text" data-testid="not-carried-warning">{{ t('transfers.not_carried_warning') }}</span>
            </td>
            <td class="py-2 pr-3 text-right font-mono">{{ l.qty_sent }}</td>
            <td class="py-2 pr-3 text-right font-mono">{{ l.qty_received ?? '—' }}</td>
            <td class="py-2 text-[12.5px] text-muted-4">{{ l.discrepancy_reason ? t('transfers.short_by', { n: l.shortfall, reason: l.discrepancy_reason }) : '' }}</td>
          </tr>
        </tbody>
      </table>

      <ol class="flex flex-col gap-1 text-[12.5px] text-muted-4" data-testid="transfer-steps">
        <li v-for="s in transfer.steps" :key="s.step">{{ t(`transfers.step_${s.step}`, { name: s.by ?? '—', time: formatDateTime(s.at) }) }}</li>
      </ol>

      <details v-if="transfer.ledger?.length" class="text-[12.5px]">
        <summary class="cursor-pointer font-semibold text-muted-5">{{ t('transfers.ledger') }}</summary>
        <ul class="mt-1.5 flex flex-col gap-0.5 font-mono text-[12px] text-muted-4">
          <li v-for="(m, i) in transfer.ledger" :key="i">{{ m.outlet?.code }} · {{ m.type }} · {{ m.qty_change }} ({{ m.stock_before }} → {{ m.stock_after }})</li>
        </ul>
      </details>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.close') }}</BaseButton>
        <BaseButton v-if="transfer?.can?.cancel" variant="danger" data-testid="transfer-cancel" @click="emit('cancel', transfer)">{{ t('transfers.cancel') }}</BaseButton>
        <BaseButton v-if="transfer?.can?.receive" data-testid="transfer-receive" @click="emit('receive', transfer)">{{ t('transfers.receive') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
