<script setup>
import { computed, useId } from 'vue';

const props = defineProps({
  modelValue: { type: [String, Number, null], default: '' },
  label: { type: String, default: '' },
  options: { type: Array, default: () => [] }, // [{ value, label }]
  placeholder: { type: String, default: 'Pilih…' },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const id = useId();
const errorId = computed(() => `${id}-error`);
</script>

<template>
  <label :for="id" class="flex flex-col gap-1.5">
    <span v-if="label" class="text-[12.5px] font-semibold text-muted-4">
      {{ label }}<span v-if="required" class="text-danger-text" aria-hidden="true"> *</span>
    </span>
    <select
      :id="id"
      :value="modelValue"
      :disabled="disabled"
      :required="required"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="error ? errorId : undefined"
      class="h-[46px] rounded-lg border bg-white px-3.5 text-[14.5px] text-ink outline-none transition-colors disabled:bg-line-5 disabled:text-muted-3 focus:border-brand focus:ring-[3px] focus:ring-mint-100"
      :class="error ? 'border-danger-border' : 'border-line'"
      @change="emit('update:modelValue', $event.target.value)"
    >
      <option v-if="placeholder" value="" disabled :selected="!modelValue">{{ placeholder }}</option>
      <option v-for="opt in options" :key="opt.value" :value="opt.value" :selected="opt.value == modelValue">
        {{ opt.label }}
      </option>
    </select>
    <span v-if="error" :id="errorId" class="text-[12px] font-medium text-danger-text">{{ error }}</span>
    <span v-else-if="hint" class="text-[11.5px] text-muted-3">{{ hint }}</span>
  </label>
</template>
