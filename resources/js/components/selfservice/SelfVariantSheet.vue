<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import SelfSheet from './SelfSheet.vue';
import { formatIDR, parseMoney } from '../../utils/money';
import {
  allowsQuantity, canIncreaseQty, describeRange, groupRange, isGroupFull, isSelectionValid, optionQtyRange,
} from '../../utils/modifierRules';
import { makeLine, unitPrice } from '../../utils/selfServiceCart';

// 090 — lembar pilihan varian + modifier + jumlah + catatan untuk satu produk. Aturan pilihan (wajib, min/maks, jumlah per
// pilihan, porsi) memakai SATU sumber yang sama dengan POS (`utils/modifierRules.js`); server tetap memvalidasi ulang semuanya.
const props = defineProps({
  open: { type: Boolean, default: false },
  card: { type: Object, default: null },
});
const emit = defineEmits(['close', 'add']);
const { t } = useI18n();

const variantId = ref(null);
const selection = ref({}); // groupId → [optionId]
const quantities = ref({}); // optionId → qty
const qty = ref(1);
const note = ref('');

function reset() {
  variantId.value = props.card?.variants?.[0]?.id ?? null;
  selection.value = {};
  quantities.value = {};
  qty.value = 1;
  note.value = '';
}

watch(() => [props.open, props.card?.product_id], ([open]) => open && props.card && reset(), { immediate: true });

const variant = computed(() => props.card?.variants?.find((v) => v.id === variantId.value) ?? null);
const groups = computed(() => props.card?.modifier_groups ?? []);
const valid = computed(() => variant.value !== null && isSelectionValid(groups.value, selection.value, quantities.value));
const unit = computed(() => (variant.value ? unitPrice(variant.value, groups.value, selection.value, quantities.value) : 0));
const rangeText = (g) => describeRange(groupRange(g));

const picked = (g) => selection.value[g.id] ?? [];
const isPicked = (g, o) => picked(g).includes(o.id);

function toggle(g, o) {
  const current = [...picked(g)];
  const at = current.indexOf(o.id);
  const nextQty = { ...quantities.value };

  if (g.selection_type === 'single') {
    if (at >= 0) {
      if (g.is_required) return; // pilihan wajib tidak bisa dikosongkan, hanya diganti
      current.splice(at, 1);
      delete nextQty[o.id];
    } else {
      for (const other of g.options) delete nextQty[other.id];
      current.splice(0, current.length, o.id);
      nextQty[o.id] = optionQtyRange(o)[0];
    }
  } else if (at >= 0) {
    current.splice(at, 1);
    delete nextQty[o.id];
  } else {
    if (isGroupFull(g, current, nextQty)) return;
    current.push(o.id);
    nextQty[o.id] = optionQtyRange(o)[0];
  }

  quantities.value = nextQty;
  selection.value = { ...selection.value, [g.id]: current };
}

function stepOption(g, o, delta) {
  const cur = quantities.value[o.id] ?? 1;
  const [min] = optionQtyRange(o);
  if (delta > 0 && !canIncreaseQty(g, o, picked(g), quantities.value)) return;
  if (cur + delta < min) return;
  quantities.value = { ...quantities.value, [o.id]: cur + delta };
}

const canIncrease = (g, o) => canIncreaseQty(g, o, picked(g), quantities.value);

function add() {
  if (!valid.value || !props.card || !variant.value) return;
  emit('add', makeLine({ card: props.card, variant: variant.value, selection: selection.value, quantities: quantities.value, qty: qty.value, note: note.value }));
  emit('close');
}
</script>

