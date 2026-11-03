<script setup>
import { watch } from 'vue';
import { useI18n } from 'vue-i18n';

// 054 (US3/US4) — filter penjual/kategori POS sebagai pil, menggantikan dropdown multi-pilih.
// Kontraknya sama dengan BaseMultiSelect (array id; kosong = "Semua") supaya state dan pemuat
// produk di PosView tidak berubah. Pil pertama = "Semua"; mengetuk pil lain menambah/mencabutnya
// dari pilihan (multi-pilih tetap), mengetuk "Semua" mengosongkan pilihan.
//
// Satu baris yang TIDAK membungkus dan menggulung horizontal: pada tablet jumlah penjual bisa jauh
// melebihi lebar layar, dan baris yang membungkus akan mendorong grid produk ke bawah.
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] }, // [{ value, label }]
  allLabel: { type: String, required: true },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const isOn = (value) => props.modelValue.some((v) => v == value);

function toggle(value) {
  emit('update:modelValue', isOn(value) ? props.modelValue.filter((v) => v != value) : [...props.modelValue, value]);
}

// Penjual/kategori yang dinonaktifkan atau dihapus saat terpilih jatuh kembali ke "Semua" (tak ada pil
// untuknya lagi, jadi pilihan tak terlihat akan terus menyaring grid). Tidak dijalankan saat opsi belum dimuat.
watch(
  () => [props.options, props.modelValue],
  () => {
    if (!props.options.length || !props.modelValue.length) return;
    const valid = props.modelValue.filter((v) => props.options.some((o) => o.value == v));
    if (valid.length !== props.modelValue.length) emit('update:modelValue', valid);
  },
  { immediate: true },
);

const pill = 'flex-none snap-start whitespace-nowrap rounded-full border px-3.5 py-2 text-[13px] font-semibold transition-colors';
const on = 'border-transparent bg-brand text-white';
const off = 'border-line-2 bg-white text-muted-4 hover:border-brand';
</script>

<template>
  <div
    role="group"
    class="flex min-w-0 shrink-0 flex-nowrap items-center gap-2 overflow-x-auto scroll-smooth snap-x pb-1 [scrollbar-width:thin]"
  >
    <button type="button" :class="[pill, modelValue.length === 0 ? on : off]" :aria-pressed="modelValue.length === 0" @click="emit('update:modelValue', [])">
      {{ allLabel }}
    </button>
    <button
      v-for="o in options"
      :key="o.value"
      type="button"
      :title="o.label"
      :class="[pill, 'max-w-[220px] truncate', isOn(o.value) ? on : off]"
      :aria-pressed="isOn(o.value)"
      @click="toggle(o.value)"
    >
      {{ o.label }}
    </button>
  </div>
</template>
