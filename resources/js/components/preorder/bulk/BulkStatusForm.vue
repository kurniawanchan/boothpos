<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseSelect from '../../ui/BaseSelect.vue';
import BaseTextarea from '../../ui/BaseTextarea.vue';

/** 065 — pilih SATU status tujuan; hanya status yang bisa dituju ≥ 1 pre-order terpilih yang ditawarkan (dengan jumlahnya). */
const props = defineProps({
  modelValue: { type: Object, required: true }, // { status, cancel_reason }
  options: { type: Array, default: () => [] }, // [{ status, count }]
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const STATUS_KEY = {
  dp_paid: 'preorders.step_dp_paid', arrived: 'preorders.step_arrived', settled: 'preorders.step_settled',
  handed_over: 'preorders.step_handed_over', cancelled: 'events_sessions.status_cancelled',
};
const selectOptions = computed(() =>
  props.options.map((o) => ({ value: o.status, label: t('preorder_bulk.status_option', { status: t(STATUS_KEY[o.status]), count: o.count }) })),
);

function set(patch) {
  emit('update:modelValue', { ...props.modelValue, ...patch });
}
</script>

<template>
  <div class="flex flex-col gap-3" data-testid="bulk-status-form">
    <p class="text-[13px] text-muted-4">{{ t('preorder_bulk.hint_status') }}</p>
    <p v-if="options.length === 0" class="text-[12.5px] font-medium text-warn-text" data-testid="bulk-status-none">{{ t('preorder_bulk.status_none') }}</p>
    <BaseSelect
      v-else
      :model-value="modelValue.status"
      :label="t('preorder_bulk.status_label')"
      :options="selectOptions"
      required
      @update:model-value="(v) => set({ status: v })"
    />
    <BaseTextarea
      v-if="modelValue.status === 'cancelled'"
      :model-value="modelValue.cancel_reason"
      :label="t('preorder_bulk.cancel_reason')"
      :rows="2"
      required
      @update:model-value="(v) => set({ cancel_reason: v })"
    />
  </div>
</template>
