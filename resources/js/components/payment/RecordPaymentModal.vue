<script setup>
import BaseModal from '../ui/BaseModal.vue';
import PaymentPanel from './PaymentPanel.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  dueAmount: { type: String, required: true },
  purpose: { type: String, default: 'down_payment' }, // down_payment | settlement
  submitting: { type: Boolean, default: false },
  title: { type: String, default: 'Catat pembayaran' },
});
const emit = defineEmits(['close', 'submit']);

// PaymentPanel is purpose-agnostic (POS orders always send "full") — this
// wrapper is what stamps the preorder-specific purpose onto the payload
// before it reaches the caller.
function handleSubmit(payload) {
  emit('submit', { ...payload, purpose: props.purpose });
}
</script>

<template>
  <BaseModal :open="open" :title="title" max-width-class="max-w-[480px]" @close="emit('close')">
    <div class="px-[26px] py-6">
      <PaymentPanel mode="record" :due-amount="dueAmount" :submitting="submitting" submit-label="Simpan pembayaran" @submit="handleSubmit" />
    </div>
  </BaseModal>
</template>
