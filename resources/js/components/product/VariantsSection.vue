<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { setVariantOutletPrice } from '../../api/products';
import { useToastStore } from '../../stores/toast';
import { formatIDR } from '../../utils/formatters';
import { variantName, variantPrice } from '../../utils/itemDetail';
import StatusPill from '../ui/StatusPill.vue';
import ItemSection from './ItemSection.vue';
import SectionTable from './SectionTable.vue';
import BaseButton from '../ui/BaseButton.vue';
import { cellInputClass } from './cellStyles';

/**
 * 043 (US2) — "Varian & harga": tabel Varian · SKU · Harga dasar · Status. Biaya modal TIDAK ditampilkan di sini:
 * API menghilangkannya untuk pengguna tanpa menu products/reports, dan halaman tidak boleh berasumsi bidang itu ada.
 * "Kelola varian" hanya dirender bila `canEdit` (hidden, bukan disabled); tombolnya meminta pemanggil membuka
 * VariantsDrawer (batch varian + harga). Mengubah data item itu sendiri tetap lewat tombol "Edit item" halaman.
 *
 * 045 Stage 3: dengan lebih dari satu outlet terlihat (`outletPrices` dari server) muncul satu kolom harga per
 * outlet. Sel kosong/abu-abu = mengikuti harga dasar (baris hanya ada bila harganya berbeda). Mengubahnya hanya
 * untuk yang boleh mengedit. Dengan rilis menu aktif perubahan menjadi perubahan tertunda (menunggu rilis/
 * persetujuan) — server yang memutuskan, sel tetap menampilkan harga yang berlaku sekarang. Banyak outlet → tabel bergulir ke samping (SectionTable sudah overflow-x-auto).
 */
const props = defineProps({
  variants: { type: Array, default: () => [] },
  canEdit: { type: Boolean, default: false },
  outletPrices: { type: Object, default: null },
  releaseOn: { type: Boolean, default: false },
});
const emit = defineEmits(['manage', 'changed']);
const { t } = useI18n();
const toast = useToastStore();

const draft = ref({});
const key = (v, o) => `${v.id}:${o.id}`;
const overrideOf = (v, o) => props.outletPrices?.by_variant?.[v.id]?.[o.id] ?? null;
const editable = () => props.canEdit;

async function save(v, o) {
  const k = key(v, o);
  if (!(k in draft.value)) return;
  const raw = String(draft.value[k]).trim();
  delete draft.value[k];
  const next = raw === '' ? null : Number(raw);
  if (next !== null && (Number.isNaN(next) || next < 0)) {
    toast.error(t('products.outlet_price_invalid'));
    return;
  }
  const current = overrideOf(v, o);
  if ((current === null && next === null) || (current !== null && next !== null && Number(current) === next)) return;
  try {
    const res = await setVariantOutletPrice(v.id, o.id, next);
    toast.success(t(res?.pending ? 'products.change_pending' : 'products.outlet_price_saved'));
    emit('changed');
  } catch {
    // 409/422 sudah di-toast handler pusat; muat ulang supaya tampilan sesuai kenyataan.
    emit('changed');
  }
}
</script>

<template>
  <ItemSection :title="t('products.sec_variants')" testid="section-variants">
    <template v-if="canEdit" #action>
      <BaseButton variant="secondary" size="sm" @click="$emit('manage')">{{ t('products.manage_variants') }}</BaseButton>
    </template>
    <p v-if="!variants.length" class="px-5 py-6 text-[13px] text-muted-4">{{ t('products.no_variants') }}</p>
    <SectionTable v-else data-testid="variants-table">
      <template #head>
        <tr>
          <th scope="col">{{ t('products.col_variant') }}</th>
          <th scope="col">{{ t('products.col_sku') }}</th>
          <th scope="col" class="text-right">{{ t('products.col_price') }}</th>
          <th v-for="o in outletPrices?.outlets ?? []" :key="`h-${o.id}`" scope="col" class="text-right" data-testid="outlet-price-head" :title="o.name">{{ o.code }}</th>
          <th scope="col">{{ t('products.col_status') }}</th>
        </tr>
      </template>
      <tr v-for="v in variants" :key="v.id" data-testid="variant-row">
        <td class="font-semibold">{{ variantName(v) }}</td>
        <td class="text-muted-5">{{ v.sku || '—' }}</td>
        <td class="text-right font-bold tabular-nums">{{ variantPrice(v) === null ? '—' : formatIDR(variantPrice(v)) }}</td>
        <td v-for="o in outletPrices?.outlets ?? []" :key="key(v, o)" class="text-right tabular-nums" data-testid="outlet-price-cell">
          <input
            v-if="editable()"
            type="number"
            inputmode="decimal"
            :class="cellInputClass({ number: true })"
            :placeholder="variantPrice(v) === null ? '—' : String(Math.round(Number(variantPrice(v))))"
            :aria-label="t('products.outlet_price_for', { outlet: o.code, variant: variantName(v) })"
            :value="draft[key(v, o)] ?? overrideOf(v, o) ?? ''"
            @input="draft[key(v, o)] = $event.target.value"
            @blur="save(v, o)"
            @keydown.enter.prevent="$event.target.blur()"
          />
          <span v-else-if="overrideOf(v, o) !== null" class="font-bold">{{ formatIDR(overrideOf(v, o)) }}</span>
          <span v-else class="text-muted-3" :title="t('products.outlet_price_follows')">{{ variantPrice(v) === null ? '—' : formatIDR(variantPrice(v)) }}</span>
        </td>
        <td>
          <StatusPill :variant="v.is_active === false ? 'neutral' : 'success'">{{ v.is_active === false ? t('products.variant_inactive') : t('products.variant_active') }}</StatusPill>
        </td>
      </tr>
    </SectionTable>
  </ItemSection>
</template>
