<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import StatusPill from '../ui/StatusPill.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import { formatIDR } from '../../utils/money';

/**
 * Read-only single-variant detail — opened by clicking an individual SKU
 * in ProductsView.vue's list (as opposed to ProductDetailModal, which shows
 * every variant of a product at once). Purely presentational: the parent
 * already has everything needed (sku, variant_name, sell_price,
 * current_stock, is_active, image_url) from the same with_variants=1
 * response the list itself was built from, so no extra fetch here.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  variant: { type: Object, default: null },
  productName: { type: String, default: '' },
  artistName: { type: String, default: '' },
  categoryName: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const { t } = useI18n();

const lightboxOpen = ref(false);
</script>

<template>
  <BaseModal :open="open" :title="variant?.sku ?? t('master_data.variant_detail')" max-width-class="max-w-[420px]" @close="emit('close')">
    <div v-if="variant" class="flex flex-col gap-4 px-6 py-5">
      <div class="flex items-start gap-3.5">
        <button
          v-if="variant.image_url"
          type="button"
          class="h-20 w-20 flex-none cursor-zoom-in"
          :aria-label="t('master_data.enlarge_variant_image', { name: variant.variant_name })"
          @click="lightboxOpen = true"
        >
          <img :src="variant.image_url" :alt="variant.variant_name" class="h-20 w-20 rounded-lg border border-line-2 object-cover" />
        </button>
        <div v-else class="flex h-20 w-20 flex-none items-center justify-center rounded-lg border border-dashed border-disabled-2 text-muted-3">
          <i class="ph-duotone ph-image text-[26px]" aria-hidden="true"></i>
        </div>
        <div class="flex flex-1 flex-col gap-1">
          <span class="font-mono text-[12px] font-bold text-brand-active">{{ variant.sku }}</span>
          <span class="text-[15px] font-bold tracking-tight">{{ productName }}</span>
          <span class="text-[12.5px] text-muted-2">{{ variant.variant_name }}</span>
          <span v-if="artistName || categoryName" class="text-[12px] text-muted-3">{{ artistName }} · {{ categoryName }}</span>
          <StatusPill class="mt-1 w-fit" :variant="variant.is_active ? 'mint' : 'neutral'">{{ variant.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-3">
        <div class="flex flex-col gap-0.5 rounded-lg border border-line-2 px-3.5 py-3">
          <span class="text-[11px] font-bold uppercase tracking-wide text-muted-3">{{ t('master_data.col_sell_price') }}</span>
          <span class="text-[16px] font-extrabold tracking-tight">{{ formatIDR(variant.sell_price) }}</span>
        </div>
        <div class="flex flex-col gap-0.5 rounded-lg border border-line-2 px-3.5 py-3">
          <span class="text-[11px] font-bold uppercase tracking-wide text-muted-3">{{ t('master_data.col_stock') }}</span>
          <span class="text-[16px] font-extrabold tracking-tight">{{ variant.current_stock }}</span>
        </div>
      </div>
    </div>
  </BaseModal>

  <ImageLightbox :open="lightboxOpen" :src="variant?.image_url" :alt="variant?.variant_name" @close="lightboxOpen = false" />
</template>
