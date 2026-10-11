<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * 042-dashboard-revamp — chip perubahan terhadap pembanding ("↑ +6,8%  vs kemarin s.d. 19.30"). Arah TIDAK
 * pernah hanya lewat warna: ada glyph panah, angka bertanda, dan teks tersembunyi "naik/turun" untuk pembaca
 * layar (WCAG 1.4.1).
 *
 * `goodWhen` menentukan warna, bukan arah: untuk penjualan naik = baik, untuk stok menipis naik = BURUK (merah).
 * `value === null` (pembanding 0/tidak ada — server mengirim null, tidak pernah NaN) -> chip netral
 * "tidak ada pembanding", bukan 0%. Nol sungguhan (tidak berubah) -> chip netral dengan tanda datar.
 *
 * @prop {number|null} value   perubahan bertanda (persen atau jumlah item)
 * @prop {'percent'|'items'} kind
 * @prop {'up'|'down'} goodWhen
 */
const props = defineProps({
  value: { type: Number, default: null },
  kind: { type: String, default: 'percent' },
  goodWhen: { type: String, default: 'up' },
  noComparisonText: { type: String, default: '' },
});
const { t } = useI18n();

const percentFmt = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const countFmt = new Intl.NumberFormat('id-ID');

const missing = computed(() => props.value === null || props.value === undefined || !Number.isFinite(props.value));
const direction = computed(() => {
  if (missing.value || props.value === 0) return 'flat';
  return props.value > 0 ? 'up' : 'down';
});
// Pembulatan 0,04% -> "0,0%" tidak boleh tampil sebagai kenaikan hijau; yang ditampilkan itulah yang diwarnai.
const rounded = computed(() => {
  if (missing.value) return 0;
  return props.kind === 'percent' ? Math.round(props.value * 10) / 10 : Math.round(props.value);
});
const shownDirection = computed(() => (rounded.value === 0 ? 'flat' : direction.value));

const tone = computed(() => {
  if (shownDirection.value === 'flat') return 'neutral';
  return shownDirection.value === props.goodWhen ? 'good' : 'bad';
});
const TONES = {
  good: 'bg-success-bg text-success-text',
  bad: 'bg-danger-bg text-danger-text',
  neutral: 'bg-line-3 text-muted-4',
};
const ICONS = { up: 'ph-arrow-up', down: 'ph-arrow-down', flat: 'ph-minus' };

const sign = computed(() => (rounded.value > 0 ? '+' : rounded.value < 0 ? '-' : ''));
const magnitude = computed(() => {
  const abs = Math.abs(rounded.value);
  return props.kind === 'percent' ? `${percentFmt.format(abs)}%` : t('dashboard.change_items', { count: countFmt.format(abs) }, abs);
});
const srText = computed(() => t(`dashboard.change_${shownDirection.value}`));
</script>

<template>
  <span
    v-if="missing"
    data-testid="change-chip"
    data-tone="neutral"
    class="inline-flex items-center bg-line-3 px-1.5 py-0.5 text-[12px] font-semibold text-muted-4"
  >{{ noComparisonText || t('dashboard.no_comparison') }}</span>
  <span
    v-else
    data-testid="change-chip"
    :data-tone="tone"
    class="inline-flex items-center gap-1 px-1.5 py-0.5 text-[12px] font-bold tabular-nums"
    :class="TONES[tone]"
  >
    <i class="ph-bold text-[11px]" :class="ICONS[shownDirection]" aria-hidden="true"></i>
    <span class="sr-only">{{ srText }}</span>
    <span>{{ sign }}{{ magnitude }}</span>
  </span>
</template>
