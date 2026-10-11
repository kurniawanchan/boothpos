<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/formatters';
import { cardLine } from '../../utils/itemSummary';
import StatusPill from '../ui/StatusPill.vue';
import AvailabilityChips from './AvailabilityChips.vue';

/**
 * 043 (T033) — satu kartu item pada tampilan Grid: foto (atau penanda tempat), lencana status di kanan atas, nama,
 * harga dasar, satu baris redup varian/modifier, lalu chip ketersediaan. Seluruh area kartu adalah SATU target tautan
 * ke halaman item (bisa difokus, Enter membukanya). Tautan "Edit" ada di luar tautan kartu (elemen interaktif tidak boleh
 * bersarang), menuju halaman item yang sama (bukan laci ubah), dan hanya dirender bila `canEdit` — disembunyikan,
 * bukan dinonaktifkan.
 */
const props = defineProps({
  item: { type: Object, required: true },
  canEdit: { type: Boolean, default: false },
});
const { t } = useI18n();

const STATUS = {
  active: { tone: 'success', key: 'products.status_active' },
  draft: { tone: 'warning', key: 'products.status_draft' },
  discontinued: { tone: 'neutral', key: 'products.status_discontinued' },
};
const status = computed(() => STATUS[props.item.status] ?? STATUS.active);
const price = computed(() => (props.item.base_price === null || props.item.base_price === undefined ? '—' : formatIDR(props.item.base_price)));
</script>

<template>
  <article class="relative flex flex-col border-2 border-line bg-white transition-colors focus-within:border-brand hover:border-brand" data-testid="item-card">
    <RouterLink :to="{ name: 'product-detail', params: { id: item.id } }" class="flex flex-1 flex-col outline-none" :aria-label="item.name">
      <div class="relative flex aspect-[4/3] items-center justify-center overflow-hidden bg-surface-subtle">
        <img v-if="item.image_url" :src="item.image_url" :alt="item.name" class="h-full w-full object-cover" loading="lazy" />
        <span v-else class="flex flex-col items-center gap-1 text-muted-3" data-testid="item-card-placeholder">
          <i class="ph-duotone ph-image text-[30px]" aria-hidden="true"></i>
          <span class="text-[11.5px]">{{ t('products.photo_placeholder') }}</span>
        </span>
        <StatusPill :variant="status.tone" class="absolute right-2 top-2">{{ t(status.key) }}</StatusPill>
      </div>
      <div class="flex flex-col gap-1 px-3.5 pt-3">
        <span class="line-clamp-2 text-[14px] font-bold leading-snug">{{ item.name }}</span>
        <span class="text-[13px] font-semibold tabular-nums text-muted-5">{{ price }}</span>
        <span class="truncate text-[12px] text-muted" :title="cardLine(item, t)">{{ cardLine(item, t) }}</span>
      </div>
    </RouterLink>
    <div class="flex items-end justify-between gap-2 px-3.5 pb-3 pt-2.5">
      <AvailabilityChips :availability="item.availability" />
      <RouterLink
        v-if="canEdit"
        :to="{ name: 'product-detail', params: { id: item.id } }"
        class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active focus-visible:text-brand-active focus-visible:outline-none"
        :aria-label="t('products.edit_item', { name: item.name })"
      >
        {{ t('common.edit') }}
      </RouterLink>
    </div>
  </article>
</template>
