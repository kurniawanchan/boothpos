<script setup>
import { useI18n } from 'vue-i18n';
import TicketCard from './TicketCard.vue';
import EmptyState from '../ui/EmptyState.vue';

/** 028 US4 — papan tiket sebuah stasiun: yang sedang berjalan dulu (tertua di depan), lalu yang baru selesai. */
defineProps({
  active: { type: Array, default: () => [] },
  recent: { type: Array, default: () => [] },
  now: { type: Number, default: () => Date.now() },
  canCook: { type: Boolean, default: false },
  canServe: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  loaded: { type: Boolean, default: false },
});
const emit = defineEmits(['status', 'print']);
const { t } = useI18n();
</script>

<template>
  <div class="flex flex-col gap-6">
    <section :aria-label="t('kitchen.active_section')" class="flex flex-col gap-2.5">
      <EmptyState v-if="loaded && !active.length" icon="ph-cooking-pot" :message="t('kitchen.no_active')" />
      <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-3">
        <TicketCard
          v-for="ticket in active"
          :key="ticket.id"
          :ticket="ticket"
          :now="now"
          :can-cook="canCook"
          :can-serve="canServe"
          :busy="busy"
          @status="(item, next) => emit('status', item, next)"
          @print="emit('print', $event)"
        />
      </div>
    </section>

    <section v-if="recent.length" :aria-label="t('kitchen.recent_section')" class="flex flex-col gap-2.5">
      <h2 class="text-[13px] font-extrabold uppercase tracking-wide text-muted-3">{{ t('kitchen.recent_section') }}</h2>
      <div class="grid grid-cols-[repeat(auto-fill,minmax(300px,1fr))] gap-3 opacity-80">
        <TicketCard
          v-for="ticket in recent"
          :key="ticket.id"
          :ticket="ticket"
          :now="now"
          :busy="busy"
          @print="emit('print', $event)"
        />
      </div>
    </section>
  </div>
</template>
