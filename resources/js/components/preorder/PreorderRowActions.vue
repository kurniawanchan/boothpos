<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Kolom aksi di baris list pre-order. Aturannya (permintaan owner): sampai
 * `maxInline` aksi ditampilkan sebagai tautan biasa; LEBIH dari itu, aksi
 * utama (`primaryKey`, mis. Detail) tetap terlihat dan sisanya masuk ke satu
 * dropdown "Lainnya".
 *
 * Menu di-Teleport ke <body> dengan posisi fixed (mekanisme yang sama dengan
 * BaseMultiSelect.vue) supaya tidak terpotong overflow tabel/kartu. Ditutup
 * saat klik di luar, Escape, scroll, atau resize — posisi fixed tidak ikut
 * bergeser bersama baris, jadi menutupnya lebih aman daripada menghitung ulang.
 *
 * Komponen ini hanya melaporkan `select(key)`; apa yang dilakukan tiap aksi
 * tetap tanggung jawab pemanggil.
 */
const props = defineProps({
  // [{ key, label, danger?, disabled?, title? }]
  actions: { type: Array, required: true },
  primaryKey: { type: String, default: 'detail' },
  maxInline: { type: Number, default: 3 },
});
const emit = defineEmits(['select']);

const { t } = useI18n();

const useMenu = computed(() => props.actions.length > props.maxInline);
const primary = computed(() => props.actions.find((a) => a.key === props.primaryKey));
const menuActions = computed(() => props.actions.filter((a) => a.key !== props.primaryKey));
const inlineActions = computed(() => (useMenu.value ? (primary.value ? [primary.value] : []) : props.actions));

const isOpen = ref(false);
const triggerEl = ref(null);
const panelEl = ref(null);
const panelStyle = ref({});

function place() {
  if (!triggerEl.value) return;
  const r = triggerEl.value.getBoundingClientRect();
  // Rata kanan dengan tombol pemicu (kolom aksi ada di tepi kanan layar).
  panelStyle.value = { position: 'fixed', top: `${r.bottom + 6}px`, right: `${Math.max(8, window.innerWidth - r.right)}px` };
}
function toggle() {
  if (isOpen.value) {
    isOpen.value = false;
    return;
  }
  place();
  isOpen.value = true;
  nextTick(() => panelEl.value?.querySelector('button:not(:disabled)')?.focus());
}
function choose(action) {
  if (action.disabled) return;
  isOpen.value = false;
  emit('select', action.key);
}

function onMouseDown(e) {
  const inTrigger = triggerEl.value && triggerEl.value.contains(e.target);
  const inPanel = panelEl.value && panelEl.value.contains(e.target);
  if (isOpen.value && !inTrigger && !inPanel) isOpen.value = false;
}
function onKeydown(e) {
  if (e.key === 'Escape' && isOpen.value) {
    isOpen.value = false;
    triggerEl.value?.focus();
  }
}
function close() {
  if (isOpen.value) isOpen.value = false;
}
watch(isOpen, (open) => {
  const method = open ? 'addEventListener' : 'removeEventListener';
  document[method]('mousedown', onMouseDown);
  document[method]('keydown', onKeydown);
  window[method]('scroll', close, true);
  window[method]('resize', close);
});
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onMouseDown);
  document.removeEventListener('keydown', onKeydown);
  window.removeEventListener('scroll', close, true);
  window.removeEventListener('resize', close);
});

const linkClass = (a) => [
  'text-[12.5px] font-semibold disabled:cursor-not-allowed disabled:opacity-40',
  a.danger ? 'text-danger-text' : a.key === props.primaryKey ? 'text-brand-active' : 'text-muted-4 hover:text-brand-active',
];
</script>

<template>
  <div class="flex items-center justify-end gap-3">
    <button
      v-for="a in inlineActions"
      :key="a.key"
      type="button"
      :disabled="a.disabled"
      :title="a.title || undefined"
      :class="linkClass(a)"
      @click="choose(a)"
    >{{ a.label }}</button>

    <template v-if="useMenu">
      <button
        ref="triggerEl"
        type="button"
        aria-haspopup="menu"
        :aria-expanded="isOpen"
        class="inline-flex items-center gap-1 text-[12.5px] font-semibold text-muted-4 hover:text-brand-active"
        @click="toggle"
      >
        {{ t('preorders.row_more_actions') }}
        <i class="ph-bold ph-caret-down text-[11px]" aria-hidden="true"></i>
      </button>
      <Teleport to="body">
        <div
          v-if="isOpen"
          ref="panelEl"
          role="menu"
          :style="panelStyle"
          class="z-50 flex min-w-44 flex-col overflow-hidden rounded-lg border border-line bg-white py-1 shadow-lg"
        >
          <button
            v-for="a in menuActions"
            :key="a.key"
            type="button"
            role="menuitem"
            :disabled="a.disabled"
            :title="a.title || undefined"
            class="px-3.5 py-2.5 text-left text-[13px] font-semibold hover:bg-line-7 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent"
            :class="a.danger ? 'text-danger-text' : 'text-muted-5'"
            @click="choose(a)"
          >{{ a.label }}</button>
        </div>
      </Teleport>
    </template>
  </div>
</template>
