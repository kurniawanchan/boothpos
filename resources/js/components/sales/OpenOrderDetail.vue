<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { getTab } from '../../api/tabs';
import { useAuthStore } from '../../stores/auth';
import { usePolling } from '../../composables/usePolling';
import { useNow } from '../../composables/useNow';
import { buildBoard, formatElapsed } from '../../utils/floorBoard';
import { formatIDR } from '../../utils/money';
import { formatDateTime } from '../../utils/date';
import { paymentMethodLabel } from '../../utils/paymentMethods';
import DetailPanel from '../page/DetailPanel.vue';
import StatusPill from '../ui/StatusPill.vue';
import ErrorState from '../ui/ErrorState.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 044-sales-table-view (US3) — detail order TERBUKA di sisi kanan halaman Sales. STRICTLY HANYA-BACA: tidak ada
 * kendali tambah/ubah/kirim/bayar/pindah/gabung/pisah/batal di sini dan komponen ini hanya mengimpor `getTab`
 * (satu-satunya pembaca) dari api/tabs — bukan penulisnya. Mengubah order tetap di layar kasir (tautan opsional).
 *
 * Data = GET /tabs/{id} yang sudah ada (research D5); tanpa field biaya. Isi disegarkan ±5 dtk HANYA selagi panel
 * terbuka (usePolling: berhenti saat panel ditutup/dilepas dan saat halaman tersembunyi). Akhir hidup order (D6):
 *   - status completed → "sudah dibayar" + tombol ke popup transaksi selesai yang sudah ada (dipancarkan, induk yang membukanya);
 *   - 404 (dibatalkan / digabung) → pesan; polling berhenti;
 *   - gagal memperbarui SETELAH pernah sukses → data lama tetap + tanda usang; gagal sebelum sukses → galat + Coba lagi.
 */
const props = defineProps({
  orderId: { type: [Number, String], default: null }, // null = panel tertutup
  tile: { type: Object, default: null }, // ubin papan yang dipilih: hanya untuk judul/kicker sebelum data tiba
});
const emit = defineEmits(['close', 'view-transaction']);
const { t } = useI18n();
const auth = useAuthStore();
const now = useNow(15000);

const PREP_VARIANT = { new: 'neutral', preparing: 'warn', ready: 'mint', served: 'neutral', cancelled: 'danger' };

const order = ref(null);
const phase = ref('loading'); // loading | live | paid | voided | error
const outdated = ref(false);

const { start, stop, refresh } = usePolling(async () => {
  const id = props.orderId;
  if (id === null || id === undefined) return;

  let data;
  try {
    data = await getTab(id);
  } catch (err) {
    if (id !== props.orderId) return; // jawaban order sebelumnya yang terlambat
    if (err?.isNotFound || err?.status === 404) {
      phase.value = 'voided';
      outdated.value = false;
      stop();
      return;
    }
    if (order.value) outdated.value = true;
    else phase.value = 'error';
    throw err; // biar usePolling menjeda lebih lama lalu mencoba lagi sendiri
  }
  if (id !== props.orderId) return;

  order.value = data;
  outdated.value = false;
  if (data.status === 'completed') {
    phase.value = 'paid';
    stop(); // order sudah selesai: tak ada lagi yang perlu disegarkan
  } else if (data.status === 'voided') {
    phase.value = 'voided';
    stop();
  } else {
    phase.value = 'live';
  }
}, { interval: 5000 });

// Dideklarasikan SETELAH semua ref yang disentuhnya (immediate: true berjalan seketika saat setup).
// Ganti order / tutup: buang data order lama supaya tak sempat tampil sebagai milik order baru.
watch(() => props.orderId, (id) => {
  stop();
  order.value = null;
  phase.value = 'loading';
  outdated.value = false;
  if (id !== null && id !== undefined) start();
}, { immediate: true });

// Lewat-45-menit dan sebagian-dibayar dihitung oleh SATU implementasi (floorBoard.js), bukan rumus kedua di sini:
// order ini diperlakukan sebagai satu ubin takeaway sementara. Payload tab memakai `created_at` untuk waktu buka.
const flags = computed(() => {
  if (!order.value) return null;
  const o = order.value;
  const [tile] = buildBoard({
    takeaway_tabs: [{ id: o.id, order_number: o.order_number, total_amount: o.total_amount, paid_amount: o.paid_amount, opened_at: o.created_at }],
  }, now.value).takeaway;

  return tile;
});

