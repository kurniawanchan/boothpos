<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { resolveAppName } from '../../utils/appName';
import { formatIDRExact } from '../../utils/money';
import { formatDate, formatDateTime } from '../../utils/date';

/**
 * 076 (US3) — bukti settlement untuk dicetak/diunduh. SATU-SATUNYA tata letaknya.
 * Murni presentasi dari payload `GET /settlements/{id}/document`: tidak
 * menghitung uang, tidak memuat data pelanggan (payload memang tak membawanya),
 * dan biaya pokok / laba kotor HANYA tampil bila payload memuatnya (pemilik
 * mencentangnya). Tanda tangan datang sebagai `signatureSrc` (data URL) supaya
 * pdfCapture mengganti gambar berdasarkan src-nya sendiri.
 */
const props = defineProps({
  doc: { type: Object, required: true },
  signatureSrc: { type: String, default: null },
});

const { t } = useI18n();

const appName = computed(() => resolveAppName(props.doc.app_name));
const store = computed(() => props.doc.store_identity ?? {});
const header = computed(() => props.doc.settlement);
const lines = computed(() => props.doc.lines);
const ack = computed(() => props.doc.acknowledgement);
const directionLabel = computed(() => t(`settlements.direction_${header.value.direction}`));
const hasMemoCost = computed(() => props.doc.memo && 'cost_of_goods' in props.doc.memo);

const METHOD_LABEL = (m) => t(`settlements.pay_method_${m}`);
</script>

