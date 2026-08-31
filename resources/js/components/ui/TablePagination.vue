<script setup>
import { computed } from 'vue';

const props = defineProps({ meta: { type: Object, required: true } });
const emit = defineEmits(['change']);

const rangeStart = computed(() => (props.meta.total === 0 ? 0 : (props.meta.current_page - 1) * props.meta.per_page + 1));
const rangeEnd = computed(() => Math.min(props.meta.current_page * props.meta.per_page, props.meta.total));
</script>

<template>
  <div class="flex items-center justify-between border-t border-line-3 bg-surface-subtle px-4 py-3">
    <span class="text-[12px] text-muted-3">Menampilkan {{ rangeStart }}–{{ rangeEnd }} dari {{ meta.total }}</span>
    <div class="flex gap-1.5">
      <button
        type="button"
        :disabled="meta.current_page <= 1"
        class="flex h-8 w-8 items-center justify-center rounded-md border border-line text-muted-2 transition-colors hover:border-brand hover:text-brand-active disabled:cursor-not-allowed disabled:opacity-40"
        aria-label="Halaman sebelumnya"
        @click="emit('change', meta.current_page - 1)"
      >
        <i class="ph-duotone ph-caret-left text-[14px]" aria-hidden="true"></i>
      </button>
      <span class="flex h-8 items-center px-2 text-[12px] font-semibold text-muted-4">{{ meta.current_page }} / {{ Math.max(meta.last_page, 1) }}</span>
      <button
        type="button"
        :disabled="meta.current_page >= meta.last_page"
        class="flex h-8 w-8 items-center justify-center rounded-md border border-line text-muted-2 transition-colors hover:border-brand hover:text-brand-active disabled:cursor-not-allowed disabled:opacity-40"
        aria-label="Halaman berikutnya"
        @click="emit('change', meta.current_page + 1)"
      >
        <i class="ph-duotone ph-caret-right text-[14px]" aria-hidden="true"></i>
      </button>
    </div>
  </div>
</template>