const open = computed(() => props.orderId !== null && props.orderId !== undefined);
const isTable = computed(() => (order.value ? !!order.value.table : props.tile?.kind === 'table'));
const title = computed(() => order.value?.order_number ?? props.tile?.orderNumber ?? '');
const kicker = computed(() => {
  if (!order.value && !props.tile) return '';
  if (!isTable.value) return t('sales.detail_kicker_takeaway');
  return t('sales.detail_kicker_table', { name: order.value?.table?.name ?? props.tile?.name ?? '' });
});

const chips = computed(() => {
  if (phase.value !== 'live' || !flags.value) return [];
  const list = [{ label: t('sales.detail_chip_open'), variant: 'info' }];
  if (flags.value.partial) list.push({ label: t('sales.detail_chip_partial'), variant: 'warning' });
  if (flags.value.overLimit) list.push({ label: t('sales.detail_chip_over'), variant: 'warning' });

  return list;
});

const lines = computed(() => order.value?.lines ?? []);
const kitchenSummary = computed(() => t('sales.detail_kitchen_summary', {
  ready: lines.value.filter((l) => l.prep_status === 'ready').length,
  unsent: lines.value.filter((l) => !l.sent).length,
}));

const facts = computed(() => {
  const o = order.value;
  if (!o) return [];
  const place = o.table ? [o.table.name, o.table.area].filter(Boolean).join(' · ') : (o.tab_label || t('sales.takeaway_group'));

  return [
    { k: t('sales.detail_fact_opened_by'), v: o.opened_by || '—' },
    { k: t('sales.detail_fact_opened_at'), v: formatDateTime(o.created_at) },
    { k: t('sales.detail_fact_elapsed'), v: formatElapsed(flags.value?.elapsedMinutes, t) || '—' },
    { k: t('sales.detail_fact_table'), v: place },
    { k: t('sales.detail_fact_kitchen'), v: kitchenSummary.value },
  ];
});

function kitchenLabel(line) {
  if (!line.sent) return t('sales.detail_kitchen_unsent');
  const base = line.prep_status ? t(`kitchen.status_${line.prep_status}`) : t('floor.tab.sent');

  return line.station ? `${base} · ${line.station.name}` : base;
}
const kitchenVariant = (line) => (line.sent ? (PREP_VARIANT[line.prep_status] ?? 'mint') : 'neutral');

const totals = computed(() => {
  const o = order.value;
  if (!o) return [];

  return [
    { key: 'subtotal', label: t('sales.detail_subtotal'), value: o.subtotal },
    { key: 'discount', label: t('sales.detail_discount'), value: o.discount_amount },
    { key: 'total', label: t('sales.detail_total'), value: o.total_amount, strong: true },
    { key: 'paid', label: t('sales.detail_paid'), value: o.paid_amount },
    { key: 'balance', label: t('sales.detail_balance'), value: o.balance, strong: true },
  ];
});

const showData = computed(() => order.value && phase.value !== 'voided' && phase.value !== 'error');
const canOpenCashier = computed(() => phase.value === 'live' && auth.canAccessMenu('floor'));
</script>

