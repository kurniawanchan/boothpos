<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import StatusPill from '../ui/StatusPill.vue';

/**
 * 028 — satu ubin meja (atau tab takeaway) pada layar Lantai. Hanya tampilan:
 * semua angka datang dari server, tidak ada perhitungan harga di sini.
 */
const props = defineProps({
  table: { type: Object, required: true }, // { name, seats?, state, tab }
  selectable: { type: Boolean, default: false },
});
const emit = defineEmits(['select']);
const { t } = useI18n();

const occupied = computed(() => props.table.state === 'occupied');
const tab = computed(() => props.table.tab ?? null);
const tag = computed(() => (props.selectable ? 'button' : 'div'));
</script>

<template>
  <component
    :is="tag"
    :type="selectable ? 'button' : undefined"
    class="flex min-h-[112px] flex-col gap-2 rounded-card border p-3.5 text-left transition-colors"
    :class="[
      occupied ? 'border-warn-border bg-warn-bg' : 'border-line bg-white',
      selectable ? 'cursor-pointer hover:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-mint-100' : '',
    ]"
    :data-testid="`table-${table.name}`"
    :data-state="table.state"
    @click="selectable && emit('select', table)"
  >
    <div class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <div class="truncate text-[18px] font-extrabold text-ink">{{ table.name }}</div>
        <div v-if="table.seats" class="text-[12px] font-semibold text-muted-3">{{ t('floor.seats', { n: table.seats }) }}</div>
      </div>
      <StatusPill :variant="occupied ? 'warn' : 'neutral'">{{ occupied ? t('floor.occupied') : t('floor.free') }}</StatusPill>
    </div>

    <template v-if="tab">
      <div class="text-[11.5px] font-semibold text-muted-3">{{ tab.order_number }}</div>
      <div class="text-[15px] font-bold text-ink">{{ formatIDR(tab.total_amount) }}</div>
      <div v-if="tab.ready_count || tab.unsent_count" class="flex flex-wrap gap-1.5">
        <StatusPill v-if="tab.ready_count" variant="mint">
          <i class="ph-duotone ph-bell-ringing text-[13px]" aria-hidden="true"></i>
          {{ t('floor.items_ready', { n: tab.ready_count }) }}
        </StatusPill>
        <StatusPill v-if="tab.unsent_count" variant="neutral">{{ t('floor.items_unsent', { n: tab.unsent_count }) }}</StatusPill>
      </div>
    </template>
  </component>
</template>
