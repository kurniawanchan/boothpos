<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import StatusPill from '../ui/StatusPill.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import VariantBomModal from './VariantBomModal.vue';
import VariantHistoryModal from './VariantHistoryModal.vue';
import { getProduct } from '../../api/products';
import { formatIDR } from '../../utils/money';
import { useToastStore } from '../../stores/toast';
import { useAuthStore } from '../../stores/auth';

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
  // 036: varian yang disorot (mis. dari klik SKU di layar Stok); digulung ke tengah pandangan.
  highlightVariantId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);

const toast = useToastStore();
const { t } = useI18n();
const auth = useAuthStore();
const product = ref(null);
const loading = ref(false);

const showBom = ref(false);
const bomVariant = ref(null);
function openBom(variant) {
  bomVariant.value = variant;
  showBom.value = true;
}

// 036 — riwayat transaksi per varian (hanya-baca); butuh akses stok ATAU produk, sama dengan endpoint-nya.
const showHistory = ref(false);
const historyVariant = ref(null);
const canSeeHistory = computed(() => auth.canAccessMenu('stock') || auth.canAccessMenu('products'));
const showActionsColumn = computed(() => canSeeHistory.value || auth.canAccessMenu('products'));
function openHistory(variant) {
  historyVariant.value = variant;
  showHistory.value = true;
}

// Sorot + gulung ke varian yang diminta begitu produknya selesai dimuat.
watch(
  () => [product.value, props.highlightVariantId],
  async ([loaded, variantId]) => {
    if (!loaded || !variantId) return;
    await nextTick();
    document.querySelector('tr[aria-current="true"]')?.scrollIntoView?.({ block: 'nearest' });
  },
);

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
      toast.error(err.message || t('master_data.load_product_detail_failed'));
    } finally {
      loading.value = false;
    }
  },
  { immediate: true }
);

const totalStock = computed(() => (product.value?.variants ?? []).reduce((sum, v) => sum + Number(v.current_stock ?? 0), 0));

const lightboxOpen = ref(false);
const lightboxSrc = ref(null);
const lightboxAlt = ref('');
function openImageLightbox(src, alt) {
  if (!src) return;
  lightboxSrc.value = src;
  lightboxAlt.value = alt;
  lightboxOpen.value = true;
}
</script>

