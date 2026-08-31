<script setup>
defineProps({
  icon: { type: String, default: 'ph-tray' },
  message: { type: String, required: true },
});
</script>

<template>
  <div class="flex flex-col items-center gap-2.5 px-5 py-14 text-center">
    <i class="ph-duotone text-[34px] text-disabled" :class="icon" aria-hidden="true"></i>
    <p class="max-w-[220px] text-[13px] leading-relaxed text-muted-3">{{ message }}</p>
    <slot />
  </div>
</template>
