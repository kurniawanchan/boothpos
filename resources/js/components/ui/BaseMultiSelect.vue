<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

/**
 * 005-ux-enhancements-dashboard (US1) — dropdown multi-pilih dengan
 * pencarian dan opsi "Semua", untuk filter artist/category di
 * Products & POS. Sengaja komponen BARU (bukan menambah mode `multiple`
 * ke BaseSelect.vue) supaya 8+ pemanggil BaseSelect.vue yang sudah ada
 * tidak berisiko regresi — lihat research.md R2. Struktur
 * posisi/klik-di-luar/Teleport meniru BaseSelect.vue apa adanya.
 *
 * modelValue: array kosong berarti "Semua" (tidak ada filter pada sumbu
 * ini). Memilih satu opsi spesifik otomatis melepas status "Semua", dan
 * memilih "Semua" mengosongkan seleksi spesifik (FR-003).
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  label: { type: String, default: '' },
  options: { type: Array, default: () => [] }, // [{ value, label }]
  placeholder: { type: String, default: '' },
  allLabel: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const id = useId();
const rootEl = ref(null);
const triggerEl = ref(null);
const panelEl = ref(null);
const isOpen = ref(false);
const activeIndex = ref(-1);
const panelStyle = ref({});
const search = ref('');

const effectivePlaceholder = computed(() => props.placeholder || t('common.select_placeholder'));
const effectiveAllLabel = computed(() => props.allLabel || t('common.all'));

const isAllSelected = computed(() => props.modelValue.length === 0);

const filteredOptions = computed(() => {
  const term = search.value.trim().toLowerCase();
  if (!term) return props.options;
  return props.options.filter((o) => o.label.toLowerCase().includes(term));
});

const displayLabel = computed(() => {
  if (isAllSelected.value) return effectiveAllLabel.value;
  if (props.modelValue.length === 1) {
    const found = props.options.find((o) => o.value == props.modelValue[0]);
    return found?.label ?? effectivePlaceholder.value;
  }
  return t('common.n_selected', { count: props.modelValue.length });
});

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
  search.value = '';
  activeIndex.value = -1;
  updatePanelPosition();
  nextTick(() => panelEl.value?.querySelector('input')?.focus());
}
function close() {
  isOpen.value = false;
}
function toggle() {
  isOpen.value ? close() : open();
}

function selectAll() {
  emit('update:modelValue', []);
}

function toggleOption(option) {
  const current = props.modelValue;
  const exists = current.some((v) => v == option.value);
  const next = exists ? current.filter((v) => v != option.value) : [...current, option.value];
  emit('update:modelValue', next);
}

function onClickOutside(e) {
  const clickedTrigger = rootEl.value && rootEl.value.contains(e.target);
  const clickedPanel = panelEl.value && panelEl.value.contains(e.target);
  if (isOpen.value && !clickedTrigger && !clickedPanel) close();
}
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
    <span v-if="label" :id="`${id}-label`" class="text-[12.5px] font-semibold text-muted-4">{{ label }}</span>

    <button
      ref="triggerEl"
      :id="id"
      type="button"
      aria-haspopup="listbox"
      :aria-expanded="isOpen"
      :aria-controls="`${id}-listbox`"
      :disabled="disabled"
      class="flex h-[46px] items-center justify-between gap-2 rounded-lg border border-line bg-white px-3.5 text-left text-[14.5px] outline-none transition-colors disabled:bg-line-5 disabled:text-muted-3 focus:border-brand focus:ring-[3px] focus:ring-mint-100"
      :class="isAllSelected ? 'text-muted-3' : 'text-ink'"
      @click="toggle"
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
        aria-multiselectable="true"
        :style="panelStyle"
        class="z-[95] flex max-h-80 flex-col overflow-hidden rounded-lg border border-line bg-white shadow-lg"
      >
        <div class="border-b border-line-6 p-2">
          <input
            v-model="search"
            type="text"
            :placeholder="t('common.search_placeholder')"
            class="h-9 w-full rounded-md border border-line px-2.5 text-[13.5px] outline-none focus:border-brand"
          />
        </div>
        <div class="overflow-y-auto p-1">
          <button
            type="button"
            role="option"
            :aria-selected="isAllSelected"
            class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-[14px] transition-colors"
            :class="isAllSelected ? 'bg-mint-100 font-semibold text-ink' : 'text-ink hover:bg-line-5'"
            @click="selectAll"
          >
            <span class="truncate">{{ effectiveAllLabel }}</span>
            <i v-if="isAllSelected" class="ph-duotone ph-check shrink-0 text-[14px] text-brand" aria-hidden="true"></i>
          </button>

          <button
            v-for="opt in filteredOptions"
            :key="opt.value"
            type="button"
            role="option"
            :aria-selected="modelValue.some((v) => v == opt.value)"
            class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-[14px] transition-colors"
            :class="modelValue.some((v) => v == opt.value) ? 'bg-mint-100 font-semibold text-ink' : 'text-ink hover:bg-line-5'"
            @click="toggleOption(opt)"
          >
            <span class="truncate">{{ opt.label }}</span>
            <i v-if="modelValue.some((v) => v == opt.value)" class="ph-duotone ph-check shrink-0 text-[14px] text-brand" aria-hidden="true"></i>
          </button>

          <div v-if="filteredOptions.length === 0" class="px-3 py-2 text-[13px] text-muted-3">{{ t('common.no_options') }}</div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