<template>
  <BaseModal :open="open" :title="product?.name ?? t('master_data.product_detail')" max-width-class="max-w-[860px]" @close="emit('close')">
    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">{{ t('master_data.loading_product_detail') }}</div>
    <div v-else-if="product" class="flex flex-col gap-4 px-6 py-5">
      <div class="flex items-start gap-3.5">
        <button
          v-if="product.image_url"
          type="button"
          class="h-20 w-20 flex-none cursor-zoom-in"
          :aria-label="t('master_data.enlarge_product_image', { name: product.name })"
          @click="openImageLightbox(product.image_url, product.name)"
        >
          <img
            :src="product.image_url"
            :alt="product.name"
            class="h-20 w-20 rounded-lg border border-line-2 object-cover"
          />
        </button>
        <div v-else class="flex h-20 w-20 flex-none items-center justify-center rounded-lg border border-dashed border-disabled-2 text-muted-3">
          <i class="ph-duotone ph-image text-[26px]" aria-hidden="true"></i>
        </div>
        <div class="flex flex-1 flex-col gap-1">
          <span class="font-mono text-[12px] font-bold text-brand-active">{{ product.code_prefix }}</span>
          <span class="text-[15px] font-bold tracking-tight">{{ product.name }}</span>
          <span class="text-[12.5px] text-muted-2">{{ product.artist_name }} · {{ product.category_name }}</span>
          <div class="flex gap-1.5">
            <StatusPill :variant="product.is_preorder ? 'warn' : 'neutral'">{{ product.is_preorder ? t('master_data.preorder') : t('master_data.ready_stock') }}</StatusPill>
            <StatusPill :variant="product.is_active ? 'mint' : 'neutral'">{{ product.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
          </div>
        </div>
      </div>

      <p v-if="product.description" class="text-[13px] leading-relaxed text-muted-4">{{ product.description }}</p>

      <div class="flex items-center justify-between rounded-lg border border-mint-border bg-mint-50 px-4 py-3">
        <span class="text-[12.5px] font-semibold text-brand-active">{{ t('master_data.total_stock_available') }}</span>
        <span class="text-[21px] font-extrabold tracking-tight text-brand-active">{{ totalStock }}</span>
      </div>

      <div class="flex flex-col gap-2">
        <span class="text-[11.5px] font-bold uppercase tracking-wider text-muted-3">{{ t('master_data.variants') }}</span>
        <div class="overflow-hidden rounded-lg border border-line-2">
          <table class="w-full border-collapse text-[13px]">
            <thead>
              <tr class="bg-surface-subtle text-left">
                <th class="px-3 py-2"></th>
                <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.col_sku') }}</th>
                <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.col_name') }}</th>
                <th class="px-3 py-2 text-right font-bold text-muted-2">{{ t('master_data.col_sell_price') }}</th>
                <th class="px-3 py-2 text-right font-bold text-muted-2">{{ t('master_data.col_stock') }}</th>
                <th class="px-3 py-2 font-bold text-muted-2">{{ t('master_data.col_status') }}</th>
                <th v-if="showActionsColumn" class="px-3 py-2"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="v in product.variants"
                :key="v.id"
                class="border-t border-line-5 transition-colors hover:bg-line-7"
                :class="Number(v.id) === Number(highlightVariantId) ? 'bg-mint-50' : ''"
                :aria-current="Number(v.id) === Number(highlightVariantId) ? 'true' : undefined"
              >
                <td class="px-3 py-2">
                  <button
                    v-if="v.image_url"
                    type="button"
                    class="h-8 w-8 cursor-zoom-in"
                    :aria-label="t('master_data.enlarge_variant_image', { name: v.variant_name })"
                    @click="openImageLightbox(v.image_url, v.variant_name)"
                  >
                    <img :src="v.image_url" :alt="v.variant_name" class="h-8 w-8 rounded-md border border-line-2 object-cover" />
                  </button>
                  <div v-else class="flex h-8 w-8 items-center justify-center rounded-md border border-line-2 bg-surface-subtle text-muted-3">
                    <i class="ph-duotone ph-image text-[13px]" aria-hidden="true"></i>
                  </div>
                </td>
                <td class="px-3 py-2 font-mono text-[12px]">{{ v.sku }}</td>
                <td class="px-3 py-2">{{ v.variant_name }}</td>
                <td class="px-3 py-2 text-right">{{ formatIDR(v.sell_price) }}</td>
                <td class="px-3 py-2 text-right font-semibold">{{ v.current_stock }}</td>
                <td class="px-3 py-2">
                  <StatusPill :variant="v.is_active ? 'mint' : 'neutral'">{{ v.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
                </td>
                <td v-if="showActionsColumn" class="px-3 py-2 text-right">
                  <div class="flex items-center justify-end gap-3">
                    <button v-if="canSeeHistory" type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openHistory(v)">{{ t('master_data.history') }}</button>
                    <button v-if="auth.canAccessMenu('products')" type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openBom(v)">{{ t('master_data.bom') }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </BaseModal>

  <VariantBomModal
    :open="showBom"
    :variant-id="bomVariant?.id"
    :variant-sku="bomVariant?.sku"
    :variant-name="bomVariant?.variant_name"
    :siblings="product?.variants ?? []"
    @close="showBom = false"
  />

  <VariantHistoryModal
    :open="showHistory"
    :variant-id="historyVariant?.id"
    :variant-sku="historyVariant?.sku"
    :variant-name="historyVariant?.variant_name"
    @close="showHistory = false"
  />

  <ImageLightbox :open="lightboxOpen" :src="lightboxSrc" :alt="lightboxAlt" @close="lightboxOpen = false" />
</template>
