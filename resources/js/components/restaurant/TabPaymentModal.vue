<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import PaymentPanel from '../payment/PaymentPanel.vue';
import { formatIDR, parseMoney, toMoneyString } from '../../utils/money';

/**
 * 028 US2 — pembayaran tab, penuh atau sebagian. Memakai PaymentPanel (mode
 * checkout) yang sama dengan kasir ritel; "jumlah dibayar sekarang" menjadi
 * jumlah-jatuh-tempo-nya, sehingga pembayaran sebagian = menurunkan angka itu.
 * Server tetap yang memutuskan: tab hanya selesai bila total yang dibayar ≥ total.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  tab: { type: Object, default: null },
  submitting: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'submit']);
const { t } = useI18n();

const balance = computed(() => parseMoney(props.tab?.balance ?? '0'));
const partial = ref(false);
const draft = ref('');
const applied = ref(0);
const panelRef = ref(null);

// Setiap kali modal dibuka: kembali ke mode "lunasi sisa".
watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    partial.value = false;
    draft.value = '';
    applied.value = balance.value;
    panelRef.value?.reset?.();
  }
);

const due = computed(() => (partial.value ? applied.value : balance.value));
const draftValid = computed(() => {
  const n = Number(draft.value);
  return Number.isFinite(n) && n > 0 && n < balance.value;
});

function applyPartial() {
  if (!draftValid.value) return;
  applied.value = Number(draft.value);
}

function setMode(next) {
  partial.value = next;
  if (next) {
    draft.value = '';
    applied.value = 0;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('floor.tab.pay_title')" max-width-class="max-w-[620px]" @close="emit('close')">
    <div v-if="tab" class="flex flex-col gap-4 px-[26px] py-6">
      <dl class="grid grid-cols-3 gap-3 rounded-card border border-line-3 bg-surface-subtle p-3.5 text-center">
        <div><dt class="text-[11.5px] font-bold uppercase text-muted-3">{{ t('floor.tab.total') }}</dt><dd class="text-[15px] font-bold">{{ formatIDR(tab.total_amount) }}</dd></div>
        <div><dt class="text-[11.5px] font-bold uppercase text-muted-3">{{ t('floor.tab.paid') }}</dt><dd class="text-[15px] font-bold">{{ formatIDR(tab.paid_amount) }}</dd></div>
        <div><dt class="text-[11.5px] font-bold uppercase text-muted-3">{{ t('floor.tab.balance') }}</dt><dd class="text-[15px] font-extrabold text-brand-active">{{ formatIDR(tab.balance) }}</dd></div>
      </dl>

      <div class="flex gap-2" role="radiogroup" :aria-label="t('floor.tab.pay_mode')">
        <button type="button" role="radio" :aria-checked="!partial" class="flex-1 rounded-lg border px-3 py-2 text-[13px] font-bold" :class="!partial ? 'border-brand bg-mint-100 text-brand-active' : 'border-line text-muted-4'" @click="setMode(false)">
          {{ t('floor.tab.pay_full') }}
        </button>
        <button type="button" role="radio" :aria-checked="partial" class="flex-1 rounded-lg border px-3 py-2 text-[13px] font-bold" :class="partial ? 'border-brand bg-mint-100 text-brand-active' : 'border-line text-muted-4'" @click="setMode(true)">
          {{ t('floor.tab.pay_partial') }}
        </button>
      </div>

      <div v-if="partial" class="flex items-end gap-2">
        <label class="flex flex-1 flex-col gap-1.5 text-[12.5px] font-semibold text-muted-4">
          {{ t('floor.tab.pay_amount_now') }}
          <input
            v-model="draft"
            type="number"
            min="1"
            :max="balance - 1"
            inputmode="numeric"
            class="h-[42px] rounded-lg border border-line bg-white px-3.5 text-[14px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
            @keydown.enter.prevent="applyPartial"
          />
        </label>
        <BaseButton variant="secondary" :disabled="!draftValid" @click="applyPartial">{{ t('floor.tab.pay_apply') }}</BaseButton>
      </div>
      <p v-if="partial" class="text-[12px] text-muted-3">{{ t('floor.tab.pay_partial_hint') }}</p>

      <PaymentPanel
        v-if="due > 0"
        :key="`${partial}-${due}`"
        ref="panelRef"
        mode="checkout"
        :due-amount="toMoneyString(due)"
        :submitting="submitting"
        :submit-label="t('floor.tab.pay_confirm')"
        @submit="(payments) => emit('submit', payments)"
      />
    </div>
  </BaseModal>
</template>
