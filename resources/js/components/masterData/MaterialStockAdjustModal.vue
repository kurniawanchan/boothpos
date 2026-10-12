<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { adjustMaterialStock } from '../../api/materials';
import { useOutletStore } from '../../stores/outlet';
import { useToastStore } from '../../stores/toast';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseSelect from '../ui/BaseSelect.vue';

/**
 * 045 Stage 5 (T081) — koreksi stok bahan di SATU outlet (selisih, bukan jumlah akhir). Outlet dipilih hanya bila ada
 * lebih dari satu (bawaan: outlet yang sedang dipilih di pemilih outlet); di satu outlet server memakai outlet itu.
 * Server menolak saldo negatif (422) dan outlet di luar yang boleh dilihat (403) — kesalahannya ditampilkan di kolomnya.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  material: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);
const { t } = useI18n();
const toast = useToastStore();
const outletStore = useOutletStore();

const multi = computed(() => outletStore.multi);
const form = reactive({ outlet_id: '', qty_change: '', reason: '' });
const errors = reactive({});
const saving = ref(false);
const outletOptions = computed(() => outletStore.outlets.map((o) => ({ value: o.id, label: o.name })));
const qty = computed(() => Number(form.qty_change));
const valid = computed(() => Number.isFinite(qty.value) && qty.value !== 0 && form.reason.trim() !== '' && (!multi.value || !!form.outlet_id));

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    Object.assign(form, { outlet_id: multi.value ? (outletStore.selected ?? '') : '', qty_change: '', reason: '' });
    Object.keys(errors).forEach((k) => delete errors[k]);
  },
  { immediate: true }
);

async function submit() {
  if (!valid.value || saving.value) return;
  saving.value = true;
  Object.keys(errors).forEach((k) => delete errors[k]);
  try {
    await adjustMaterialStock(props.material.id, {
      ...(multi.value ? { outlet_id: form.outlet_id } : {}),
      qty_change: qty.value,
      reason: form.reason.trim(),
    });
    toast.success(t('stock.material_adjust_saved'));
    emit('saved');
    emit('close');
  } catch (err) {
    if (err?.isValidation) Object.assign(errors, Object.fromEntries(Object.entries(err.errors ?? {}).map(([k, v]) => [k, v[0]])));
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open && !!material" :title="t('stock.material_adjust_title', { name: material?.name ?? '' })" max-width-class="max-w-[460px]" @close="emit('close')">
    <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="submit">
      <BaseSelect
        v-if="multi"
        v-model="form.outlet_id"
        :label="t('stock.adjust_outlet')"
        :placeholder="t('stock.adjust_outlet_placeholder')"
        :options="outletOptions"
        :error="errors.outlet_id"
        data-testid="material-adjust-outlet"
      />
      <BaseInput v-model="form.qty_change" type="number" step="any" :label="t('stock.material_adjust_qty')" :error="errors.qty_change" data-autofocus />
      <BaseInput v-model="form.reason" :label="t('stock.material_adjust_reason')" maxlength="255" :error="errors.reason" />
    </form>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :disabled="!valid" :loading="saving" @click="submit">{{ t('common.save') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
