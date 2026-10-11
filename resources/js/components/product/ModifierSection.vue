<script setup>
import { useI18n } from 'vue-i18n';
import { modifierRuleText } from '../../utils/itemDetail';
import ItemSection from './ItemSection.vue';
import SectionTable from './SectionTable.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 043 (US2) — "Grup modifier": Urutan · Grup · Aturan ("Opsional · 0–1" / "Wajib · 1–2") · Opsi. Aturan adalah
 * nilai EFEKTIF dari server (tautan item menimpa grup). "Kelola modifier" hanya dirender bila `canEdit` (objek `can.edit`
 * dari server; tersembunyi, bukan nonaktif) dan meminta pemanggil membuka ModifierLinksDrawer.
 */
defineProps({
  links: { type: Array, default: () => [] },
  canEdit: { type: Boolean, default: false },
});
defineEmits(['manage']);
const { t } = useI18n();
</script>

<template>
  <ItemSection :title="t('products.sec_modifiers')" testid="section-modifiers">
    <template v-if="canEdit" #action>
      <BaseButton variant="secondary" size="sm" data-testid="modifier-manage" @click="$emit('manage')">{{ t('products.manage_modifiers') }}</BaseButton>
    </template>
    <p v-if="!links.length" class="px-5 py-6 text-[13px] text-muted-4">{{ t('products.modifiers_empty') }}</p>
    <SectionTable v-else>
      <template #head>
        <tr>
          <th scope="col">{{ t('products.col_order') }}</th>
          <th scope="col">{{ t('products.col_group') }}</th>
          <th scope="col">{{ t('products.col_rule') }}</th>
          <th scope="col">{{ t('products.col_options') }}</th>
        </tr>
      </template>
      <tr v-for="(l, i) in links" :key="l.group_id" data-testid="modifier-row">
        <td class="tabular-nums text-muted-4">{{ i + 1 }}</td>
        <td class="font-semibold">{{ l.name }}</td>
        <td data-testid="modifier-rule">{{ modifierRuleText(l, t) }}</td>
        <td class="text-muted-4">{{ (l.options ?? []).join(', ') || '—' }}</td>
      </tr>
    </SectionTable>
  </ItemSection>
</template>
