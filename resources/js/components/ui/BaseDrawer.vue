<script setup>
import { ref, toRef } from 'vue';
import { useFocusTrap } from '../../composables/useFocusTrap';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  subtitle: { type: String, default: '' },
  maxWidthClass: { type: String, default: 'max-w-[820px]' },
});
const emit = defineEmits(['close']);

const panelRef = ref(null);
useFocusTrap(panelRef, toRef(props, 'open'));

function onKeydown(e) {
  if (e.key === 'Escape') emit('close');
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[85] flex items-stretch justify-end bg-ink/40" @keydown="onKeydown">
      <div
        ref="panelRef"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
        tabindex="-1"
        class="flex w-full flex-col bg-surface shadow-2xl"
        :class="maxWidthClass"
      >
        <div class="flex items-start justify-between gap-3.5 border-b border-line-2 bg-white px-[26px] py-[18px]">
          <slot name="header">
            <div class="flex flex-col gap-0.5">
              <h2 class="text-[19px] font-bold tracking-tight">{{ title }}</h2>
              <p v-if="subtitle" class="text-[12.5px] text-muted-2">{{ subtitle }}</p>
            </div>
          </slot>
          <button
            type="button"
            class="flex h-[34px] w-[34px] flex-none items-center justify-center rounded-lg border border-line-2 bg-white text-muted transition-colors hover:border-danger-border-hover hover:text-danger-text"
            aria-label="Tutup"
            @click="emit('close')"
          >
            <i class="ph-duotone ph-x text-[16px]" aria-hidden="true"></i>
          </button>
        </div>
        <div class="flex-1 overflow-auto px-[26px] py-6">
          <slot />
        </div>
        <div v-if="$slots.footer" class="flex justify-end gap-2.5 border-t border-line-2 bg-white px-[26px] py-4">
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>
