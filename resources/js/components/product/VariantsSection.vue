<script setup>
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/formatters';
import { variantName, variantPrice } from '../../utils/itemDetail';
import StatusPill from '../ui/StatusPill.vue';
import ItemSection from './ItemSection.vue';
import SectionTable from './SectionTable.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 043 (US2) — "Varian & harga": tabel Varian · SKU · Harga dasar · Status. Biaya modal TIDAK ditampilkan di sini:
 * API menghilangkannya untuk pengguna tanpa menu products/reports, dan halaman tidak boleh berasumsi bidang itu ada.
 * "Kelola varian" hanya dirender bila `canEdit` (hidden, bukan disabled); tombolnya meminta pemanggil membuka
 * VariantsDrawer (batch varian + harga). Mengubah data item itu sendiri tetap lewat tombol "Edit item" halaman.
 */
defineProps({
  variants: { type: Array, default: () => [] },
  canEdit: { type: Boolean, default: false },
});
defineEmits(['manage']);
const { t } = useI18n();
</script>

<template>
  <ItemSection :title="t('products.sec_variants')" testid="section-variants">
    <template v-if="canEdit" #action>
      <BaseButton variant="secondary" size="sm" @click="$emit('manage')">{{ t('products.manage_variants') }}</BaseButton>
    </template>
    <p v-if="!variants.length" class="px-5 py-6 text-[13px] text-muted-4">{{ t('products.no_variants') }}</p>
    <SectionTable v-else>
      <template #head>
        <tr>
          <th scope="col">{{ t('products.col_variant') }}</th>
          <th scope="col">{{ t('products.col_sku') }}</th>
          <th scope="col" class="text-right">{{ t('products.col_price') }}</th>
          <th scope="col">{{ t('products.col_status') }}</th>
        </tr>
      </template>
      <tr v-for="v in variants" :key="v.id" data-testid="variant-row">
        <td class="font-semibold">{{ variantName(v) }}</td>
        <td class="text-muted-5">{{ v.sku || '—' }}</td>
        <td class="text-right font-bold tabular-nums">{{ variantPrice(v) === null ? '—' : formatIDR(variantPrice(v)) }}</td>
        <td>
          <StatusPill :variant="v.is_active === false ? 'neutral' : 'success'">{{ v.is_active === false ? t('products.variant_inactive') : t('products.variant_active') }}</StatusPill>
        </td>
      </tr>
    </SectionTable>
  </ItemSection>
</template>
