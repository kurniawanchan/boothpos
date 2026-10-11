<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { RouterLink } from 'vue-router';
import { formatIDR } from '../../utils/formatters';
import { paymentMethodLabel } from '../../utils/paymentMethods';

/**
 * 042-dashboard-revamp (US2) — satu butir "Perlu tindakan": ikon, judul, nilai, dan baris keterangan. Bentuk nilai
 * dan keterangan ditentukan per `kind` (contracts/api-dashboard.md GET /dashboard/attention).
 *
 * Aturan yang sengaja dijaga:
 *  - Tingkat keparahan TIDAK hanya warna: tiap tingkat punya ikon sudut berbeda + teks `sr-only` (dan label
 *    "Kritis" yang terlihat untuk `critical`), jadi pembaca layar dan pengguna buta warna mendapat informasi sama.
 *  - `ok` memperlihatkan kalimat "semua aman" per jenis dengan ikon centang — bukan kartu kosong.
 *  - Butir hanya menjadi tautan bila `link` ada DAN ada yang perlu ditindaklanjuti. Tanpa `link` (peran tak
 *    punya menu tujuan) ia teks biasa, BUKAN tombol yang dinonaktifkan; `ok` juga teks biasa karena tujuannya
 *    akan kosong.
 *  - Nilai uang yang tidak tercatat (void lama tanpa harga snapshot) tidak dikarang: hanya jumlah item dan
 *    keterangan "n item tanpa nilai tercatat".
 *
 * @prop {string} kind  payments_pending | void_after_fire | cash_difference | low_stock
 * @prop {'ok'|'warn'|'critical'} severity
 * @prop {number} count
 * @prop {string|null} amount  string uang dari server (boleh negatif untuk selisih kas) atau null
 * @prop {object} meta
 * @prop {{route: string, query?: object}|null} link
 * @prop {number} amountUnknownCount
 */
const props = defineProps({
  kind: { type: String, required: true },
  severity: { type: String, default: 'warn' },
  count: { type: Number, default: 0 },
  amount: { type: [String, Number], default: null },
  meta: { type: Object, default: () => ({}) },
  link: { type: Object, default: null },
  amountUnknownCount: { type: Number, default: 0 },
});
const { t } = useI18n();

const ICONS = {
  payments_pending: 'ph-qr-code',
  void_after_fire: 'ph-x-circle',
  cash_difference: 'ph-coins',
  low_stock: 'ph-package',
};
const KNOWN = Object.keys(ICONS);

const isOk = computed(() => props.severity === 'ok');
const sev = computed(() => (['ok', 'warn', 'critical'].includes(props.severity) ? props.severity : 'warn'));
const hasAmount = computed(() => props.amount !== null && props.amount !== undefined && props.amount !== '');
const isLink = computed(() => !!props.link && !isOk.value);

const title = computed(() => (KNOWN.includes(props.kind) ? t(`dashboard.attention_${props.kind}`) : props.kind));
const okText = computed(() => (KNOWN.includes(props.kind) ? t(`dashboard.attention_ok_${props.kind}`) : ''));
const icon = computed(() => ICONS[props.kind] ?? 'ph-bell');

/** "−Rp 23.500" / "+Rp 5.000" — selisih kas selalu bertanda; minus memakai tanda minus tipografis. */
function signedMoney(value) {
  const n = Number(value);
  if (!Number.isFinite(n) || n === 0) return formatIDR(0);
  return `${n < 0 ? '−' : '+'}${formatIDR(Math.abs(n))}`;
}

const valueText = computed(() => {
  const money = hasAmount.value ? formatIDR(props.amount) : '';
  switch (props.kind) {
    case 'payments_pending':
      return money ? `${props.count} · ${money}` : String(props.count);
    case 'void_after_fire': {
      const items = t('dashboard.attention_items', { count: props.count });
      return money ? `${items} · ${money}` : items;
    }
    case 'cash_difference':
      return hasAmount.value ? signedMoney(props.amount) : t('dashboard.attention_shifts', { count: props.count });
    case 'low_stock':
      return t('dashboard.attention_items', { count: props.count });
    default:
      return String(props.count);
  }
});