<template>
  <div class="flex flex-col gap-[18px] bg-white px-8 py-7 text-[13px]" data-testid="settlement-proof">
    <div class="flex flex-col items-center gap-1 border-b border-dashed border-line-2 pb-4 text-center">
      <img v-if="store.logo_url" :src="store.logo_url" :alt="store.name" class="mb-1 h-20 w-auto max-w-[240px] object-contain" />
      <span class="text-[18px] font-extrabold tracking-tight">{{ store.name }}</span>
      <span v-if="store.address" class="max-w-[420px] text-[11.5px] leading-snug text-muted-3">{{ store.address }}</span>
      <span v-if="store.contact_phone || store.contact_email" class="text-[11.5px] text-muted-3">
        {{ [store.contact_phone, store.contact_email].filter(Boolean).join(' · ') }}
      </span>
    </div>

    <div class="flex flex-col items-center gap-0.5 text-center">
      <span class="text-[15px] font-bold">{{ t('settlements.doc_title_settlement') }}</span>
      <span class="font-mono text-[15px] font-bold" data-testid="doc-number">{{ header.number }}</span>
    </div>

    <dl class="grid grid-cols-2 gap-x-6 gap-y-1.5 rounded-lg border border-line-3 px-4 py-3">
      <div><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_seller') }}</dt><dd class="font-bold">{{ header.seller.name }}</dd></div>
      <div><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_event') }}</dt><dd class="font-bold">{{ header.event.name }}</dd></div>
      <div><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_date') }}</dt><dd>{{ formatDate(header.finalized_at) }}</dd></div>
      <div><dt class="text-[11px] font-semibold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_payment_direction') }}</dt><dd>{{ directionLabel }}</dd></div>
    </dl>

    <section class="flex flex-col gap-1.5">
      <h3 class="text-[12px] font-bold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_statement') }}</h3>
      <div class="flex justify-between"><span>{{ t('settlements.revenue_pos') }} · {{ t('settlements.units_n', { count: lines.pos_units }) }}</span><span class="tabular-nums">{{ formatIDRExact(lines.pos_revenue) }}</span></div>
      <div class="flex justify-between"><span>{{ t('settlements.revenue_preorder') }} · {{ t('settlements.units_n', { count: lines.preorder_units }) }}</span><span class="tabular-nums">{{ formatIDRExact(lines.preorder_revenue) }}</span></div>
      <div class="flex justify-between border-t border-dashed border-line-2 pt-1.5 font-bold"><span>{{ t('settlements.revenue_total') }}</span><span class="tabular-nums">{{ formatIDRExact(lines.revenue_total) }}</span></div>
      <div v-for="(adj, i) in lines.adjustments" :key="i" class="flex justify-between gap-3" data-testid="doc-adjustment">
        <span>
          {{ adj.label }}
          <span v-if="adj.basis" class="text-[11.5px] text-muted-3">({{ adj.basis }})</span>
        </span>
        <span class="tabular-nums">{{ formatIDRExact(adj.amount) }}</span>
      </div>
      <div class="flex justify-between border-t border-line-2 pt-1.5 text-[15px] font-extrabold"><span>{{ t('settlements.doc_total_line') }}</span><span class="tabular-nums" data-testid="doc-net">{{ formatIDRExact(lines.net_amount) }}</span></div>
      <div class="flex justify-between text-muted-4"><span>{{ t('settlements.paid_label') }}</span><span class="tabular-nums">{{ formatIDRExact(lines.paid_amount) }}</span></div>
      <div class="flex justify-between font-bold"><span>{{ t('settlements.balance_label') }}</span><span class="tabular-nums">{{ formatIDRExact(lines.balance) }}</span></div>
    </section>

    <section class="flex flex-col gap-1.5">
      <h3 class="text-[12px] font-bold uppercase tracking-wide text-muted-3">{{ t('settlements.sources_title') }}</h3>
      <table class="w-full border-collapse text-[12px]">
        <thead>
          <tr class="border-b border-line-2 text-left text-[10.5px] font-bold uppercase tracking-wide text-muted-3">
            <th class="py-1.5 pr-2">{{ t('settlements.src_col_number') }}</th>
            <th class="py-1.5 pr-2">{{ t('settlements.src_col_type') }}</th>
            <th class="py-1.5 pr-2 text-right">{{ t('settlements.src_col_units') }}</th>
            <th class="py-1.5 text-right">{{ t('settlements.src_col_amount') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in doc.sources" :key="`${row.source_type}-${row.source_number}`" class="border-b border-dashed border-line-2 last:border-b-0">
            <td class="py-1.5 pr-2 font-mono">{{ row.source_number }}</td>
            <td class="py-1.5 pr-2">{{ t(`settlements.src_type_${row.source_type}`) }}</td>
            <td class="py-1.5 pr-2 text-right tabular-nums">{{ row.units }}</td>
            <td class="py-1.5 text-right tabular-nums">{{ formatIDRExact(row.amount) }}</td>
          </tr>
        </tbody>
      </table>
      <p class="text-[10.5px] text-muted-3">{{ t('settlements.doc_rounding') }}</p>
    </section>

    <section class="flex flex-col gap-1 rounded-lg bg-surface-subtle px-4 py-2.5 text-[12px]">
      <span class="font-bold">{{ t('settlements.doc_reconciliation') }}</span>
      <span>{{ t('settlements.doc_reconciliation_ok', { diff: formatIDRExact(doc.reconciliation.difference) }) }}</span>
      <span v-if="doc.memo?.preorder_uncollected && Number(doc.memo.preorder_uncollected) > 0" class="text-muted-3">
        {{ t('settlements.doc_uncollected') }}: {{ formatIDRExact(doc.memo.preorder_uncollected) }}
      </span>
      <template v-if="hasMemoCost">
        <span class="text-muted-3" data-testid="doc-cost">{{ t('settlements.doc_cogs') }}: {{ formatIDRExact(doc.memo.cost_of_goods) }}</span>
        <span class="text-muted-3">{{ t('settlements.doc_profit') }}: {{ formatIDRExact(doc.memo.gross_profit) }}</span>
      </template>
    </section>

    <section v-if="doc.payments.length" class="flex flex-col gap-1">
      <h3 class="text-[12px] font-bold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_payments') }}</h3>
      <div v-for="(p, i) in doc.payments" :key="i" class="flex justify-between text-[12px]">
        <span>{{ formatDate(p.paid_at) }} · {{ METHOD_LABEL(p.method) }}<template v-if="p.reference"> · {{ p.reference }}</template></span>
        <span class="tabular-nums">{{ formatIDRExact(p.amount) }}</span>
      </div>
    </section>

    <section class="flex flex-col gap-3 border-t border-dashed border-line-2 pt-4" data-testid="doc-ack">
      <h3 class="text-[12px] font-bold uppercase tracking-wide text-muted-3">{{ t('settlements.doc_ack_block') }}</h3>
      <div class="grid grid-cols-2 gap-8">
        <div class="flex flex-col items-center gap-1 text-center">
          <div class="flex h-[84px] w-full items-end justify-center border-b border-line">
            <img v-if="ack && signatureSrc" :src="signatureSrc" :alt="t('settlements.ack_signature_alt')" class="max-h-[80px] max-w-full object-contain" />
          </div>
          <span class="text-[11.5px] font-semibold">{{ ack?.signer_name || t('settlements.doc_signature_line_seller') }}</span>
          <span v-if="ack" class="text-[10.5px] text-muted-3">{{ t('settlements.doc_acknowledged_on', { date: formatDateTime(ack.acknowledged_at) }) }}</span>
          <span v-else class="text-[10.5px] text-muted-3">{{ t('settlements.doc_not_acknowledged') }}</span>
          <span v-if="ack?.method === 'external'" class="text-[10.5px] font-semibold">{{ t('settlements.doc_outside_note') }}<template v-if="ack.note"> — {{ ack.note }}</template></span>
        </div>
        <div class="flex flex-col items-center gap-1 text-center">
          <div class="h-[84px] w-full border-b border-line"></div>
          <span class="text-[11.5px] font-semibold">{{ t('settlements.doc_signature_line_store') }}</span>
        </div>
      </div>
    </section>

    <div class="border-t border-dashed border-line-2 pt-3 text-center text-[10.5px] text-muted-3">
      {{ t('common.powered_by', { name: appName }) }}
    </div>
  </div>
</template>
