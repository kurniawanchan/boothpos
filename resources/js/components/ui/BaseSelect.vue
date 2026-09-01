<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

/**
 * Dropdown kustom, bukan <select> native — popup <select> native memakai
 * styling OS (gelap, sudut lain, warna lain di macOS/Windows) yang tidak
 * bisa di-restyle lewat CSS di browser manapun, jadi selalu terlihat lepas
 * dari desain aplikasi. Kontrak props/emits sama persis dengan versi lama
 * supaya 8 pemanggil yang sudah ada tidak perlu berubah.
 */
const props = defineProps({
  modelValue: { type: [String, Number, null], default: '' },
  label: { type: String, default: '' },
  options: { type: Array, default: () => [] }, // [{ value, label }]
  placeholder: { type: String, default: 'Pilih…' },
  error: { type: String, default: '' },
  hint: { type: String, default: '' },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const id = useId();
const errorId = computed(() => `${id}-error`);
const rootEl = ref(null);
const triggerEl = ref(null);
const panelEl = ref(null);
const isOpen = ref(false);
const activeIndex = ref(-1);
const panelStyle = ref({});

const selectedOption = computed(() =>
  props.options.find((o) => o.value == props.modelValue) ?? null,
);
const displayLabel = computed(() => selectedOption.value?.label ?? props.placeholder);

// Panel is teleported to <body> (same pattern as BaseModal) and positioned
// with fixed coordinates computed from the trigger's own rect. A select
// nested inside a drawer/scrollable container otherwise has its dropdown
// silently clipped or hidden by an ancestor's `overflow` — position:
// absolute keeps it in that ancestor's stacking/clipping context no matter
// what z-index it's given, which is invisible to the eye but not to any
// DOM query, so this bites in exactly the cases that are hardest to spot.
function updatePanelPosition() {
  if (!triggerEl.value) return;
  const r = triggerEl.value.getBoundingClientRect();
  panelStyle.value = {
    position: 'fixed',
    top: `${r.bottom + 6}px`,
    left: `${r.left}px`,
    width: `${r.width}px`,
  };
}

function open() {
  if (props.disabled) return;
  isOpen.value = true;
  updatePanelPosition();
  const currentIndex = props.options.findIndex((o) => o.value == props.modelValue);
  activeIndex.value = currentIndex >= 0 ? currentIndex : 0;
  nextTick(() => {
    panelEl.value?.querySelector('[data-active="true"]')?.scrollIntoView?.({ block: 'nearest' });
  });
}
function close() {
  isOpen.value = false;
}
function toggle() {
  isOpen.value ? close() : open();
}
function select(option) {
  emit('update:modelValue', option.value);
  close();
  nextTick(() => rootEl.value?.querySelector('button')?.focus());
}

function onTriggerKeydown(e) {
  if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) {
    e.preventDefault();
    if (!isOpen.value) return open();
    if (e.key === 'ArrowDown') moveActive(1);
    else if (e.key === 'ArrowUp') moveActive(-1);
    else select(props.options[activeIndex.value]);
  } else if (e.key === 'Escape' && isOpen.value) {
    e.preventDefault();
    close();
  }
}
function moveActive(delta) {
  if (props.options.length === 0) return;
  activeIndex.value = (activeIndex.value + delta + props.options.length) % props.options.length;
  panelEl.value?.querySelector('[data-active="true"]')?.scrollIntoView?.({ block: 'nearest' });
}

function onClickOutside(e) {
  const clickedTrigger = rootEl.value && rootEl.value.contains(e.target);
  const clickedPanel = panelEl.value && panelEl.value.contains(e.target);
  if (isOpen.value && !clickedTrigger && !clickedPanel) close();
}
// The panel is fixed-positioned to the trigger's rect at open time — if the
// page scrolls or resizes while open, either reposition or just close
// rather than leaving it floating over the wrong spot.
function onScrollOrResize() {
  if (isOpen.value) close();
}
watch(isOpen, (open) => {
  if (open) {
    document.addEventListener('mousedown', onClickOutside);
    window.addEventListener('scroll', onScrollOrResize, true);
    window.addEventListener('resize', onScrollOrResize);
  } else {
    document.removeEventListener('mousedown', onClickOutside);
    window.removeEventListener('scroll', onScrollOrResize, true);
    window.removeEventListener('resize', onScrollOrResize);
  }
});
onBeforeUnmount(() => {
  document.removeEventListener('mousedown', onClickOutside);
  window.removeEventListener('scroll', onScrollOrResize, true);
  window.removeEventListener('resize', onScrollOrResize);
});
</script>

<template>
  <div ref="rootEl" class="relative flex flex-col gap-1.5">
    <span v-if="label" :id="`${id}-label`" class="text-[12.5px] font-semibold text-muted-4">
      {{ label }}<span v-if="required" class="text-danger-text" aria-hidden="true"> *</span>
    </span>

    <button
      ref="triggerEl"
      :id="id"
      type="button"
      role="combobox"
      :aria-labelledby="label ? `${id}-label` : undefined"
      aria-haspopup="listbox"
      :aria-expanded="isOpen"
      :aria-controls="`${id}-listbox`"
      :aria-invalid="error ? 'true' : undefined"
      :aria-describedby="error ? errorId : undefined"
      :disabled="disabled"
      class="flex h-[46px] items-center justify-between gap-2 rounded-lg border bg-white px-3.5 text-left text-[14.5px] outline-none transition-colors disabled:bg-line-5 disabled:text-muted-3 focus:border-brand focus:ring-[3px] focus:ring-mint-100"
      :class="[
        error ? 'border-danger-border' : 'border-line',
        selectedOption ? 'text-ink' : 'text-muted-3',
      ]"
      @click="toggle"
      @keydown="onTriggerKeydown"
    >
      <span class="truncate">{{ displayLabel }}</span>
      <i
        class="ph-duotone ph-caret-down shrink-0 text-[13px] text-muted-3 transition-transform"
        :class="{ 'rotate-180': isOpen }"
        aria-hidden="true"
      ></i>
    </button>

    <Teleport to="body">
      <div
        v-if="isOpen"
        ref="panelEl"
        :id="`${id}-listbox`"
        role="listbox"
        :aria-labelledby="label ? `${id}-label` : undefined"
        :style="panelStyle"
        class="z-[95] max-h-64 overflow-y-auto rounded-lg border border-line bg-white p-1 shadow-lg"
      >
        <div
          v-if="placeholder"
          role="option"
          :aria-selected="!modelValue"
          class="cursor-default rounded-md px-3 py-2 text-[14px] text-muted-3"
        >
          {{ placeholder }}
        </div>
        <button
          v-for="(opt, i) in options"
          :key="opt.value"
          type="button"
          role="option"
          :data-active="i === activeIndex"
          :aria-selected="opt.value == modelValue"
          class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-[14px] transition-colors"
          :class="
            opt.value == modelValue
              ? 'bg-mint-100 font-semibold text-ink'
              : i === activeIndex
                ? 'bg-line-5 text-ink'
                : 'text-ink hover:bg-line-5'
          "
          @click="select(opt)"
          @mouseenter="activeIndex = i"
        >
          <span class="truncate">{{ opt.label }}</span>
          <i v-if="opt.value == modelValue" class="ph-duotone ph-check shrink-0 text-[14px] text-brand" aria-hidden="true"></i>
        </button>
        <div v-if="options.length === 0" class="px-3 py-2 text-[13px] text-muted-3">Tidak ada pilihan.</div>
      </div>
    </Teleport>

    <span v-if="error" :id="errorId" class="text-[12px] font-medium text-danger-text">{{ error }}</span>
    <span v-else-if="hint" class="text-[11.5px] text-muted-3">{{ hint }}</span>
  </div>
</template>
