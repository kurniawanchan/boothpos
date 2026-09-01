<script setup>
import { ref, computed, watch } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import StatusPill from '../ui/StatusPill.vue';
import { getProduct } from '../../api/products';
import { formatIDR } from '../../utils/money';
import { useToastStore } from '../../stores/toast';

/**
 * Read-only product detail — opened from the "Detail" row action on
 * /products (separate from Edit) and from the Sales report's product-name
 * click-through (Task 1). Reuses GET /products/{id}; the "total stock
 * available" figure has no dedicated backend field, so it's summed
 * client-side from variants[].current_stock.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  productId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const toast = useToastStore();
const product = ref(null);
const loading = ref(false);

watch(
  () => [props.open, props.productId],
  async ([open, productId]) => {
    if (!open || !productId) {
      product.value = null;
      return;
    }
    loading.value = true;
    try {
      product.value = await getProduct(productId);
    } catch (err) {
      toast.error(err.message || 'Gagal memuat detail produk.');
    } finally {
      loading.value = false;
    }
  },
  { immediate: true }
);

const totalStock = computed(() => (product.value?.variants ?? []).reduce((sum, v) => sum + Number(v.current_stock ?? 0), 0));
</script>

<template>
  <BaseModal :open="open" :title="product?.name ?? 'Detail produk'" max-width-class="max-w-[560px]" @close="emit('close')">
    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">Memuat detail produk…</div>
    <div v-else-if="product" class="flex flex-col gap-4 px-6 py-5">
      <div class="flex items-start gap-3.5">
        <img
          v-if="product.image_url"
          :src="product.image_url"
          :alt="product.name"
          class="h-20 w-20 flex-none rounded-lg border border-line-2 object-cover"
        />
        <div v-else class="flex h-20 w-20 flex-none items-center justify-center rounded-lg border border-dashed border-disabled-2 text-muted-3">
          <i class="ph-duotone ph-image text-[26px]" aria-hidden="true"></i>
        </div>
        <div class="flex flex-1 flex-col gap-1">
          <span class="font-mono text-[12px] font-bold text-brand-active">{{ product.code_prefix }}</span>
          <span class="text-[15px] font-bold tracking-tight">{{ product.name }}</span>
          <span class="text-[12.5px] text-muted-2">{{ product.artist_name }} · {{ product.category_name }}</span>
          <div class="flex gap-1.5">
            <StatusPill :variant="product.is_preorder ? 'warn' : 'neutral'">{{ product.is_preorder ? 'Pre-order' : 'Ready stock' }}</StatusPill>
            <StatusPill :variant="product.is_active ? 'mint' : 'neutral'">{{ product.is_active ? 'Aktif' : 'Nonaktif' }}</StatusPill>
          </div>
        </div>
      </div>

      <p v-if="product.description" class="text-[13px] leading-relaxed text-muted-4">{{ product.description }}</p>

      <div class="flex items-center justify-between rounded-lg border border-mint-border bg-mint-50 px-4 py-3">
        <span class="text-[12.5px] font-semibold text-brand-active">Total stok tersedia (semua varian)</span>
        <span class="text-[21px] font-extrabold tracking-tight text-brand-active">{{ totalStock }}</span>
      </div>

      <div class="flex flex-col gap-2">
        <span class="text-[11.5px] font-bold uppercase tracking-wider text-muted-3">Varian</span>
        <div class="overflow-hidden rounded-lg border border-line-2">
          <table class="w-full border-collapse text-[13px]">
            <thead>
              <tr class="bg-surface-subtle text-left">
                <th class="px-3 py-2 font-bold text-muted-2">SKU</th>
                <th class="px-3 py-2 font-bold text-muted-2">Nama</th>
                <th class="px-3 py-2 text-right font-bold text-muted-2">Harga jual</th>
                <th class="px-3 py-2 text-right font-bold text-muted-2">Stok</th>
                <th class="px-3 py-2 font-bold text-muted-2">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in product.variants" :key="v.id" class="border-t border-line-5">
                <td class="px-3 py-2 font-mono text-[12px]">{{ v.sku }}</td>
                <td class="px-3 py-2">{{ v.variant_name }}</td>
                <td class="px-3 py-2 text-right">{{ formatIDR(v.sell_price) }}</td>
                <td class="px-3 py-2 text-right font-semibold">{{ v.current_stock }}</td>
                <td class="px-3 py-2">
                  <StatusPill :variant="v.is_active ? 'mint' : 'neutral'">{{ v.is_active ? 'Aktif' : 'Nonaktif' }}</StatusPill>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </BaseModal>
</template>
