<script setup>
import ItemCard from './ItemCard.vue';

/**
 * 043 (T033) — tampilan Grid: 5 kolom di layar lebar, makin sedikit di layar sempit. Komponen ini hanya menata
 * kartu; keadaan memuat/galat/kosong dipegang halaman supaya Grid dan Tabel berbagi satu perilaku.
 */
defineProps({
  items: { type: Array, required: true },
  canEdit: { type: Boolean, default: false },
});
</script>

<template>
  <ul class="grid grid-cols-2 gap-3.5 px-5 pb-5 pt-1 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5" data-testid="item-grid">
    <li v-for="item in items" :key="item.id" class="flex">
      <ItemCard :item="item" :can-edit="canEdit" class="w-full" />
    </li>
  </ul>
</template>
