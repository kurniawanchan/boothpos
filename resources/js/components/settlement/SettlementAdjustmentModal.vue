<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';

// 076 — tambah/ubah potongan (deduction) atau penyesuaian (adjustment).
// Potongan diketik POSITIF (server yang menyimpannya negatif); penyesuaian
// boleh positif/negatif. Tidak ada angka pendapatan di sini — itu selalu dari server.
const props = defineProps({
  open: { type: Boolean, default: false },
  kind: { type: String, default: 'deduction' }, // deduction | adjustment
  adjustment: { type: Object, default: null }, // baris yang diubah, null = baru
  saving: { type: Boolean, default: false },
  errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['save', 'close']);

const { t } = useI18n();

const form = reactive({ label: '', reason: '', basis: '', amount: '' });
const clientError = ref('');

const isEdit = computed(() => props.adjustment !== null);
const title = computed(() => t(`settlements.${isEdit.value ? 'edit' : 'add'}_${props.kind}`));
const amountHint = computed(() => t(`settlements.adj_amount_hint_${props.kind}`));

// Mengisi ulang HANYA saat dialog dibuka (bukan tiap props berubah) agar ketikan tidak terhapus.
watch(
  () => props.open,
  (open) => {
    if (!open) return;
    clientError.value = '';
    if (props.adjustment) {
      form.label = props.adjustment.label;
      form.reason = props.adjustment.reason;
      form.basis = props.adjustment.basis ?? '';
      // Potongan tersimpan negatif; tampilkan positif seperti saat mengetiknya.
      form.amount = String(props.kind === 'deduction' ? Math.abs(Number(props.adjustment.amount)) : Number(props.adjustment.amount));
    } else {
      Object.assign(form, { label: '', reason: '', basis: '', amount: '' });
    }
  },
  { immediate: true },
);

function submit() {
  clientError.value = '';
  if (form.amount === '' || Number(form.amount) === 0 || Number.isNaN(Number(form.amount))) {
    clientError.value = t('settlements.adj_amount_required');
    return;
  }
  emit('save', {
    kind: props.kind,
    label: form.label.trim(),
    reason: form.reason.trim(),
    basis: form.basis.trim() || null,
    amount: String(form.amount),
  });
}
</script>

<template>
  <BaseModal :open="open" :title="title" @close="emit('close')">
    <form id="settlement-adjustment-form" class="flex flex-col gap-4 px-6 py-5" @submit.prevent="submit">
      <BaseInput v-model="form.label" :label="t('settlements.adj_label')" required maxlength="120" :error="errors.label" data-autofocus />
      <BaseTextarea v-model="form.reason" :label="t('settlements.adj_reason')" required :rows="2" :error="errors.reason" />
      <BaseInput v-model="form.basis" :label="t('settlements.adj_basis')" :hint="t('settlements.adj_basis_hint')" maxlength="160" :error="errors.basis" />
      <BaseInput
        v-model="form.amount"
        type="number"
        step="0.01"
        :min="kind === 'deduction' ? '0' : undefined"
        :label="t('settlements.adj_amount')"
        :hint="amountHint"
        required
        :error="clientError || errors.amount"
      />
    </form>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :loading="saving" @click="submit">{{ t('common.save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
