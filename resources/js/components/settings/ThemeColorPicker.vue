<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { isColorTooLight } from '../../utils/theme';

const props = defineProps({ modelValue: { type: String, default: '#2f9e6e' } });
const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();
const value = ref(props.modelValue || '#2f9e6e');
watch(() => props.modelValue, (v) => { if (v) value.value = v; });

function onInput(e) {
  value.value = e.target.value;
  emit('update:modelValue', value.value);
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <span class="text-[12.5px] font-semibold text-muted-4">{{ t('settings.theme_accent_color') }}</span>
    <div class="flex items-center gap-3">
      <input
        type="color"
        :value="value"
        class="h-11 w-14 cursor-pointer rounded-md border border-line bg-white p-1"
        :aria-label="t('settings.theme_accent_color')"
        @input="onInput"
      />
      <span class="font-mono text-[13px] text-muted-4">{{ value }}</span>
    </div>
    <p v-if="isColorTooLight(value)" class="flex items-center gap-1.5 text-[12px] font-semibold text-warn-text">
      <i class="ph-duotone ph-warning text-[14px]" aria-hidden="true"></i>
      {{ t('settings.theme_color_too_light_warning') }}
    </p>
  </div>
</template>
