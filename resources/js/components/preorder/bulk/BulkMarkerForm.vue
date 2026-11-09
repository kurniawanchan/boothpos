<script setup>
import { useI18n } from 'vue-i18n';

/** 065 — penanda progres (invoice terkirim / belum / pengiriman berjalan) = satu aksi `dispatch` dengan tiga nilai. */
const props = defineProps({ modelValue: { type: Object, required: true } }); // { dispatch_status }
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();
const choices = ['invoice_sent', 'pending', 'shipping'];
</script>

<template>
  <div class="flex flex-col gap-3" data-testid="bulk-marker-form">
    <p class="text-[13px] text-muted-4">{{ t('preorder_bulk.hint_dispatch') }}</p>
    <div role="radiogroup" :aria-label="t('preorder_bulk.marker_label')" class="flex flex-col gap-2">
      <label
        v-for="c in choices"
        :key="c"
        class="flex cursor-pointer items-center gap-2.5 rounded-lg border px-3.5 py-2.5 text-[13.5px] font-semibold"
        :class="modelValue.dispatch_status === c ? 'border-brand bg-mint-50 text-brand-active' : 'border-line bg-white text-muted-5'"
      >
        <input
          type="radio"
          class="h-4 w-4"
          name="bulk-marker"
          :value="c"
          :checked="modelValue.dispatch_status === c"
          :data-testid="`marker-${c}`"
          @change="emit('update:modelValue', { dispatch_status: c })"
        />
        {{ t(`preorder_bulk.marker_${c}`) }}
      </label>
    </div>
  </div>
</template>
