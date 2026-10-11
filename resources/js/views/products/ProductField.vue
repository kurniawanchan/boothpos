<script setup>
import { useId } from 'vue';

/**
 * 043 — satu kolom formulir item: label (+ bintang wajib), isi, lalu galat ATAU petunjuk. `id` diberikan ke slot
 * supaya label benar-benar terhubung ke kontrolnya (getByLabelText / pembaca layar). Kontrolnya sendiri memakai
 * elemen bawaan + `formStyle.js` agar tinggi dan garisnya sama dengan desain.
 */
defineProps({
  label: { type: String, required: true },
  required: { type: Boolean, default: false },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
});
const id = useId();
</script>

<template>
  <div class="flex min-w-0 flex-col gap-1.5">
    <label :for="id" class="text-[12.5px] font-semibold text-muted-5">
      {{ label }}<span v-if="required" class="text-danger-text" aria-hidden="true"> *</span>
    </label>
    <slot :id="id" />
    <p v-if="error" class="text-[12px] font-medium text-danger-text">{{ error }}</p>
    <p v-else-if="hint" class="text-[11.5px] text-muted-3">{{ hint }}</p>
  </div>
</template>
