<script setup>
import KpiCard from './KpiCard.vue';

/**
 * 041-back-office-ui-redesign (US2) — ringkasan empat angka di atas setiap halaman manajemen. Selalu satu
 * bingkai bergaris dengan sel-sel yang dipisah garis (seperti desain), 2×2 di lebar sempit dan satu baris
 * di layar lebar. Setiap item: { key, label, value, note?, tone?, unavailable?, clickable? }; `select` memancarkan
 * key item (mis. untuk berpindah ke tab terkait). Angka harus berasal dari data dan filter YANG SAMA dengan
 * daftar di bawahnya — itu tanggung jawab pemanggil/endpoint ringkasan, bukan komponen ini.
 */
defineProps({
  items: { type: Array, required: true },
});
defineEmits(['select']);
</script>

<template>
  <section class="mx-[26px] mb-5 grid grid-cols-1 gap-px border-2 border-line bg-line sm:grid-cols-2 xl:grid-cols-4">
    <KpiCard
      v-for="item in items"
      :key="item.key"
      :label="item.label"
      :value="item.value"
      :note="item.note"
      :tone="item.tone"
      :unavailable="item.unavailable"
      :clickable="item.clickable"
      @select="$emit('select', item.key)"
    />
  </section>
</template>