<template>
  <SelfSheet :open="open && !!card" :title="card?.name ?? ''" @close="emit('close')">
    <div v-if="card" class="flex flex-col gap-5">
      <p v-if="card.description" class="text-[13px] text-muted">{{ card.description }}</p>

      <fieldset v-if="card.variants.length > 1" class="flex flex-col gap-2">
        <legend class="mb-1 text-[13px] font-extrabold text-ink">{{ t('self_service.choose_variant') }}</legend>
        <label
          v-for="v in card.variants"
          :key="v.id"
          class="flex min-h-12 cursor-pointer items-center justify-between gap-3 border px-3 py-2"
          :class="variantId === v.id ? 'border-brand bg-mint-50' : 'border-line bg-surface-soft'"
        >
          <span class="flex items-center gap-3">
            <input v-model="variantId" type="radio" :value="v.id" name="ss-variant" class="h-4 w-4 accent-brand" />
            <span class="text-[14px] font-semibold text-ink">{{ v.name }}</span>
          </span>
          <span class="text-[13px] font-bold text-muted-5">{{ formatIDR(v.price) }}</span>
        </label>
      </fieldset>

      <fieldset v-for="g in groups" :key="g.id" class="flex flex-col gap-2">
        <legend class="mb-1 flex flex-wrap items-center gap-2 text-[13px] font-extrabold text-ink">
          {{ g.name }}
          <span v-if="groupRange(g)[0] >= 1" class="bg-warn-bg px-2 py-0.5 text-[11px] font-bold text-warn-text">{{ t('self_service.required') }}</span>
          <span class="text-[11.5px] font-semibold text-muted">{{ t('self_service.choose_range', { range: rangeText(g) }) }}</span>
        </legend>
        <div
          v-for="o in g.options"
          :key="o.id"
          class="flex min-h-12 items-center justify-between gap-3 border px-3 py-2"
          :class="isPicked(g, o) ? 'border-brand bg-mint-50' : 'border-line bg-surface-soft'"
        >
          <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-3">
            <input
              :type="g.selection_type === 'single' ? 'radio' : 'checkbox'"
              :checked="isPicked(g, o)"
              :name="`ss-group-${g.id}`"
              class="h-4 w-4 accent-brand"
              @click.prevent="toggle(g, o)"
            />
            <span class="truncate text-[14px] font-semibold text-ink">{{ o.name }}</span>
          </label>
          <span v-if="parseMoney(o.price_delta) > 0" class="text-[12.5px] font-bold text-muted-5">+{{ formatIDR(o.price_delta) }}</span>
          <span v-if="isPicked(g, o) && allowsQuantity(o)" class="flex items-center gap-1">
            <button type="button" class="h-8 w-8 border border-line text-[16px] font-bold" :aria-label="t('self_service.decrease')" @click="stepOption(g, o, -1)">−</button>
            <span class="w-6 text-center text-[13px] font-bold" aria-live="polite">{{ quantities[o.id] ?? 1 }}</span>
            <button type="button" class="h-8 w-8 border border-line text-[16px] font-bold disabled:opacity-40" :disabled="!canIncrease(g, o)" :aria-label="t('self_service.increase')" @click="stepOption(g, o, 1)">+</button>
          </span>
        </div>
      </fieldset>

      <label class="flex flex-col gap-1.5">
        <span class="text-[13px] font-extrabold text-ink">{{ t('self_service.note') }}</span>
        <input
          v-model="note"
          type="text"
          maxlength="200"
          :placeholder="t('self_service.note_placeholder')"
          class="h-11 w-full border border-line bg-surface-soft px-3 text-[14px] outline-none focus:border-brand"
        />
      </label>
    </div>

    <template #footer>
      <div class="flex items-center gap-3">
        <div class="flex items-center gap-1" role="group" :aria-label="t('self_service.quantity')">
          <button type="button" class="h-12 w-12 border border-line text-[18px] font-bold disabled:opacity-40" :disabled="qty <= 1" :aria-label="t('self_service.decrease')" @click="qty -= 1">−</button>
          <span class="w-8 text-center text-[15px] font-extrabold" aria-live="polite">{{ qty }}</span>
          <button type="button" class="h-12 w-12 border border-line text-[18px] font-bold disabled:opacity-40" :disabled="qty >= 99" :aria-label="t('self_service.increase')" @click="qty += 1">+</button>
        </div>
        <BaseButton class="flex-1" size="lg" :disabled="!valid" @click="add">
          {{ t('self_service.add_to_cart') }} · {{ formatIDR(unit * qty) }}
        </BaseButton>
      </div>
    </template>
  </SelfSheet>
</template>
