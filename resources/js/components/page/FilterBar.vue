<script setup>
import { useI18n } from 'vue-i18n';
import { formatCount } from '../../utils/formatters';

/**
 * 041-back-office-ui-redesign (US2) — baris filter di atas tabel: kolom cari, filter (slot), dan penghitung
 * "N data" untuk HASIL yang sedang difilter (kelompok kanan; slot `trailing` opsional menambah kendali di sebelahnya). `active` menandai ada filter/pencarian terpasang; hanya saat itu
 * tombol "Atur ulang filter" muncul. Sinkronisasi ke address bar dilakukan pemanggil lewat useQueryState —
 * komponen ini tidak tahu soal URL.
 */
defineProps({
  search: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  count: { type: Number, default: null },
  active: { type: Boolean, default: false },
  searchLabel: { type: String, default: '' },
  // Daftar tanpa pencarian di server (mis. Stok, PO) menyembunyikan kolom cari — tak ada kendali yang tak berfungsi.
  searchable: { type: Boolean, default: true },
});
defineEmits(['update:search', 'reset']);
const { t } = useI18n();
</script>

<template>
  <div class="flex flex-wrap items-center gap-3 px-5 py-4">
    <label v-if="searchable" class="flex min-w-[220px] flex-1 items-center gap-2 border-2 border-line bg-white px-3 py-2 text-[13px] focus-within:border-brand sm:max-w-[360px]">
      <i class="ph-duotone ph-magnifying-glass text-[16px] text-muted" aria-hidden="true"></i>
      <span class="sr-only">{{ searchLabel || t('frame.search_label') }}</span>
      <input
        type="search"
        :value="search"
        :placeholder="placeholder"
        class="min-w-0 flex-1 bg-transparent outline-none"
        @input="$emit('update:search', $event.target.value)"
      />
    </label>
    <slot />
    <button
      v-if="active"
      type="button"
      class="text-[12.5px] font-bold text-brand-active underline decoration-dotted hover:text-brand"
      @click="$emit('reset')"
    >
      {{ t('page.reset_filters') }}
    </button>
    <!-- Hitungan hasil selalu di kanan; slot `trailing` (opsional, mis. saklar Grid|Tabel dan tombol tambah) ikut di
         kelompok kanan yang sama, SESUDAH hitungan, supaya urutannya "N data · saklar · tombol". -->
    <div v-if="count !== null || $slots.trailing" class="ml-auto flex items-center gap-3">
      <span v-if="count !== null" class="text-[12.5px] font-semibold text-muted" data-testid="data-count">{{ t('page.data_count', { count: formatCount(count) }) }}</span>
      <slot name="trailing" />
    </div>
  </div>
</template>
