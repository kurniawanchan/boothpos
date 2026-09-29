<script setup>
import { computed, useId } from 'vue';

// 024-invoice-layout-shipping-slip — BUG YANG DITEMUKAN: root komponen ini
// adalah <label>, jadi listener non-bubbling seperti @focus/@blur yang
// dipasang pemanggil (mis. PreordersView.vue) sebelumnya jatuh ke <label>
// lewat fallthrough attrs bawaan Vue dan TIDAK PERNAH terpicu (focus/blur
// tidak bubble, beda dari @input yang bubble sehingga terlihat "berfungsi").
// inheritAttrs: false + v-bind="$attrs" eksplisit di <input> memindahkan
// SEMUA attrs/listener yang tidak dikenal (termasuk @focus/@blur) ke
// elemen <input> yang sesungguhnya.
defineOptions({ inheritAttrs: false });

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  label: { type: String, default: '' },
  type: { type: String, default: 'text' },
  placeholder: { type: String, default: '' },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
  autocomplete: { type: String, default: undefined },
  min: { type: [String, Number], default: undefined },
  max: { type: [String, Number], default: undefined },
  step: { type: [String, Number], default: undefined },
  maxlength: { type: [String, Number], default: undefined },
});
const emit = defineEmits(['update:modelValue']);

const id = useId();
const errorId = computed(() => `${id}-error`);
const hintId = computed(() => `${id}-hint`);
</script>

<template>
  <label :for="id" class="flex flex-col gap-1.5">
    <span v-if="label" class="text-[12.5px] font-semibold text-muted-4">
      {{ label }}<span v-if="required" class="text-danger-text" aria-hidden="true"> *</span>
    </span>
    <input
      v-bind="$attrs"
      :id="id"
      :type="type"
      :value="modelValue"
      :placeholder="placeholder"
      :disabled="disabled"
      :readonly="readonly"
      :required="required"
      :autocomplete="autocomplete"
      :min="min"
      :max="max"
      :step="step"
      :maxlength="maxlength"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="error ? errorId : hint ? hintId : undefined"
      class="h-[46px] rounded-lg border bg-white px-3.5 text-[14.5px] text-ink outline-none transition-colors placeholder:text-muted-3 disabled:bg-line-5 disabled:text-muted-3 focus:border-brand focus:ring-[3px] focus:ring-mint-100"
      :class="error ? 'border-danger-border' : 'border-line'"
      @input="emit('update:modelValue', $event.target.value)"
    />
    <span v-if="error" :id="errorId" class="text-[12px] font-medium text-danger-text">{{ error }}</span>
    <span v-else-if="hint" :id="hintId" class="text-[11.5px] text-muted-3">{{ hint }}</span>
  </label>
</template>
