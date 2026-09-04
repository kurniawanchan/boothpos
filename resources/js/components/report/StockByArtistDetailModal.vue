<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import { stockByArtistReport } from '../../api/reports';
import { useToastStore } from '../../stores/toast';

/**
 * US7 (009-ui-ux-refinements) — drill-down varian per penjual dari tab
 * "Stok per Penjual" di ReportsView. Dipanggil ON DEMAND saat baris diklik
 * (bukan eager untuk semua baris) — sama seperti prinsip `with_variants`
 * pada GET /products (lihat CLAUDE.md) dan `artist_id` di sini sendiri
 * (research.md R9): payload varian-level cukup berat sehingga hanya
 * diambil ketika benar-benar diminta pengguna.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  artistId: { type: [Number, String, null], default: null },
  artistName: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const toast = useToastStore();
const { t } = useI18n();
const loading = ref(false);
const detail = ref(null);

watch(
  () => [props.open, props.artistId],
  async ([open, artistId]) => {
    if (!open || !artistId) {
      detail.value = null;
      return;
    }
    loading.value = true;
    try {
      // Endpoint sama dengan tabel ringkasan (stockByArtistReport), tapi
      // dengan artist_id backend mengembalikan bentuk respons yang berbeda
      // (variant-level, bukan array ringkasan per-penjual) — lihat
      // research.md R9.
      detail.value = await stockByArtistReport({ artist_id: artistId });
    } catch (err) {
      toast.error(err.message || t('reports.load_stock_detail_failed'));
    } finally {
      loading.value = false;
    }
  },
  { immediate: true }
);
</script>

<template>
  <BaseModal :open="open" :title="t('reports.stock_detail_for', { artist: artistName })" max-width-class="max-w-[560px]" @close="emit('close')">
    <div v-if="loading" class="px-6 py-14 text-center text-[13px] text-muted-3">{{ t('reports.loading_stock_detail') }}</div>
    <div v-else-if="!detail || detail.variants.length === 0" class="px-6 py-14 text-center text-[13px] text-muted-3">
      {{ t('reports.no_stock_detail') }}
    </div>
    <div v-else class="flex flex-col gap-3.5 px-6 py-5">
      <div class="grid grid-cols-2 gap-3.5">
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4">
          <span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.stock_col_variant_count') }}</span>
          <span class="text-[19px] font-extrabold tracking-tight">{{ detail.variant_count }}</span>
        </div>
        <div class="flex flex-col gap-1.5 rounded-card border border-line-2 bg-white p-4">
          <span class="text-[11.5px] font-semibold text-muted-2">{{ t('reports.stock_col_total_stock') }}</span>
          <span class="text-[19px] font-extrabold tracking-tight">{{ detail.total_stock }}</span>
        </div>
      </div>
      <div class="overflow-hidden rounded-md border border-line-5">
        <table class="w-full border-collapse text-[12.5px]">
          <thead>
            <tr class="bg-surface-subtle text-left">
              <th class="px-3 py-1.5 font-bold text-muted-2">{{ t('master_data.col_sku') }}</th>
              <th class="px-3 py-1.5 font-bold text-muted-2">{{ t('master_data.col_name') }}</th>
              <th class="px-3 py-1.5 text-right font-bold text-muted-2">{{ t('reports.stock_col_current_stock') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="variant in detail.variants" :key="variant.variant_id" class="border-t border-line-5">
              <td class="px-3 py-1.5 font-mono">{{ variant.sku }}</td>
              <td class="px-3 py-1.5">{{ variant.variant_name }}</td>
              <td class="px-3 py-1.5 text-right">{{ variant.current_stock }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </BaseModal>
</template>
