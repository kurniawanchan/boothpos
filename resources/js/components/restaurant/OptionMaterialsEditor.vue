<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseSelect from '../ui/BaseSelect.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 087 — bahan yang dipakai SATU pilihan modifier per porsi. Bahan dipotong dari stok outlet saat pesanan
 * selesai; jumlah memakai satuan bahan itu sendiri (tanpa konversi). Baris yang bahannya nonaktif tetap
 * ditampilkan dengan penanda supaya pengelola tahu pemetaan itu sedang tidak memotong apa pun.
 *
 * `materials` = bahan aktif yang bisa dipilih: [{ id, name, unit }].
 * `errors` = galat formulir yang kuncinya sudah dipotong ke awalan pilihan ini (mis. `0.material_id`).
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  materials: { type: Array, default: () => [] },
  errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();

const byId = computed(() => new Map(props.materials.map((m) => [m.id, m])));

// Bahan nonaktif yang sudah dipetakan tetap harus terlihat di pilihan select, kalau tidak nilainya kosong.
function optionsFor(row) {
  const opts = props.materials.map((m) => ({ value: m.id, label: `${m.name} (${m.unit})` }));
  if (row.material_id && !byId.value.has(row.material_id)) {
    opts.unshift({ value: row.material_id, label: `${row.name ?? '#' + row.material_id} — ${t('dining_setup.option_materials_inactive')}` });
  }
  return opts;
}

const unitOf = (row) => byId.value.get(row.material_id)?.unit ?? row.unit ?? '';
const inactive = (row) => row.material_id && !byId.value.has(row.material_id);

function update(idx, patch) {
  emit('update:modelValue', props.modelValue.map((r, i) => (i === idx ? { ...r, ...patch } : r)));
}
const add = () => emit('update:modelValue', [...props.modelValue, { material_id: '', quantity: '1' }]);
const remove = (idx) => emit('update:modelValue', props.modelValue.filter((_, i) => i !== idx));
</script>

<template>
  <div class="flex flex-col gap-2 rounded-lg border border-line-2 bg-surface-subtle px-3 py-2.5" data-testid="option-materials">
    <p class="text-[11.5px] text-muted-3">{{ t('dining_setup.option_materials_hint') }}</p>
    <div v-for="(row, idx) in modelValue" :key="idx" class="grid grid-cols-[1fr_96px_auto_auto] items-end gap-2" data-testid="option-material-row">
      <BaseSelect
        :model-value="row.material_id"
        :options="optionsFor(row)"
        searchable
        :placeholder="t('dining_setup.option_materials_pick')"
        :aria-label="t('dining_setup.option_materials_material')"
        :error="errors[`${idx}.material_id`]"
        @update:model-value="(v) => update(idx, { material_id: v })"
      />
      <BaseInput
        :model-value="row.quantity"
        type="number"
        min="0.001"
        step="0.001"
        :aria-label="t('dining_setup.option_materials_qty')"
        :error="errors[`${idx}.quantity`]"
        @update:model-value="(v) => update(idx, { quantity: v })"
      />
      <span class="mb-3 min-w-[44px] text-[12px] font-semibold text-muted-4" data-testid="option-material-unit">
        {{ unitOf(row) }}
        <span v-if="inactive(row)" class="ml-1 text-danger-text">· {{ t('dining_setup.option_materials_inactive') }}</span>
      </span>
      <button type="button" class="mb-0.5 flex h-[38px] w-[38px] items-center justify-center rounded-lg border border-line-2 text-danger-text hover:bg-danger-bg" :aria-label="t('common.delete')" @click="remove(idx)">
        <i class="ph-duotone ph-x text-[14px]" aria-hidden="true"></i>
      </button>
    </div>
    <BaseButton variant="secondary" size="sm" class="self-start" @click="add">
      <i class="ph-duotone ph-plus text-[14px]" aria-hidden="true"></i>
      {{ t('dining_setup.option_materials_add') }}
    </BaseButton>
  </div>
</template>
