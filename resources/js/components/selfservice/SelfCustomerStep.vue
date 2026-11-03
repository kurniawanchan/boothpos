<script setup>
import { useI18n } from 'vue-i18n';

// 090 / US4 — data diri opsional: "Lanjut sebagai tamu" (bawaan) atau isi nama, telepon, surel. Nama dan telepon wajib bila
// mengisi. Ada pemberitahuan singkat kegunaan data. `modelValue` = { mode, name, phone, email } milik store (hanya memori).
const props = defineProps({
  modelValue: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const set = (patch) => emit('update:modelValue', { ...props.modelValue, ...patch });
const errorText = (key) => (props.errors[key] ? t(`self_service.customer_error_${key}`) : '');
</script>

<template>
  <fieldset class="flex flex-col gap-3" data-testid="customer-step">
    <legend class="text-[14px] font-extrabold text-ink">{{ t('self_service.customer_title') }}</legend>

    <div class="grid grid-cols-2 gap-2" role="radiogroup" :aria-label="t('self_service.customer_title')">
      <button
        type="button" role="radio" :aria-checked="modelValue.mode === 'guest'" data-testid="customer-guest"
        class="h-12 border text-[14px] font-bold"
        :class="modelValue.mode === 'guest' ? 'border-brand bg-brand text-white' : 'border-line bg-surface-soft text-muted-5'"
        @click="set({ mode: 'guest' })"
      >{{ t('self_service.customer_guest') }}</button>
      <button
        type="button" role="radio" :aria-checked="modelValue.mode === 'details'" data-testid="customer-details"
        class="h-12 border text-[14px] font-bold"
        :class="modelValue.mode === 'details' ? 'border-brand bg-brand text-white' : 'border-line bg-surface-soft text-muted-5'"
        @click="set({ mode: 'details' })"
      >{{ t('self_service.customer_details') }}</button>
    </div>

    <div v-if="modelValue.mode === 'details'" class="flex flex-col gap-3">
      <label class="flex flex-col gap-1.5">
        <span class="text-[12.5px] font-semibold text-muted-4">{{ t('self_service.customer_name') }} <span class="text-danger-text" aria-hidden="true">*</span></span>
        <input
          type="text" autocomplete="name" maxlength="100" required :value="modelValue.name" data-testid="customer-name"
          class="h-12 w-full border bg-surface-soft px-3 text-[14.5px] outline-none focus:border-brand" :class="errors.name ? 'border-danger-border' : 'border-line'"
          :aria-invalid="errors.name ? 'true' : undefined" @input="set({ name: $event.target.value })"
        />
        <span v-if="errors.name" class="text-[12px] font-medium text-danger-text" role="alert">{{ errorText('name') }}</span>
      </label>
      <label class="flex flex-col gap-1.5">
        <span class="text-[12.5px] font-semibold text-muted-4">{{ t('self_service.customer_phone') }} <span class="text-danger-text" aria-hidden="true">*</span></span>
        <input
          type="tel" inputmode="tel" autocomplete="tel" maxlength="30" required :value="modelValue.phone" data-testid="customer-phone"
          class="h-12 w-full border bg-surface-soft px-3 text-[14.5px] outline-none focus:border-brand" :class="errors.phone ? 'border-danger-border' : 'border-line'"
          :aria-invalid="errors.phone ? 'true' : undefined" @input="set({ phone: $event.target.value })"
        />
        <span v-if="errors.phone" class="text-[12px] font-medium text-danger-text" role="alert">{{ errorText('phone') }}</span>
      </label>
      <label class="flex flex-col gap-1.5">
        <span class="text-[12.5px] font-semibold text-muted-4">{{ t('self_service.customer_email') }}</span>
        <input
          type="email" inputmode="email" autocomplete="email" maxlength="150" :value="modelValue.email" data-testid="customer-email"
          class="h-12 w-full border bg-surface-soft px-3 text-[14.5px] outline-none focus:border-brand" :class="errors.email ? 'border-danger-border' : 'border-line'"
          :aria-invalid="errors.email ? 'true' : undefined" @input="set({ email: $event.target.value })"
        />
        <span v-if="errors.email" class="text-[12px] font-medium text-danger-text" role="alert">{{ errorText('email') }}</span>
      </label>
    </div>

    <p class="text-[11.5px] leading-relaxed text-muted" data-testid="customer-privacy">{{ t('self_service.customer_privacy') }}</p>
  </fieldset>
</template>
