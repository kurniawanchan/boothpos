<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';

// 090 — kartu menu untuk ponsel. Kartu "habis" tidak bisa dipilih. Foto gagal dimuat atau kosong → inisial nama.
const props = defineProps({ card: { type: Object, required: true } });
const emit = defineEmits(['pick']);
const { t } = useI18n();

const broken = ref(false);
const initial = computed(() => props.card.name.trim().slice(0, 2).toUpperCase());
const priceText = computed(() => {
  const { min_price: min, max_price: max } = props.card;
  return min === max ? formatIDR(min) : t('self_service.from_price', { price: formatIDR(min) });
});
</script>

<template>
  <button
    type="button"
    :disabled="card.sold_out"
    class="relative flex min-w-0 flex-col overflow-hidden border border-line bg-surface-soft text-left transition-colors enabled:active:border-brand disabled:cursor-not-allowed"
    :class="card.sold_out ? 'opacity-60' : ''"
    @click="emit('pick', card)"
  >
    <div class="relative aspect-[4/3] w-full bg-line-7">
      <img
        v-if="card.image_url && !broken"
        :src="card.image_url"
        alt=""
        loading="lazy"
        class="h-full w-full object-cover"
        @error="broken = true"
      />
      <span v-else class="flex h-full w-full items-center justify-center text-[26px] font-extrabold text-muted-3">{{ initial }}</span>
      <span v-if="card.sold_out" class="absolute left-2 top-2 bg-danger-bg px-2 py-0.5 text-[11px] font-bold text-danger-text">
        {{ t('self_service.sold_out') }}
      </span>
      <span v-else-if="card.variant_count > 1" class="absolute left-2 top-2 bg-white/90 px-2 py-0.5 text-[11px] font-bold text-muted-5">
        {{ t('self_service.variants_badge', { n: card.variant_count }) }}
      </span>
    </div>
    <div class="flex flex-1 flex-col gap-1 p-3">
      <span class="line-clamp-2 text-[13.5px] font-bold leading-snug text-ink">{{ card.name }}</span>
      <span class="mt-auto text-[13px] font-extrabold text-brand">{{ priceText }}</span>
    </div>
  </button>
</template>
