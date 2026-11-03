<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import { getTicket } from '../../api/kitchen';
import { formatDateTime } from '../../utils/date';
import { modifierLabel } from '../../utils/modifierRules';

/**
 * 028 US4 — tampilan cetak tiket (FR-035): memuat tiket UTUH dari server lalu mencetaknya lewat
 * dialog cetak browser. Cetak termal perangkat keras di luar lingkup fitur ini.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  ticketId: { type: [Number, null], default: null },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const ticket = ref(null);
const failed = ref(false);

watch(
  () => [props.open, props.ticketId],
  async () => {
    ticket.value = null;
    failed.value = false;
    if (!props.open || props.ticketId == null) return;
    try {
      ticket.value = await getTicket(props.ticketId);
    } catch {
      failed.value = true;
    }
  },
  { immediate: true }
);

const place = (tk) => (tk.table ? t('floor.tab.table_label', { name: tk.table.name }) : tk.tab_label || t('floor.takeaway'));
// 090 / FR-032 — baris cara layanan + "MANDIRI" di tiket cetak, hanya bila server mengirimnya.
const serviceLine = (tk) => [
  tk.service_type === 'takeaway' ? t('kitchen.service_takeaway') : tk.service_type === 'dine_in' ? t('kitchen.service_dine_in') : null,
  tk.self_service ? t('kitchen.self_service_tag') : null,
].filter(Boolean).join(' · ');
const printNow = () => window.print();
</script>

<template>
  <BaseModal :open="open" :title="t('kitchen.print_title')" max-width-class="max-w-[420px]" @close="emit('close')">
    <div class="px-6 py-5">
      <p v-if="failed" role="alert" class="text-[13px] font-semibold text-danger-text">{{ t('kitchen.print_failed') }}</p>
      <div v-else-if="ticket" id="printable-ticket" class="flex flex-col gap-2 font-mono text-[13px]">
        <div class="text-center text-[15px] font-extrabold uppercase">{{ ticket.station?.name ?? t('kitchen.no_station') }}</div>
        <div class="flex justify-between"><span>{{ place(ticket) }}</span><span>{{ t('kitchen.round', { n: ticket.round_no }) }}</span></div>
        <div class="flex justify-between text-[12px]"><span>{{ ticket.order.order_number }}</span><span>{{ formatDateTime(ticket.sent_at) }}</span></div>
        <div v-if="serviceLine(ticket)" class="text-center text-[13px] font-extrabold uppercase" data-testid="ticket-service-line">{{ serviceLine(ticket) }}</div>
        <hr class="border-t border-dashed border-line" />
        <div v-for="item in ticket.items" :key="item.id" class="flex flex-col">
          <div class="flex gap-2 font-bold"><span>{{ item.qty }}×</span><span class="flex-1" :class="item.status === 'cancelled' ? 'line-through' : ''">{{ item.name }}</span></div>
          <div v-if="item.modifiers.length" class="pl-6 text-[12px]">{{ item.modifiers.map(modifierLabel).join(', ') }}</div>
          <div v-if="item.note" class="pl-6 text-[12px] italic">“{{ item.note }}”</div>
          <div v-if="item.status === 'cancelled'" class="pl-6 text-[12px] font-bold">{{ t('kitchen.cancelled') }}</div>
        </div>
      </div>
      <p v-else class="text-[13px] text-muted-3">{{ t('kitchen.print_loading') }}</p>
    </div>
    <template #footer>
      <div class="no-print flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.close') }}</BaseButton>
        <BaseButton :disabled="!ticket" @click="printNow">
          <i class="ph-duotone ph-printer text-[16px]" aria-hidden="true"></i>
          {{ t('kitchen.print_now') }}
        </BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
