<script setup>
import { RouterView } from 'vue-router';
import ToastStack from './components/ui/ToastStack.vue';
import { useBranding } from './composables/useBranding';

// 076 (US4) — judul tab & favicon mengikuti identitas toko di semua rute.
useBranding();
</script>

<template>
  <RouterView />
  <ToastStack />
</template>
