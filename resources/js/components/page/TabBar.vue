<script setup>
import { nextTick, ref } from 'vue';

/**
 * 041-back-office-ui-redesign (US2) — tab bagian halaman dengan chip jumlah. Jumlah menggambarkan SELURUH bagian
 * (bukan tampilan yang difilter) — penghitung "N data" di FilterBar yang menggambarkan hasil filter.
 * Pola ARIA tablist: satu tab dalam urutan Tab (roving tabindex), panah kiri/kanan + Home/End memindahkan
 * dan memilih. Isi panel dirender pemanggil; set `panelId` bila ingin aria-controls.
 *
 * tabs: [{ key, label, count?, tone? }]   tone chip: neutral | warning | danger
 */
const props = defineProps({
  tabs: { type: Array, required: true },
  modelValue: { type: String, required: true },
  label: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const refs = ref({});
const TONES = { neutral: 'bg-line-7 text-muted-4', warning: 'bg-warn-bg text-warn-text', danger: 'bg-danger-bg text-danger-text' };

async function move(index) {
  const n = props.tabs.length;
  const next = props.tabs[(index + n) % n];
  emit('update:modelValue', next.key);
  await nextTick();
  refs.value[next.key]?.focus();
}
function onKeydown(e, index) {
  if (e.key === 'ArrowRight') { e.preventDefault(); move(index + 1); }
  else if (e.key === 'ArrowLeft') { e.preventDefault(); move(index - 1); }
  else if (e.key === 'Home') { e.preventDefault(); move(0); }
  else if (e.key === 'End') { e.preventDefault(); move(props.tabs.length - 1); }
}
</script>

<template>
  <div role="tablist" :aria-label="label || undefined" class="flex flex-wrap gap-x-5 border-b-2 border-line px-5">
    <button
      v-for="(tab, i) in tabs"
      :key="tab.key"
      :ref="(el) => (refs[tab.key] = el)"
      type="button"
      role="tab"
      :aria-selected="modelValue === tab.key"
      :tabindex="modelValue === tab.key ? 0 : -1"
      class="-mb-[2px] flex items-center gap-2 border-b-2 px-0.5 py-3 text-[13px] font-bold transition-colors focus-visible:outline-none focus-visible:text-brand-active"
      :class="modelValue === tab.key ? 'border-brand text-ink' : 'border-transparent text-muted hover:text-ink'"
      @click="emit('update:modelValue', tab.key)"
      @keydown="onKeydown($event, i)"
    >
      {{ tab.label }}
      <span v-if="tab.count !== undefined && tab.count !== null" class="px-1.5 py-px text-[11px] font-bold tabular-nums" :class="TONES[tab.tone] ?? TONES.neutral">{{ tab.count }}</span>
    </button>
  </div>
</template>
