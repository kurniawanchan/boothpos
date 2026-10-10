<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import StatusPill from '../ui/StatusPill.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 028 US4 — satu tiket di layar stasiun: meja/label, nomor tab, ronde, lama menunggu,
 * dan item-itemnya dengan tombol aksi menurut status. Hanya tampilan + niat pengguna;
 * perpindahan status dikirim ke induk yang memanggil API (server yang memutuskan).
 */
const props = defineProps({
  ticket: { type: Object, required: true },
  now: { type: Number, default: () => Date.now() }, // ms — dari useNow()
  canCook: { type: Boolean, default: false }, // kunci `kitchen`: boleh mulai/siap
  canServe: { type: Boolean, default: false }, // `floor` ATAU `kitchen`: boleh tandai diantar
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(['status', 'print']);
const { t } = useI18n();

const place = computed(() => (props.ticket.table ? t('floor.tab.table_label', { name: props.ticket.table.name }) : props.ticket.tab_label || t('floor.takeaway')));
const waitingMinutes = computed(() => Math.max(0, Math.floor((props.now - new Date(props.ticket.sent_at).getTime()) / 60000)));
const late = computed(() => waitingMinutes.value >= 15 && props.ticket.items.some((i) => ['new', 'preparing'].includes(i.status)));

const VARIANT = { new: 'neutral', preparing: 'warn', ready: 'mint', served: 'neutral', cancelled: 'danger' };
const NEXT = { new: 'preparing', preparing: 'ready', ready: 'served' };

function actionFor(item) {
  const next = NEXT[item.status];
  if (!next) return null;
  if (next === 'served' ? !props.canServe : !props.canCook) return null;
  return next;
}
</script>

<template>
  <article
    class="flex flex-col gap-2.5 rounded-card border bg-white p-3.5"
    :class="late ? 'border-warn-border-strong' : 'border-line'"
    :data-testid="`ticket-${ticket.id}`"
  >
    <header class="flex items-start justify-between gap-2">
      <div class="min-w-0">
        <h3 class="truncate text-[17px] font-extrabold text-ink">{{ place }}</h3>
        <div class="text-[11.5px] font-semibold text-muted-3">{{ ticket.order.order_number }} · {{ t('kitchen.round', { n: ticket.round_no }) }}</div>
      </div>
      <div class="flex items-center gap-1.5">
        <StatusPill :variant="late ? 'warn' : 'neutral'">{{ t('kitchen.waiting', { n: waitingMinutes }) }}</StatusPill>
        <button
          type="button"
          class="flex h-8 w-8 items-center justify-center rounded-lg border border-line-2 text-muted-4 hover:text-brand-active"
          :aria-label="t('kitchen.print_ticket', { place })"
          @click="emit('print', ticket)"
        >
          <i class="ph-duotone ph-printer text-[15px]" aria-hidden="true"></i>
        </button>
      </div>
    </header>

    <ul class="flex flex-col divide-y divide-line-3">
      <li v-for="item in ticket.items" :key="item.id" class="flex items-start gap-2.5 py-2" :data-testid="`ticket-item-${item.id}`">
        <span class="min-w-[26px] text-[15px] font-bold text-brand-active">{{ item.qty }}×</span>
        <div class="min-w-0 flex-1" :class="item.status === 'cancelled' ? 'opacity-70' : ''">
          <div class="text-[14px] font-bold leading-snug" :class="item.status === 'cancelled' ? 'text-danger-text line-through' : 'text-ink'">{{ item.name }}</div>
          <div v-if="item.modifiers.length" class="text-[12px] text-muted-4">{{ item.modifiers.map((m) => m.option).join(', ') }}</div>
          <div v-if="item.note" class="text-[12px] font-semibold italic text-warn-text">“{{ item.note }}”</div>
          <div v-if="item.status === 'cancelled'" class="text-[12px] font-bold text-danger-text">
            {{ t('kitchen.cancelled') }}<template v-if="item.cancel_reason"> — {{ item.cancel_reason }}</template>
          </div>
        </div>
        <div class="flex flex-col items-end gap-1.5">
          <StatusPill :variant="VARIANT[item.status]">{{ t(`kitchen.status_${item.status}`) }}</StatusPill>
          <BaseButton v-if="actionFor(item)" size="sm" :disabled="busy" @click="emit('status', item, actionFor(item))">
            {{ t(`kitchen.action_${actionFor(item)}`) }}
          </BaseButton>
        </div>
      </li>
    </ul>
  </article>
</template>
