<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { cellInputClass } from './formStyle';
import { applyRequired, rowFromGroup, blankLinkRow } from './modifierLinkRows';

/**
 * 043 — tabel "Grup modifier" (formulir buat DAN ubah item): GRUP (select bawaan) · WAJIB · MIN · MAKS · ×, tautan
 * "+ Tautkan grup" di dalam bingkai. Baris dimutasi langsung (konvensi ProductCreateVariants); urutan baris =
 * urutan tampil (`display_order`). Satu grup tidak boleh dipilih dua kali: tiap select hanya menawarkan grup yang
 * belum dipakai baris lain. Memilih grup mengisi aturan awal dari grup itu sendiri.
 */
const props = defineProps({
  rows: { type: Array, required: true },
  groups: { type: Array, required: true },
  errors: { type: Object, default: () => ({}) }, // { [indeks]: { min, max } }
});
const emit = defineEmits(['add', 'remove']);
const { t } = useI18n();

const groupById = computed(() => new Map(props.groups.map((g) => [g.id, g])));

function optionsFor(row) {
  const taken = new Set(props.rows.filter((r) => r !== row).map((r) => r.group_id));
  return props.groups.filter((g) => (g.is_active !== false && !taken.has(g.id)) || g.id === row.group_id);
}

function onPick(row, value) {
  const group = groupById.value.get(Number(value));
  if (!group) {
    Object.assign(row, blankLinkRow());
    return;
  }
  Object.assign(row, rowFromGroup(group));
}
</script>

<template>
  <div class="flex flex-col gap-1.5" data-testid="create-modifiers">
    <!-- Belum ada grup sama sekali: bagian tetap tampil (tata letak sama dengan desain) tetapi tanpa tabel yang tak bisa diisi. -->
    <p v-if="!groups.length" class="border border-line bg-surface-subtle px-3 py-3 text-[12.5px] text-muted-4" data-testid="create-modifiers-empty">{{ t('products.f_modifier_none') }}</p>
    <div v-else class="border border-line">
      <table class="w-full table-fixed border-collapse text-left">
        <thead>
          <tr class="bg-surface-subtle text-[11px] font-semibold uppercase tracking-[0.05em] text-muted">
            <th scope="col" class="border-b border-r border-line-2 px-3 py-2">{{ t('products.col_group') }}</th>
            <th scope="col" class="w-[72px] border-b border-r border-line-2 px-2 py-2 text-center">{{ t('products.ml_col_required') }}</th>
            <th scope="col" class="w-[80px] border-b border-r border-line-2 px-3 py-2">{{ t('products.ml_col_min') }}</th>
            <th scope="col" class="w-[96px] border-b border-line-2 px-3 py-2">{{ t('products.ml_col_max') }}</th>
            <th scope="col" class="w-[36px] border-b border-l border-line-2" aria-hidden="true"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in rows" :key="i" data-testid="create-modifier-row">
            <td class="border-b border-r border-line-2 p-0">
              <select
                :value="row.group_id"
                :class="[cellInputClass, 'cursor-pointer']"
                :aria-label="t('products.f_modifier_group_aria', { n: i + 1 })"
                @change="onPick(row, $event.target.value)"
              >
                <option value="">{{ t('common.select_placeholder') }}</option>
                <option v-for="g in optionsFor(row)" :key="g.id" :value="g.id">{{ g.name }}</option>
              </select>
            </td>
            <td class="border-b border-r border-line-2 text-center">
              <input
                type="checkbox"
                class="h-4 w-4 accent-brand"
                :checked="row.is_required"
                :disabled="!row.group_id"
                :aria-label="t('products.f_modifier_required_aria', { n: i + 1 })"
                @change="applyRequired(row, $event.target.checked)"
              />
            </td>
            <td class="border-b border-r border-line-2 p-0">
              <input v-model="row.min" type="number" step="1" :class="[cellInputClass, errors[i]?.min ? 'bg-danger-bg' : '']" :aria-label="t('products.f_modifier_min_aria', { n: i + 1 })" />
            </td>
            <td class="border-b border-line-2 p-0">
              <input
                v-model="row.max"
                type="number"
                step="1"
                :placeholder="t('products.ml_unlimited')"
                :class="[cellInputClass, errors[i]?.max ? 'bg-danger-bg' : '']"
                :aria-label="t('products.f_modifier_max_aria', { n: i + 1 })"
              />
            </td>
            <td class="border-b border-l border-line-2 text-center">
              <button type="button" class="text-muted-3 hover:text-danger-text" :aria-label="t('products.f_modifier_remove_aria', { n: i + 1 })" @click="emit('remove', i)">
                <i class="ph-duotone ph-x text-[12px]" aria-hidden="true"></i>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <button type="button" class="block w-full px-3 py-2.5 text-left text-[12.5px] font-semibold text-brand hover:text-brand-active" @click="emit('add')">
        {{ t('products.f_link_group') }}
      </button>
    </div>
    <template v-for="(row, i) in rows" :key="`e${i}`">
      <p v-if="errors[i]?.min || errors[i]?.max" role="alert" class="text-[12px] font-medium text-danger-text">{{ errors[i].min || errors[i].max }}</p>
    </template>
    <p v-if="groups.length" class="text-[11.5px] text-muted-3">{{ t('products.f_modifier_hint') }}</p>
  </div>
</template>
