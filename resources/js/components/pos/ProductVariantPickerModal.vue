<script setup>
import BaseModal from '../ui/BaseModal.vue';
import { formatIDR } from '../../utils/money';

// `product` is a browse card from posProductCards.js — { name, artist_name,
// variants: [...active only] }. Only opened for products with more than
// one active variant; the single-variant case is added to the cart
// directly by PosView without ever showing this picker.
defineProps({
  open: { type: Boolean, default: false },
  product: { type: Object, default: null },
});
const emit = defineEmits(['close', 'select']);

function pick(variant) {
  if (variant.current_stock <= 0) return;
  emit('select', variant);
}
</script>

<template>
  <BaseModal :open="open" :title="product?.name ?? 'Pilih varian'" max-width-class="max-w-[420px]" @close="emit('close')">
    <div class="flex flex-col gap-2.5 px-5 py-4">
      <p v-if="product?.artist_name" class="text-[12px] text-muted-3">{{ product.artist_name }} · pilih varian untuk ditambahkan ke keranjang</p>
      <button
        v-for="v in product?.variants ?? []"
        :key="v.id"
        type="button"
        :disabled="v.current_stock <= 0"
        class="flex items-center justify-between gap-3 rounded-lg border border-line-2 bg-white px-3.5 py-3 text-left transition-colors hover:border-brand disabled:cursor-not-allowed disabled:opacity-50"
        @click="pick(v)"
      >
        <div class="flex flex-col gap-0.5">
          <span class="text-[13.5px] font-semibold leading-tight">{{ v.variant_name }}</span>
          <span class="font-mono text-[10.5px] text-muted-3">{{ v.sku }}</span>
        </div>
        <div class="flex flex-col items-end gap-0.5">
          <span class="text-[13.5px] font-bold text-brand-active">{{ formatIDR(v.sell_price) }}</span>
          <span class="text-[11px]" :class="v.current_stock <= 0 ? 'font-semibold text-danger-text' : 'text-muted-3'">
            {{ v.current_stock <= 0 ? 'Stok habis' : `Stok ${v.current_stock}` }}
          </span>
        </div>
      </button>
    </div>
  </BaseModal>
</template>
