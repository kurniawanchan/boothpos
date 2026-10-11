<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../../stores/settings';
import { formatClock } from '../../utils/dashboardDates';
import AttentionItem from './AttentionItem.vue';
import EmptyState from '../ui/EmptyState.vue';
import ErrorState from '../ui/ErrorState.vue';
import LoadingState from '../ui/LoadingState.vue';

/**
 * 042-dashboard-revamp (US2) — panel "Perlu tindakan": judul, chip jumlah, baris cakupan, dan kisi butir. Memegang
 * keadaannya sendiri (memuat / galat + Coba lagi / siap) sehingga kegagalan panel ini tidak mengosongkan wilayah
 * Dashboard lain; komponen hanya memancarkan `retry`.
 *
 * Chip jumlah memakai nada peringatan bila ada yang perlu tindakan (> 0) dan netral bila 0 — nol adalah nilai,
 * chip tetap tampil. Jam pada baris cakupan memakai ZONA WAKTU TOKO (sama dengan "Diperbarui" di header).
 *
 * @prop {Array} items       items[] dari GET /dashboard/attention
 * @prop {number} count      jumlah butir yang severity-nya bukan `ok`
 * @prop {string} scopeText  mis. "Seluruh toko" (Stage B: nama outlet / "Semua outlet")
 * @prop {string} computedAt stempel waktu ISO dari server
 * @prop {'loading'|'error'|'ready'} state
 */
const props = defineProps({
  items: { type: Array, default: () => [] },
  count: { type: Number, default: 0 },
  scopeText: { type: String, default: '' },
  computedAt: { type: String, default: '' },
  state: { type: String, default: 'loading' },
});
defineEmits(['retry']);
const { t } = useI18n();
const settings = useSettingsStore();

const scopeLine = computed(() => {
  const time = formatClock(props.computedAt, settings.storeTimezone);
  const scope = props.scopeText || t('dashboard.attention_scope_all');
  return time
    ? t('dashboard.attention_scope_line', { scope, time })
    : t('dashboard.attention_scope_line_no_time', { scope });
});
</script>

<template>
  <section class="mx-[26px] mb-5 border-2 border-line bg-white" :aria-label="t('dashboard.attention_title')" data-testid="attention-panel">
    <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b-2 border-line px-5 py-3.5">
      <h2 class="text-[14px] font-bold tracking-tight">{{ t('dashboard.attention_title') }}</h2>
      <span
        v-if="state === 'ready'"
        class="px-1.5 py-px text-[11.5px] font-bold tabular-nums"
        :class="count > 0 ? 'bg-warn-bg text-warn-text' : 'bg-line-7 text-muted-4'"
        data-testid="attention-count"
      >{{ count }}<span class="sr-only"> — {{ t('dashboard.attention_count_sr', { count }) }}</span></span>
      <span v-if="state === 'ready'" class="text-[12.5px] text-muted" data-testid="attention-scope">{{ scopeLine }}</span>
    </header>

    <LoadingState v-if="state === 'loading'" :rows="3" />
    <ErrorState v-else-if="state === 'error'" :message="t('dashboard.attention_load_failed')" @retry="$emit('retry')" />
    <EmptyState v-else-if="items.length === 0" icon="ph-check-circle" :message="t('dashboard.attention_empty')" />
    <div v-else class="grid grid-cols-1 gap-px bg-line lg:grid-cols-2">
      <div v-for="item in items" :key="item.kind" class="min-w-0 bg-white">
        <AttentionItem
          :kind="item.kind"
          :severity="item.severity"
          :count="item.count"
          :amount="item.amount ?? null"
          :meta="item.meta ?? {}"
          :link="item.link ?? null"
          :amount-unknown-count="item.amount_unknown_count ?? 0"
        />
      </div>
    </div>
  </section>
</template>
