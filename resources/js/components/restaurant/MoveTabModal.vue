<script setup>
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFloorStore } from '../../stores/floor';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 028 US5 — memindahkan tab ke meja KOSONG lain. Daftar meja kosong diambil dari lantai
 * (dimuat penuh saat dialog dibuka agar tidak basi); server tetap memeriksa ulang dan
 * menolak bila ada yang lebih dulu duduk di meja itu (409).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  currentTableId: { type: [Number, null], default: null },
  submitting: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'move']);
const { t } = useI18n();
const floor = useFloorStore();

watch(() => props.open, (isOpen) => { if (isOpen) floor.fetch({ full: true }).catch(() => {}); }, { immediate: true });

const groups = computed(() =>
  floor.areas
    .map((g) => ({ area: g.area, tables: g.tables.filter((tb) => tb.state === 'free' && tb.id !== props.currentTableId) }))
    .filter((g) => g.tables.length)
);
const areaLabel = (area) => area || t('floor.no_area');
</script>

<template>
  <BaseModal :open="open" :title="t('floor.tab.move_title')" max-width-class="max-w-[520px]" @close="emit('close')">
    <div class="flex max-h-[65vh] flex-col gap-4 overflow-auto px-6 py-5">
      <p v-if="!groups.length" class="text-[13.5px] text-muted-3">{{ t('floor.tab.move_none') }}</p>
      <section v-for="g in groups" :key="g.area" :aria-label="areaLabel(g.area)" class="flex flex-col gap-2">
        <h3 class="text-[12px] font-extrabold uppercase tracking-wide text-muted-3">{{ areaLabel(g.area) }}</h3>
        <div class="grid grid-cols-[repeat(auto-fill,minmax(96px,1fr))] gap-2">
          <button
            v-for="tb in g.tables"
            :key="tb.id"
            type="button"
            class="rounded-lg border border-line bg-white px-3 py-3 text-center text-[15px] font-extrabold text-ink hover:border-brand hover:bg-mint-100 disabled:opacity-50"
            :disabled="submitting"
            :aria-label="t('floor.tab.move_to', { name: tb.name })"
            @click="emit('move', tb.id)"
          >
            {{ tb.name }}
            <span class="block text-[11px] font-semibold text-muted-3">{{ t('floor.seats', { n: tb.seats }) }}</span>
          </button>
        </div>
      </section>
    </div>
    <template #footer>
      <div class="flex justify-end"><BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton></div>
    </template>
  </BaseModal>
</template>
