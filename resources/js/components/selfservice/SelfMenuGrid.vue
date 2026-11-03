<script setup>
import { useI18n } from 'vue-i18n';
import SelfProductCard from './SelfProductCard.vue';

// 090 — pencarian + chip kategori + grid dua kolom. Murni presentasi: keadaan (query, kategori) milik store.
defineProps({
  cards: { type: Array, required: true },
  categories: { type: Array, default: () => [] },
  query: { type: String, default: '' },
  categoryId: { type: Number, default: null },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['update:query', 'update:categoryId', 'pick']);
const { t } = useI18n();
</script>

<template>
  <div class="flex flex-col gap-3">
    <label class="relative block">
      <span class="sr-only">{{ t('self_service.search_placeholder') }}</span>
      <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[18px] text-muted-3" aria-hidden="true"></i>
      <input
        type="search"
        :value="query"
        :placeholder="t('self_service.search_placeholder')"
        class="h-11 w-full border border-line bg-surface-soft pl-10 pr-3 text-[14px] text-ink outline-none focus:border-brand"
        autocomplete="off"
        @input="emit('update:query', $event.target.value)"
      />
    </label>

    <div v-if="categories.length" class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1" role="tablist">
      <button
        type="button"
        role="tab"
        :aria-selected="categoryId === null"
        class="h-9 shrink-0 border px-3 text-[13px] font-bold"
        :class="categoryId === null ? 'border-brand bg-brand text-white' : 'border-line bg-surface-soft text-muted-5'"
        @click="emit('update:categoryId', null)"
      >
        {{ t('self_service.all_categories') }}
      </button>
      <button
        v-for="c in categories"
        :key="c.id"
        type="button"
        role="tab"
        :aria-selected="categoryId === c.id"
        class="h-9 shrink-0 border px-3 text-[13px] font-bold"
        :class="categoryId === c.id ? 'border-brand bg-brand text-white' : 'border-line bg-surface-soft text-muted-5'"
        @click="emit('update:categoryId', c.id)"
      >
        {{ c.name }}
      </button>
    </div>

    <p v-if="loading && !cards.length" class="py-10 text-center text-[13px] text-muted">{{ t('self_service.loading') }}</p>
    <p v-else-if="!cards.length" class="py-10 text-center text-[13px] text-muted">{{ t('self_service.empty_menu') }}</p>
    <div v-else class="grid grid-cols-2 gap-3" :class="loading ? 'opacity-60' : ''">
      <SelfProductCard v-for="card in cards" :key="card.product_id" :card="card" @pick="emit('pick', $event)" />
    </div>
  </div>
</template>
