<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { createTransfer } from '../../api/stockTransfers';
import { lookupVariants } from '../../api/products';
import { listMaterials } from '../../api/materials';
import { useOutletStore } from '../../stores/outlet';
import { useToastStore } from '../../stores/toast';
import { useDebouncedFn } from '../../composables/useDebouncedFn';
import FormDrawer from '../page/FormDrawer.vue';
import FormSection from '../page/FormSection.vue';
import BaseSelect from '../ui/BaseSelect.vue';
import BaseInput from '../ui/BaseInput.vue';

/**
 * 045 Stage 6 (T093) — transfer baru (buat + kirim). Outlet asal/tujuan, lalu baris item atau bahan dengan jumlah
 * tersedia DI OUTLET ASAL (saldo dari server). Jumlah > tersedia ditahan di sini DAN di server (409/422 — server yang
 * memutuskan). `client_ref` dibuat sekali per pembukaan supaya kirim ulang (jaringan) tidak menggandakan transfer.
 */
const props = defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'created']);
const { t } = useI18n();
const toast = useToastStore();
const outletStore = useOutletStore();

const form = reactive({ from: '', to: '', note: '' });
const lines = ref([]);
const kind = ref('variant');
const search = ref('');
const results = ref([]);
const errors = reactive({});
const submitting = ref(false);
let clientRef = null;

const outletOptions = computed(() => outletStore.outlets.map((o) => ({ value: o.id, label: o.name })));
const toOptions = computed(() => outletOptions.value.filter((o) => o.value !== form.from));
const kindOptions = computed(() => [{ value: 'variant', label: t('transfers.kind_variant') }, { value: 'material', label: t('transfers.kind_material') }]);

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) return;
    Object.assign(form, { from: outletStore.selected ?? '', to: '', note: '' });
    lines.value = [];
    results.value = [];
    search.value = '';
    Object.keys(errors).forEach((k) => delete errors[k]);
    clientRef = globalThis.crypto?.randomUUID?.() ?? `ref-${Date.now()}-${Math.random()}`;
  },
  { immediate: true }
);
watch(() => form.from, () => { lines.value = []; results.value = []; });

const runSearch = useDebouncedFn(async () => {
  const term = search.value.trim();
  if (!term || !form.from) { results.value = []; return; }
  if (kind.value === 'variant') {
    const res = await lookupVariants(term, 8, { stockOutlet: form.from });
    results.value = (res.data ?? []).map((v) => ({ kind: 'variant', item_id: v.variant_id, label: v.label, sku: v.sku, available: Number(v.outlet_stock ?? 0) }));
  } else {
    const res = await listMaterials({ search: term, per_page: 8 });
    results.value = (res.data ?? []).map((m) => ({
      kind: 'material', item_id: m.id, label: m.name, sku: m.code,
      available: Number((m.outlet_stock ?? []).find((o) => o.outlet_id === form.from)?.on_hand ?? 0),
    }));
  }
}, 300);

function add(item) {
  if (lines.value.some((l) => l.kind === item.kind && l.item_id === item.item_id)) return;
  lines.value.push({ ...item, qty: '' });
  search.value = '';
  results.value = [];
}
const remove = (i) => lines.value.splice(i, 1);
const lineValid = (l) => Number(l.qty) > 0 && Number(l.qty) <= l.available && (l.kind === 'material' || Number.isInteger(Number(l.qty)));
const valid = computed(() => !!form.from && !!form.to && form.from !== form.to && lines.value.length > 0 && lines.value.every(lineValid));

async function submit() {
  if (!valid.value || submitting.value) return;
  submitting.value = true;
  Object.keys(errors).forEach((k) => delete errors[k]);
  try {
    const res = await createTransfer({
      from_outlet_id: form.from, to_outlet_id: form.to, note: form.note.trim() || null, client_ref: clientRef,
      lines: lines.value.map((l) => ({ kind: l.kind, item_id: l.item_id, qty: Number(l.qty) })),
    });
    toast.success(t('transfers.dispatched_done', { number: res.number }));
    emit('created', res);
    emit('close');
  } catch (err) {
    if (err?.isValidation) Object.assign(errors, Object.fromEntries(Object.entries(err.errors ?? {}).map(([k, v]) => [k, v[0]])));
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <FormDrawer :open="open" :title="t('transfers.new_title')" :subtitle="t('transfers.new_subtitle')" :submit-label="t('transfers.dispatch')" :submitting="submitting" max-width-class="max-w-[720px]" @close="emit('close')" @submit="submit">
    <FormSection :title="t('transfers.route')">
      <BaseSelect v-model="form.from" :label="t('transfers.from')" :options="outletOptions" :placeholder="t('transfers.choose_outlet')" :error="errors.from_outlet_id" data-testid="transfer-from" />
      <BaseSelect v-model="form.to" :label="t('transfers.to')" :options="toOptions" :placeholder="t('transfers.choose_outlet')" :error="errors.to_outlet_id" data-testid="transfer-to" />
      <BaseInput v-model="form.note" :label="t('transfers.note')" maxlength="255" />
    </FormSection>

    <FormSection :title="t('transfers.lines')">
      <div v-for="(l, i) in lines" :key="`${l.kind}-${l.item_id}`" class="flex items-center gap-3 border-2 border-line p-3" data-testid="transfer-line">
        <div class="min-w-0 flex-1">
          <span class="block truncate text-[13.5px] font-semibold">{{ l.label }}</span>
          <span class="font-mono text-[11.5px] text-muted-3">{{ l.sku }} · {{ t('transfers.available_n', { n: l.available }) }}</span>
          <span v-if="errors[`lines.${i}.qty`] || errors[`lines.${i}`]" class="block text-[12px] font-semibold text-danger-text">{{ errors[`lines.${i}.qty`] || errors[`lines.${i}`] }}</span>
        </div>
        <input v-model="l.qty" type="number" step="any" min="0" class="h-10 w-28 border-2 border-line px-2 text-right tabular-nums" :aria-label="t('transfers.qty_for', { name: l.label })" />
        <button type="button" class="text-[12.5px] font-semibold text-danger-text" @click="remove(i)">{{ t('common.delete') }}</button>
      </div>

      <BaseSelect v-model="kind" :label="t('transfers.kind')" :options="kindOptions" @update:model-value="() => { results = []; search = ''; }" />
      <div class="relative">
        <BaseInput v-model="search" :label="t('transfers.search_item')" :disabled="!form.from" data-testid="transfer-search" @input="runSearch" />
        <div v-if="results.length" class="absolute z-10 mt-1 w-full border-2 border-line bg-white shadow-lg">
          <button v-for="r in results" :key="`${r.kind}-${r.item_id}`" type="button" class="flex w-full flex-col px-3 py-2 text-left hover:bg-line-7" @click="add(r)">
            <span class="text-[13px] font-semibold">{{ r.label }}</span>
            <span class="font-mono text-[11px] text-muted-3">{{ r.sku }} · {{ t('transfers.available_n', { n: r.available }) }}</span>
          </button>
        </div>
      </div>
      <p v-if="errors.lines" class="text-[12px] font-semibold text-danger-text">{{ errors.lines }}</p>
    </FormSection>
  </FormDrawer>
</template>
