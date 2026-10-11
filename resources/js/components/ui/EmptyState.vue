<script setup>
// 041-back-office-ui-redesign — `hint` ditambahkan (kalimat kedua yang menjelaskan fungsi halaman) dan
// lebar pesan diperlebar; slot default tetap untuk tindakan utama ("offers the primary action", spec US2).
defineProps({
  icon: { type: String, default: 'ph-tray' },
  message: { type: String, required: true },
  hint: { type: String, default: '' },
});
</script>

<template>
  <div class="flex flex-col items-center gap-2.5 px-5 py-14 text-center">
    <i class="ph-duotone text-[34px] text-disabled" :class="icon" aria-hidden="true"></i>
    <p class="max-w-[280px] text-[13px] leading-relaxed text-muted-3">{{ message }}</p>
    <p v-if="hint" class="max-w-[320px] text-[12px] leading-relaxed text-muted-2">{{ hint }}</p>
    <slot />
  </div>
</template>
