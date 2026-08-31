<script setup>
import { ref, toRef } from 'vue';
import { useFocusTrap } from '../../composables/useFocusTrap';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  maxWidthClass: { type: String, default: 'max-w-[520px]' },
});
const emit = defineEmits(['close']);

const dialogRef = ref(null);
useFocusTrap(dialogRef, toRef(props, 'open'));

function onKeydown(e) {
  if (e.key === 'Escape') emit('close');
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[90] flex items-center justify-center bg-ink/40 p-7"
      @keydown="onKeydown"
      @click.self="emit('close')"
    >
      <div
        ref="dialogRef"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
        tabindex="-1"
        class="flex max-h-[90vh] w-full flex-col overflow-hidden rounded-modal bg-white shadow-2xl"
        :class="maxWidthClass"
      >
        <div v-if="title || $slots.header" class="flex items-start justify-between gap-3 border-b border-line-3 px-6 py-5">
          <slot name="header">
            <h2 class="text-[19px] font-bold tracking-tight">{{ title }}</h2>
          </slot>
          <button
            type="button"
            class="flex h-[34px] w-[34px] flex-none items-center justify-center rounded-lg border border-line-2 bg-white text-muted transition-colors hover:border-danger-border-hover hover:text-danger-text"
            aria-label="Tutup dialog"
            @click="emit('close')"
          >
            <i class="ph-duotone ph-x text-[16px]" aria-hidden="true"></i>
          </button>
        </div>
        <div class="flex-1 overflow-auto">
          <slot />
        </div>
        <div v-if="$slots.footer" class="border-t border-line-3 bg-surface-subtle px-6 py-4">
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
