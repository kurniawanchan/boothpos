<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFocusTrap } from '../../composables/useFocusTrap';

// 090 — lembar bawah (bottom sheet) untuk halaman pesan mandiri di ponsel: tutup dengan Esc/tap latar, gulir badan halaman
// dikunci selama terbuka, dan fokus terjebak di dalam panel (aksesibilitas).
const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const panel = ref(null);
useFocusTrap(panel, computed(() => props.open));

function onKey(e) {
  if (e.key === 'Escape') emit('close');
}

watch(
  () => props.open,
  (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) window.addEventListener('keydown', onKey);
    else window.removeEventListener('keydown', onKey);
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  document.body.style.overflow = '';
  window.removeEventListener('keydown', onKey);
});
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[100] flex items-end bg-ink/60" @click.self="emit('close')">
      <section
        ref="panel"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
        class="flex max-h-[92vh] w-full flex-col bg-surface-soft shadow-drawer"
      >
        <header class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
          <h2 class="text-[16px] font-extrabold text-ink">{{ title }}</h2>
          <button
            type="button"
            class="flex h-10 w-10 items-center justify-center text-muted hover:text-ink"
            :aria-label="t('common.close')"
            @click="emit('close')"
          >
            <i class="ph-duotone ph-x text-[20px]" aria-hidden="true"></i>
          </button>
        </header>
        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4"><slot /></div>
        <footer v-if="$slots.footer" class="border-t border-line bg-surface-soft px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <slot name="footer" />
        </footer>
      </section>
    </div>
  </Teleport>
</template>
