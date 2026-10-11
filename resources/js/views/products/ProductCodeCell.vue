<script setup>
import { useI18n } from 'vue-i18n';

/**
 * Sel "Kode" daftar produk: gambar di atas, kode tepat di bawahnya (038). Dipecah dari ProductsView tanpa
 * mengubah markup: kolom khusus gambar sengaja tidak ada supaya gambar bisa 96px tanpa melebarkan tabel;
 * kode SELALU satu baris (036, dipotong + tooltip bila sangat panjang); placeholder seukuran gambar supaya
 * baris tetap sejajar. Klik gambar hanya MEMINTA pembesaran — lightbox dimiliki halaman.
 */
defineProps({ product: { type: Object, required: true } });
defineEmits(['enlarge']);
const { t } = useI18n();
</script>

<template>
  <div class="flex flex-col items-center gap-1.5">
    <button
      v-if="product.image_url"
      type="button"
      class="cursor-zoom-in"
      :aria-label="t('master_data.enlarge_product_image', { name: product.name })"
      @click="$emit('enlarge', product)"
    >
      <img :src="product.image_url" :alt="product.name" class="h-24 w-24 rounded-md border border-line-2 object-cover" />
    </button>
    <div v-else class="flex h-24 w-24 items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3">
      <i class="ph-duotone ph-image text-[30px]" aria-hidden="true"></i>
    </div>
    <span class="inline-block max-w-[190px] truncate whitespace-nowrap font-mono text-[12px] font-bold text-brand-active" :title="product.code_prefix">{{ product.code_prefix }}</span>
  </div>
</template>
