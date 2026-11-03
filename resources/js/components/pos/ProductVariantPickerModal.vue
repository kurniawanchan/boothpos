<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import { formatIDR } from '../../utils/money';

const { t } = useI18n();

// `product` is a browse card from posProductCards.js — { name, artist_name,
// variants: [...active only] }. Only opened for products with more than
// one active variant; the single-variant case is added to the cart
// directly by PosView without ever showing this picker.
// 054 (US1) — pemilih TETAP TERBUKA setelah memilih varian: kasir memilih beberapa varian
// (atau beberapa pcs varian yang sama) lalu menutupnya sendiri lewat tombol Tutup di bawah.
// `cartQtyByVariant` ({ [variant.id]: qty }) diturunkan PosView dari keranjang, jadi hitungan di
// sini tidak pernah basi walau keranjang dikosongkan dari tempat lain.
const props = defineProps({
  open: { type: Boolean, default: false },
  product: { type: Object, default: null },
  cartQtyByVariant: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['close', 'select']);

const inCart = (variant) => props.cartQtyByVariant?.[variant.id] ?? 0;
// cart.add() sendiri tidak membatasi qty pada stok; pemilih yang tetap terbuka membuat
// ketukan beruntun mudah, jadi varian dinonaktifkan begitu keranjang sudah memuat seluruh stoknya
// (sama seperti tombol + di panel keranjang).
const isUnavailable = (variant) => variant.current_stock <= 0 || inCart(variant) >= variant.current_stock;

function pick(variant) {
  if (isUnavailable(variant)) return;
  emit('select', variant);
}

const lightboxSrc = ref(null);
const lightboxAlt = ref('');
function variantImage(v) {
  // Falls back to the product's own image when this variant has none of
  // its own — same fallback used everywhere else a variant image shows.
  return v.image_url ?? props.product?.image_url ?? null;
}
function openImageLightbox(v) {
  const src = variantImage(v);
  if (!src) return;
  lightboxSrc.value = src;
  lightboxAlt.value = v.variant_name;
}
</script>

<template>
  <BaseModal :open="open" :title="product?.name ?? t('pos.pick_variant')" max-width-class="max-w-[420px]" @close="emit('close')">
    <div class="flex flex-col gap-2.5 px-5 py-4">
      <p v-if="product?.category_name" class="text-[11px] font-semibold uppercase tracking-wide text-muted-4">{{ product.category_name }}</p>
      <p v-if="product?.artist_name" class="text-[12px] text-muted-3">{{ product.artist_name }} · {{ t('pos.pick_variant_hint') }}</p>
      <button
        v-for="v in product?.variants ?? []"
        :key="v.id"
        type="button"
        :disabled="isUnavailable(v)"
        class="flex items-center justify-between gap-3 rounded-lg border bg-white px-3.5 py-3 text-left transition-colors hover:border-brand disabled:cursor-not-allowed disabled:opacity-50"
        :class="inCart(v) > 0 ? 'border-brand' : 'border-line-2'"
        @click="pick(v)"
      >
        <div class="flex items-center gap-2.5">
          <span
            v-if="variantImage(v)"
            role="button"
            tabindex="0"
            class="h-10 w-10 flex-none cursor-zoom-in"
            :aria-label="t('master_data.enlarge_variant_image', { name: v.variant_name })"
            @click.stop="openImageLightbox(v)"
            @keydown.enter.stop="openImageLightbox(v)"
          >
            <img :src="variantImage(v)" :alt="v.variant_name" class="h-10 w-10 rounded-md border border-line-2 object-cover" />
          </span>
          <div class="flex flex-col gap-0.5">
            <span class="text-[13.5px] font-semibold leading-tight">{{ v.variant_name }}</span>
            <span class="font-mono text-[10.5px] text-muted-3">{{ v.sku }}</span>
            <span v-if="inCart(v) > 0" class="w-fit rounded-full bg-mint-100 px-2 py-0.5 text-[10.5px] font-bold text-brand-active">{{ t('pos.in_cart_count', { count: inCart(v) }) }}</span>
          </div>
        </div>
        <div class="flex flex-col items-end gap-0.5">
          <span class="text-[13.5px] font-bold text-brand-active">{{ formatIDR(v.sell_price) }}</span>
          <span class="text-[11px]" :class="v.current_stock <= 0 ? 'font-semibold text-danger-text' : 'text-muted-3'">
            {{ v.current_stock <= 0 ? t('pos.stock_out') : t('pos.stock_n', { count: v.current_stock }) }}
          </span>
        </div>
      </button>
    </div>

    <template #footer>
      <BaseButton variant="secondary" class="w-full" data-testid="picker-close" @click="emit('close')">
        {{ t('pos.picker_close') }}
      </BaseButton>
    </template>
  </BaseModal>

  <ImageLightbox :open="!!lightboxSrc" :src="lightboxSrc" :alt="lightboxAlt" @close="lightboxSrc = null" />
</template>
