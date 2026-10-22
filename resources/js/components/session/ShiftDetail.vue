<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { sessionSummary, sessionCashMovements } from '../../api/sessions';
import { formatIDR } from '../../utils/formatters';
import { formatDateTime } from '../../utils/date';
import CashMovementsTable from './CashMovementsTable.vue';
import LoadingState from '../ui/LoadingState.vue';
import ErrorState from '../ui/ErrorState.vue';

/**
 * 065 / US1+US2 — rincian satu shift: informasi penjualan (dari GET /sessions/{id}/summary, rumus kas satu
 * tempat di server), pembagian per metode bayar, dan catatan kas masuk/keluar shift itu. Shift tertutup
 * menampilkan hitungan, kas seharusnya, dan selisih yang tersimpan.
 */
const props = defineProps({ sessionId: { type: Number, required: true } });
const emit = defineEmits(['close']);
const { t } = useI18n();

const state = ref('loading'); // loading | ready | error
const summary = ref(null);
const movements = ref([]);
let seq = 0;

async function load() {
  const mine = ++seq;
  state.value = 'loading';
  try {
    const [s, m] = await Promise.all([sessionSummary(props.sessionId), sessionCashMovements(props.sessionId)]);
    if (mine !== seq) return; // pengguna sudah membuka shift lain
    summary.value = s;
    movements.value = m;
    state.value = 'ready';
  } catch {
    if (mine === seq) state.value = 'error';
  }
}
onMounted(load);
watch(() => props.sessionId, load);

const session = computed(() => summary.value?.session ?? {});
const closed = computed(() => session.value.status === 'closed');
const METHOD = computed(() => ({
  cash: t('events_sessions.method_full_cash'),
  bank_transfer: t('events_sessions.method_full_bank_transfer'),
  qr_ewallet: t('events_sessions.method_full_qris'),
}));
const diff = computed(() => (session.value.cash_difference === null || session.value.cash_difference === undefined ? null : Number(session.value.cash_difference)));
</script>

<template>
  <section class="flex flex-col gap-4 border-2 border-line bg-white p-5" data-testid="shift-detail" :aria-label="t('shifts.detail_title', { id: sessionId })">
    <div class="flex items-start justify-between gap-3">
      <h3 class="text-[15px] font-bold tracking-tight">{{ t('shifts.detail_title', { id: sessionId }) }}</h3>
      <button type="button" class="text-[12.5px] font-bold text-brand-active underline decoration-dotted hover:text-brand" data-testid="shift-detail-dismiss" @click="emit('close')">{{ t('shifts.detail_close') }}</button>
    </div>

    <LoadingState v-if="state === 'loading'" :rows="3" />
    <ErrorState v-else-if="state === 'error'" @retry="load" />
    <template v-else>
      <p class="text-[12.5px] text-muted">
        {{ t('shifts.opened_by', { name: session.user?.name ?? '—', time: formatDateTime(session.opened_at) }) }}
        <template v-if="summary.terminal_name"> · {{ summary.terminal_name }}</template>
        <template v-if="session.closed_at"> · {{ t('shifts.closed_at', { time: formatDateTime(session.closed_at) }) }}</template>
      </p>

      <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="flex flex-col gap-1 border border-line-3 bg-surface-subtle p-3.5"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('shifts.col_opening') }}</span><span class="text-[17px] font-extrabold tracking-tight">{{ formatIDR(session.opening_cash ?? 0) }}</span></div>
        <div class="flex flex-col gap-1 border border-line-3 bg-surface-subtle p-3.5"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('shifts.col_sales') }}</span><span class="text-[17px] font-extrabold tracking-tight" data-testid="detail-sales">{{ formatIDR(summary.total_sales ?? 0) }}</span><span class="text-[11.5px] text-muted-3">{{ t('shifts.receipts', { count: summary.order_count ?? 0 }) }}</span></div>
        <div class="flex flex-col gap-1 border border-line-3 bg-surface-subtle p-3.5"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('shifts.cash_in_out') }}</span><span class="text-[17px] font-extrabold tracking-tight" data-testid="detail-cash-io">{{ formatIDR(summary.cash_in ?? 0) }} / {{ formatIDR(summary.cash_out ?? 0) }}</span></div>
        <div class="flex flex-col gap-1 border border-line-3 bg-surface-subtle p-3.5"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('shifts.col_expected') }}</span><span class="text-[17px] font-extrabold tracking-tight" data-testid="detail-expected">{{ formatIDR(closed ? (session.expected_cash ?? 0) : (summary.expected_cash ?? 0)) }}</span></div>
      </div>

      <div v-if="closed" class="grid grid-cols-2 gap-3 lg:grid-cols-2" data-testid="detail-closed">
        <div class="flex flex-col gap-1 border border-line-3 bg-surface-subtle p-3.5"><span class="text-[11.5px] font-semibold text-muted-2">{{ t('shifts.col_counted') }}</span><span class="text-[17px] font-extrabold tracking-tight">{{ formatIDR(session.closing_cash ?? 0) }}</span></div>
        <div class="flex flex-col gap-1 border p-3.5" :class="diff ? 'border-danger-border bg-danger-bg' : 'border-line-3 bg-surface-subtle'"><span class="text-[11.5px] font-semibold" :class="diff ? 'text-danger-text' : 'text-muted-2'">{{ t('shifts.col_difference') }}</span><span class="text-[17px] font-extrabold tracking-tight" :class="diff ? 'text-danger-text' : ''">{{ diff === null ? '—' : `${diff < 0 ? '−' : diff > 0 ? '+' : ''}${formatIDR(Math.abs(diff))}` }}</span></div>
      </div>

      <div v-if="(summary.by_method ?? []).length" class="flex flex-col gap-1.5" data-testid="detail-methods">
        <h4 class="text-[12.5px] font-bold text-muted-4">{{ t('shifts.by_method') }}</h4>
        <div v-for="m in summary.by_method" :key="m.method" class="flex justify-between border-b border-line-3 py-1 text-[13px]"><span>{{ METHOD[m.method] ?? m.method }} · {{ m.count }}×</span><span class="font-semibold">{{ formatIDR(m.amount) }}</span></div>
      </div>

      <div class="flex flex-col gap-1.5">
        <h4 class="text-[12.5px] font-bold text-muted-4">{{ t('shifts.cash_in_out') }}</h4>
        <CashMovementsTable :rows="movements" :show-shift="false" />
      </div>
      <p v-if="session.notes" class="text-[12.5px] text-muted-4">{{ session.notes }}</p>
    </template>
  </section>
</template>
