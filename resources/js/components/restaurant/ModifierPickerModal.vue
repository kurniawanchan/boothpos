<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import { formatIDR, parseMoney } from '../../utils/money';
import { describeRange, groupRange, isSelectionValid, selectedOptionIds, selectionDelta } from '../../utils/modifierRules';

/**
 * 028 US3 — memilih modifier + catatan saat menambah item ke tab. Aturan
 * (wajib/opsional, tunggal/jamak, min/maks) ditegakkan di sini hanya sebagai
 * kemudahan; harga berjalan juga hanya tampilan — server menghitung ulang dan
 * menolak pelanggaran.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  itemName: { type: String, default: '' },
  basePrice: { type: [String, Number], default: '0' },
  groups: { type: Array, default: () => [] }, // grup efektif aktif beserta opsi aktif
  submitting: { type: Boolean, default: false },
  // 084 — POS ritel (POST /orders) tidak menyimpan catatan per baris; menyembunyikannya mencegah catatan yang hilang diam-diam.
  showNote: { type: Boolean, default: true },
});
const emit = defineEmits(['close', 'confirm']);
const { t } = useI18n();

const selection = reactive({});
const note = ref('');

watch(
  () => [props.open, props.groups],
  () => {
    if (!props.open) return;
    Object.keys(selection).forEach((k) => delete selection[k]);
    props.groups.forEach((g) => { selection[g.id] = []; });
    note.value = '';
  },
  { immediate: true }
);

const valid = computed(() => isSelectionValid(props.groups, selection));
const unitPrice = computed(() => parseMoney(String(props.basePrice)) + selectionDelta(props.groups, selection));
const negative = computed(() => unitPrice.value < 0);

const chosen = (g, option) => (selection[g.id] ?? []).includes(option.id);
const full = (g) => {
  const [, max] = groupRange(g);
  return max !== null && (selection[g.id] ?? []).length >= max;
};

function toggle(g, option) {
  const current = selection[g.id] ?? [];
  if (g.selection_type === 'single') {
    // Pilih lagi opsi yang sama pada grup OPSIONAL = membatalkan pilihan.
    selection[g.id] = chosen(g, option) && !g.is_required ? [] : [option.id];
    return;
  }
  if (chosen(g, option)) selection[g.id] = current.filter((id) => id !== option.id);
  else if (!full(g)) selection[g.id] = [...current, option.id];
}

const deltaLabel = (o) => {
  const n = Number(o.price_delta);
  if (!n) return '';
  return `${n > 0 ? '+' : '−'}${formatIDR(Math.abs(n))}`;
};

function confirm() {
  if (!valid.value || negative.value) return;
  emit('confirm', { modifier_option_ids: selectedOptionIds(selection), note: note.value.trim() || null });
}
</script>

<template>
  <BaseModal :open="open" :title="itemName" max-width-class="max-w-[520px]" @close="emit('close')">
    <div class="flex max-h-[70vh] flex-col gap-5 overflow-auto px-6 py-5">
      <fieldset v-for="g in groups" :key="g.id" class="flex flex-col gap-2" :data-testid="`group-${g.id}`">
        <legend class="mb-1 flex w-full items-baseline justify-between gap-2">
          <span class="text-[13.5px] font-extrabold text-ink">{{ g.name }}</span>
          <span class="text-[11.5px] font-semibold" :class="g.is_required ? 'text-danger-text' : 'text-muted-3'">
            {{ g.is_required ? t('floor.tab.mod_required') : t('floor.tab.mod_optional') }} · {{ t('floor.tab.mod_choose', { range: describeRange(groupRange(g)) }) }}
          </span>
        </legend>
        <label
          v-for="o in g.options"
          :key="o.id"
          class="flex cursor-pointer items-center gap-3 rounded-lg border px-3.5 py-2.5 text-[13.5px]"
          :class="[chosen(g, o) ? 'border-brand bg-mint-100' : 'border-line bg-white', !chosen(g, o) && g.selection_type === 'multiple' && full(g) ? 'cursor-not-allowed opacity-50' : '']"
        >
          <input
            :type="g.selection_type === 'single' ? 'radio' : 'checkbox'"
            :name="`group-${g.id}`"
            class="h-4 w-4 accent-brand"
            :checked="chosen(g, o)"
            :disabled="!chosen(g, o) && g.selection_type === 'multiple' && full(g)"
            @click="toggle(g, o)"
            @keydown.space.prevent="toggle(g, o)"
          />
          <span class="flex-1 font-semibold">{{ o.name }}</span>
          <span class="text-[12.5px] font-bold text-muted-4">{{ deltaLabel(o) }}</span>
        </label>
      </fieldset>

      <label v-if="showNote" class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-muted-4">
        {{ t('floor.tab.mod_note') }}
        <textarea
          v-model="note"
          rows="2"
          maxlength="255"
          :placeholder="t('floor.tab.note_placeholder')"
          class="rounded-lg border border-line bg-white px-3.5 py-2.5 text-[13.5px] font-normal outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
        ></textarea>
      </label>

      <p v-if="negative" role="alert" class="text-[12.5px] font-semibold text-danger-text">{{ t('floor.tab.mod_negative') }}</p>
    </div>
    <template #footer>
      <div class="flex items-center justify-between gap-3">
        <div class="text-[15px] font-extrabold" data-testid="modifier-price">{{ formatIDR(Math.max(0, unitPrice)) }}</div>
        <div class="flex gap-2.5">
          <BaseButton variant="secondary" @click="emit('close')">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :disabled="!valid || negative" :loading="submitting" @click="confirm">{{ t('floor.tab.mod_add') }}</BaseButton>
        </div>
      </div>
    </template>
  </BaseModal>
</template>
