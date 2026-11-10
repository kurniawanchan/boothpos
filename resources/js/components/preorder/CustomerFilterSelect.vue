<script setup>
import { computed, reactive, ref } from 'vue';
import BaseMultiSelect from '../ui/BaseMultiSelect.vue';
import { listCustomers } from '../../api/customers';
import { useDebouncedFn } from '../../composables/useDebouncedFn';

/**
 * 066-preorder-filter-export-columns (US1) — filter Pelanggan di daftar pre-order.
 *
 * Pelanggan bisa lebih banyak dari satu halaman, jadi daftar pilihan TIDAK
 * dimuat sekaligus seperti penjual/kategori: BaseMultiSelect dipakai dalam mode
 * `remote`, panggilan yang sama dengan CustomerSearchDropdown (GET /customers;
 * `search` kosong = halaman pertama tanpa filter, jadi pilihan awal muncul
 * tanpa mengetik).
 *
 * Dua hal yang sengaja dijaga:
 * - Nama pelanggan yang SUDAH dipilih diingat (id → nama). Hasil pencarian
 *   berikutnya bisa tidak memuat mereka lagi; tanpa ingatan ini label tombol
 *   berubah jadi placeholder padahal filternya masih aktif.
 * - Label opsi HANYA nama. Telepon/e-mail ikut dikirim API tetapi tidak perlu
 *   (dan tidak boleh) tampil di filter — data pribadi tidak disebar ke layar
 *   yang tidak membutuhkannya. Konsekuensi yang diterima: dua pelanggan
 *   sama-nama tampil sama.
 *
 * v-model = array id pelanggan; array kosong = "Semua" (konvensi BaseMultiSelect).
 */
defineProps({
  modelValue: { type: Array, default: () => [] },
  allLabel: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const PAGE_SIZE = 25;
const results = ref([]); // [{ value, label }]
const names = reactive({}); // id → nama, untuk pelanggan yang sudah dipilih
let requestSeq = 0;

async function load(term) {
  const seq = ++requestSeq;
  try {
    const res = await listCustomers({ search: term.trim(), per_page: PAGE_SIZE });
    // Jawaban pencarian yang sudah usang (pengguna sudah mengetik lagi) dibuang.
    if (seq !== requestSeq) return;
    results.value = res.data.map((c) => ({ value: c.id, label: c.name }));
  } catch {
    // Kegagalan dilaporkan oleh penanganan galat pusat api/client.js; daftar
    // sebelumnya dibiarkan agar filter yang sudah aktif tetap bisa dilepas.
  }
}
const debouncedLoad = useDebouncedFn(load, 300);

function onSearch(term) {
  // Panel baru dibuka ('' ) → muat langsung; mengetik → ditunda 300 ms.
  if (term === '') {
    debouncedLoad.cancel();
    load('');
  } else {
    debouncedLoad(term);
  }
}

const options = computed(() => {
  const shown = new Set(results.value.map((o) => String(o.value)));
  const remembered = Object.entries(names)
    .filter(([id]) => !shown.has(id))
    .map(([id, label]) => ({ value: Number(id), label }));

  return [...results.value, ...remembered];
});

function onUpdate(next) {
  next.forEach((id) => {
    const found = results.value.find((o) => o.value == id);
    if (found) names[id] = found.label;
  });
  emit('update:modelValue', next);
}
</script>

<template>
  <BaseMultiSelect
    :model-value="modelValue"
    :options="options"
    :all-label="allLabel"
    remote
    @search="onSearch"
    @update:model-value="onUpdate"
  />
</template>