<template>
  <DetailPanel :open="open" :kicker="kicker" :title="title" :chips="chips" :facts="showData ? facts : []" @close="emit('close')">
    <p v-if="phase === 'loading'" role="status" class="py-8 text-center text-[13px] text-muted-3">{{ t('sales.detail_loading') }}</p>

    <ErrorState v-else-if="phase === 'error'" :message="t('sales.detail_error')" @retry="refresh" />

    <template v-else>
      <p v-if="outdated" role="status" class="mb-4 border-2 border-warn-border bg-warn-bg px-3.5 py-2.5 text-[12.5px] font-semibold text-warn-text">
        {{ t('sales.detail_outdated') }}
      </p>

      <div v-if="phase === 'paid'" role="status" class="mb-4 flex flex-col items-start gap-2.5 border-2 border-line bg-white px-3.5 py-3 text-[13px] font-semibold text-muted-5">
        <span>{{ t('sales.detail_ended_paid') }}</span>
        <BaseButton size="sm" variant="secondary" @click="emit('view-transaction', order?.id ?? orderId)">{{ t('sales.detail_view_transaction') }}</BaseButton>
      </div>

      <p v-if="phase === 'voided'" role="status" class="mb-4 border-2 border-line bg-white px-3.5 py-3 text-[13px] font-semibold text-muted-5">
        {{ t('sales.detail_ended_voided') }}
      </p>

      <template v-if="showData">
        <RouterLink
          v-if="canOpenCashier"
          :to="{ name: 'floor-tab', params: { id: order.id } }"
          class="mb-5 inline-block text-[12.5px] font-semibold text-brand-active hover:underline"
        >{{ t('sales.detail_open_cashier') }}</RouterLink>

        <h3 class="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted">{{ t('sales.detail_lines') }}</h3>
        <table class="mb-5 w-full border-2 border-line bg-white text-left text-[13px]" data-testid="detail-lines">
          <thead>
            <tr class="border-b-2 border-line text-[11px] font-bold uppercase tracking-wide text-muted">
              <th scope="col" class="w-12 px-3 py-2">{{ t('sales.detail_col_qty') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('sales.detail_col_item') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('sales.detail_col_kitchen') }}</th>
              <th scope="col" class="px-3 py-2 text-right">{{ t('sales.detail_col_amount') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="line in lines" :key="line.id" class="border-b border-line-3 align-top last:border-b-0" :class="{ 'opacity-60': line.prep_status === 'cancelled' }">
              <td class="px-3 py-2.5 font-bold tabular-nums">{{ line.qty }}×</td>
              <td class="px-3 py-2.5">
                <span class="font-bold" :class="{ 'line-through': line.prep_status === 'cancelled' }">{{ line.name }}</span>
                <ul v-if="line.modifiers?.length" class="mt-0.5 flex flex-wrap gap-x-2 text-[12px] text-muted-3">
                  <li v-for="(m, i) in line.modifiers" :key="i">{{ m.option }}</li>
                </ul>
                <p v-if="line.note" class="mt-0.5 text-[12px] italic text-muted-3">“{{ line.note }}”</p>
              </td>
              <td class="px-3 py-2.5"><StatusPill :variant="kitchenVariant(line)">{{ kitchenLabel(line) }}</StatusPill></td>
              <td class="px-3 py-2.5 text-right font-bold tabular-nums">{{ formatIDR(line.line_total) }}</td>
            </tr>
          </tbody>
        </table>

        <div class="mb-5 flex flex-col gap-1.5 border-2 border-line bg-white px-3.5 py-3 text-[13px]" data-testid="detail-totals">
          <div v-for="row in totals" :key="row.key" class="flex items-baseline justify-between gap-3" :class="row.strong ? 'font-bold text-ink' : 'text-muted-4'">
            <span>{{ row.label }}</span>
            <span class="tabular-nums">{{ formatIDR(row.value) }}</span>
          </div>
        </div>

        <h3 class="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted">{{ t('sales.detail_payments') }}</h3>
        <div data-testid="detail-payments">
          <p v-if="!(order.payments ?? []).length" class="text-[13px] text-muted-3">{{ t('sales.detail_no_payments') }}</p>
          <ul v-else class="flex flex-col divide-y divide-line-3 border-2 border-line bg-white">
            <li v-for="p in order.payments" :key="p.id" class="flex items-baseline justify-between gap-3 px-3.5 py-2.5 text-[13px]">
              <span class="flex flex-col">
                <span class="font-semibold">{{ paymentMethodLabel(t, p.method) }}</span>
                <span class="text-[11.5px] text-muted-3">{{ formatDateTime(p.paid_at) }}</span>
              </span>
              <span class="font-bold tabular-nums">{{ formatIDR(p.amount) }}</span>
            </li>
          </ul>
        </div>
      </template>
    </template>
  </DetailPanel>
</template>
