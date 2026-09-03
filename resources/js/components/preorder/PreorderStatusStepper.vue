<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const STEPS = computed(() => [
  { value: 'ordered', label: t('preorders.step_ordered') },
  { value: 'dp_paid', label: t('preorders.step_dp_paid') },
  { value: 'arrived', label: t('preorders.step_arrived') },
  { value: 'settled', label: t('preorders.step_settled') },
  { value: 'handed_over', label: t('preorders.step_handed_over') },
]);

const props = defineProps({ status: { type: String, required: true } });

const currentIndex = computed(() => STEPS.value.findIndex((s) => s.value === props.status));
</script>

<template>
  <div v-if="status === 'cancelled'" class="flex items-center gap-2.5 rounded-lg border border-danger-border bg-danger-bg px-4 py-3 text-[13px] font-bold text-danger-text">
    <i class="ph-duotone ph-x-circle text-[19px]" aria-hidden="true"></i>
    {{ t('preorders.cancelled') }}
  </div>
  <ol v-else class="flex items-start" :aria-label="t('preorders.status_steps_aria')">
    <li v-for="(step, idx) in STEPS" :key="step.value" class="flex flex-1 items-start">
      <div class="flex w-[88px] flex-none flex-col items-center gap-2">
        <span
          class="flex h-7 w-7 items-center justify-center rounded-full text-[12px] font-bold"
          :class="idx <= currentIndex ? 'bg-brand text-white' : 'bg-line-7 text-muted-3'"
          :aria-current="idx === currentIndex ? 'step' : undefined"
        >
          {{ idx < currentIndex ? '✓' : idx + 1 }}
        </span>
        <span class="text-center text-[11px] font-semibold" :class="idx <= currentIndex ? 'text-ink' : 'text-muted-3'">{{ step.label }}</span>
      </div>
      <div v-if="idx < STEPS.length - 1" class="mt-3.5 h-[2px] flex-1" :class="idx < currentIndex ? 'bg-brand' : 'bg-line-2'"></div>
    </li>
  </ol>
</template>
