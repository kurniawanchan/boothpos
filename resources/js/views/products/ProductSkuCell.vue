<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseTooltip from '../../components/ui/BaseTooltip.vue';

/**
 * Sel SKU daftar produk (024): SKU sungguhan per varian, bukan sekadar code_prefix bersama. Hanya
 * SKU_PREVIEW_COUNT pertama yang tampil; sisanya di balik tautan "+N lainnya" yang dibuka/ditutup PER BARIS
 * (keadaan buka-tutup kini milik sel ini, bukan set id di halaman — hasilnya sama karena satu sel = satu
 * baris). 038: nama varian muncul di tooltip (BaseTooltip di-teleport, tidak terpotong gulir tabel); klik SKU
 * tetap membuka detail varian itu. Nama kosong = tanpa tooltip.
 */
const SKU_PREVIEW_COUNT = 3;
const props = defineProps({ product: { type: Object, required: true } });
defineEmits(['open-variant']);
const { t } = useI18n();

const expanded = ref(false);
const variants = computed(() => props.product.variants ?? []);
const shown = computed(() => (expanded.value ? variants.value : variants.value.slice(0, SKU_PREVIEW_COUNT)));
</script>

<template>
  <span v-if="!variants.length" class="text-[11.5px] text-muted-3">—</span>
  <div v-else class="flex flex-wrap items-center gap-x-1 gap-y-1.5">
    <template v-for="(v, i) in shown" :key="v.id">
      <BaseTooltip :text="v.variant_name ?? ''">
        <button
          type="button"
          class="font-mono text-[11.5px] text-muted-3 underline decoration-dotted hover:text-brand-active"
          @click="$emit('open-variant', product, v)"
        >{{ v.sku }}</button>
      </BaseTooltip><span v-if="i < shown.length - 1" class="text-[11.5px] text-muted-3">,</span>
    </template>
    <button
      v-if="variants.length > SKU_PREVIEW_COUNT"
      type="button"
      class="whitespace-nowrap text-[11px] font-semibold text-brand-active underline decoration-dotted"
      @click="expanded = !expanded"
    >
      {{ expanded ? t('master_data.show_less') : t('master_data.show_more_count', { count: variants.length - SKU_PREVIEW_COUNT }) }}
    </button>
  </div>
</template>