const metaText = computed(() => {
  const parts = [];
  const m = props.meta ?? {};
  if (props.kind === 'payments_pending' && m.by_method) {
    Object.entries(m.by_method).forEach(([method, n]) => parts.push(`${paymentMethodLabel(t, method)} ${n}`));
  }
  if (props.kind === 'void_after_fire' && m.share_of_gross_pct !== undefined && m.share_of_gross_pct !== null) {
    parts.push(t('dashboard.attention_share_of_gross', { pct: String(m.share_of_gross_pct).replace('.', ',') }));
  }
  if (props.kind === 'cash_difference') {
    if (props.count > 1) parts.push(t('dashboard.attention_shifts', { count: props.count }));
    if (m.tolerance !== undefined && m.tolerance !== null) parts.push(t('dashboard.attention_tolerance', { amount: formatIDR(m.tolerance) }));
  }
  if (props.amountUnknownCount > 0) parts.push(t('dashboard.attention_unknown_amount', { count: props.amountUnknownCount }));
  return parts.join(' · ');
});

// Kotak ikon + ikon sudut menurut tingkat. Token saja (tanpa hex); ikon sudut menjadi penanda non-warna.
const BOX = {
  ok: 'bg-success-bg text-success-text',
  warn: 'bg-warn-bg text-warn-text',
  critical: 'bg-danger-bg text-danger-text',
};
const CORNER = { ok: 'ph-check-circle', warn: 'ph-warning', critical: 'ph-warning-octagon' };

const rowClass = 'flex min-w-0 items-start gap-3.5 px-5 py-4 text-left';
const to = computed(() => (props.link ? { name: props.link.route, query: props.link.query ?? {} } : null));
</script>

<template>
  <component
    :is="isLink ? RouterLink : 'div'"
    :to="isLink ? to : undefined"
    :class="[rowClass, isLink ? 'transition-colors hover:bg-mint-50 focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-inset focus-visible:ring-mint-100' : '']"
    :data-testid="`attention-${kind}`"
    :data-severity="sev"
  >
    <span class="relative flex h-10 w-10 shrink-0 items-center justify-center" :class="BOX[sev]">
      <i class="ph-duotone text-[20px]" :class="isOk ? 'ph-check-circle' : icon" aria-hidden="true"></i>
      <i v-if="!isOk" class="ph-duotone absolute -right-1 -top-1 text-[13px]" :class="CORNER[sev]" aria-hidden="true" data-testid="severity-icon"></i>
    </span>
    <span class="flex min-w-0 flex-col gap-0.5">
      <span class="flex flex-wrap items-center gap-2 text-[13px] font-semibold text-muted-5">
        {{ title }}
        <span v-if="sev === 'critical'" class="bg-danger-bg px-1.5 py-px text-[10.5px] font-bold uppercase tracking-wide text-danger-text" data-testid="severity-label">{{ t('dashboard.attention_severity_critical') }}</span>
        <span v-else class="sr-only" data-testid="severity-label">{{ t(`dashboard.attention_severity_${sev}`) }}</span>
      </span>
      <template v-if="isOk">
        <span class="text-[14px] font-semibold text-success-text" data-testid="attention-ok-text">{{ okText }}</span>
      </template>
      <template v-else>
        <span class="text-[17px] font-extrabold leading-tight tracking-tight tabular-nums" data-testid="attention-value">{{ valueText }}</span>
        <span v-if="metaText" class="text-[12px] text-muted" data-testid="attention-meta">{{ metaText }}</span>
      </template>
    </span>
    <i v-if="isLink" class="ph-duotone ph-caret-right ml-auto mt-1 shrink-0 text-[14px] text-muted-3" aria-hidden="true"></i>
  </component>
</template>
