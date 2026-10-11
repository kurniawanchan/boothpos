<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { listTabs } from '../../api/tabs';
import { formatIDR } from '../../utils/money';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 028 US5 — menggabungkan tab lain (shift yang sama) KE tab ini. Hanya daftar pilihan; aturan
 * (shift sama, sumber belum ada pembayaran, kedua tab masih terbuka) ditegakkan server (409).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  tab: { type: Object, default: null },
  submitting: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'merge']);
const { t } = useI18n();

const candidates = ref([]);
const loading = ref(false);
const picked = ref(null);

watch(
  () => props.open,
  async (isOpen) => {
    picked.value = null;
    if (!isOpen || !props.tab) return;
    loading.value = true;
    try {
      const rows = (await listTabs({ session_id: props.tab.session_id })).data;
      candidates.value = rows.filter((r) => r.id !== props.tab.id);
    } catch {
      candidates.value = [];
    } finally {
      loading.value = false;
    }
  },
  { immediate: true }
);

const label = (r) => (r.table ? t('floor.tab.table_label', { name: r.table.name }) : r.tab_label || t('floor.takeaway'));
</script>

<template>
  <BaseModal :open="open" :title="t('floor.tab.merge_title')" max-width-class="max-w-[480px]" @close="emit('close')">
    <div class="flex max-h-[60vh] flex-col gap-3 overflow-auto px-6 py-5">
      <p class="text-[12.5px] text-muted-3">{{ t('floor.tab.merge_hint') }}</p>
      <p v-if="!loading && !candidates.length" class="text-[13.5px] text-muted-3">{{ t('floor.tab.merge_none') }}</p>
      <label
        v-for="r in candidates"
        :key="r.id"
        class="flex cursor-pointer items-center gap-3 rounded-lg border px-3.5 py-2.5"
        :class="picked === r.id ? 'border-brand bg-mint-100' : 'border-line bg-white'"
      >
        <input v-model="picked" type="radio" name="merge-source" :value="r.id" class="h-4 w-4 accent-brand" />
        <span class="flex-1">
          <span class="block text-[14px] font-bold text-ink">{{ label(r) }}</span>
          <span class="text-[12px] text-muted-3">{{ r.order_number }}</span>
        </span>
        <span class="text-[13.5px] font-bold">{{ formatIDR(r.total_amount) }}</span>
      </label>
    </div>
    <template #footer>
      <div class="flex justify-end gap-2.5">
        <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
        <BaseButton :disabled="picked === null" :loading="submitting" @click="emit('merge', picked)">{{ t('floor.tab.merge_confirm') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>
