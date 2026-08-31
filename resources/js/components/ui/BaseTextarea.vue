<script setup>
import { computed, useId } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  required: { type: Boolean, default: false },
  rows: { type: [String, Number], default: 4 },
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
    <textarea
      :id="id"
      :value="modelValue"
      :placeholder="placeholder"
      :required="required"
      :rows="rows"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="error ? errorId : undefined"
      class="min-h-[84px] resize-y rounded-lg border bg-white px-3.5 py-2.5 text-[13.5px] text-ink outline-none transition-colors placeholder:text-muted-3 focus:border-brand focus:ring-[3px] focus:ring-mint-100"
      :class="error ? 'border-danger-border' : 'border-line'"
      @input="emit('update:modelValue', $event.target.value)"
    ></textarea>
    <span v-if="error" :id="errorId" class="text-[12px] font-medium text-danger-text">{{ error }}</span>
    <span v-else-if="hint" class="text-[11.5px] text-muted-3">{{ hint }}</span>
  </label>
</template>
