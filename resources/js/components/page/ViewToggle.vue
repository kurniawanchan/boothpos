<script setup>
import { nextTick, ref } from 'vue';

/**
 * 043-products-page-redesign (T019) — saklar tampilan bersegmen (Grid | Tabel). Pola ARIA radiogroup: satu
 * pilihan aktif, roving tabindex, panah/Home/End memindahkan dan memilih. Teks selalu tampil (ikon hanya
 * pelengkap) supaya pilihan tak bergantung pada ikon atau warna.
 *
 * Ukuran ringkas (garis tipis, tinggi ~30 px) mengikuti toolbar daftar desain Back Office; segmen aktif terisi warna brand.
 *
 * options: [{ value, label, icon? }]   icon = kelas Phosphor mis. 'ph-squares-four'
 */
const props = defineProps({
  options: { type: Array, required: true },
  modelValue: { type: String, required: true },
  label: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const refs = ref({});

async function move(index) {
  const n = props.options.length;
  const next = props.options[(index + n) % n];
  emit('update:modelValue', next.value);
  await nextTick();
  refs.value[next.value]?.focus();
}
function onKeydown(e, index) {
  if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); move(index + 1); }
  else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { e.preventDefault(); move(index - 1); }
  else if (e.key === 'Home') { e.preventDefault(); move(0); }
  else if (e.key === 'End') { e.preventDefault(); move(props.options.length - 1); }
}
</script>

<template>
  <div role="radiogroup" :aria-label="label || undefined" class="inline-flex border border-line bg-white">
    <button
      v-for="(opt, i) in options"
      :key="opt.value"
      :ref="(el) => (refs[opt.value] = el)"
      type="button"
      role="radio"
      :aria-checked="modelValue === opt.value"
      :tabindex="modelValue === opt.value ? 0 : -1"
      class="flex items-center gap-1.5 px-3.5 py-1 text-[12.5px] font-bold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand"
      :class="modelValue === opt.value ? 'bg-brand text-white' : 'text-muted-5 hover:bg-line-7'"
      @click="emit('update:modelValue', opt.value)"
      @keydown="onKeydown($event, i)"
    >
      <i v-if="opt.icon" class="ph-duotone text-[14px]" :class="opt.icon" aria-hidden="true"></i>
      {{ opt.label }}
    </button>
  </div>
</template>
