<script setup>
import { useI18n } from 'vue-i18n';

/**
 * Blok gelap "pratinjau kode otomatis" di drawer produk. Murni presentasi: kode artist/kategori/segmen dan
 * pratinjau lengkap dihitung di drawer (butuh form), blok ini hanya menampilkannya.
 */
defineProps({
  artistCode: { type: String, required: true },
  categoryCode: { type: String, required: true },
  segment: { type: String, required: true },
  preview: { type: String, required: true },
});
const { t } = useI18n();
</script>

<template>
  <div class="flex flex-col gap-3.5 rounded-card bg-ink p-5">
    <span class="text-[10.5px] font-bold uppercase tracking-[0.14em] text-dark-muted-2">{{ t('master_data.auto_code_preview') }}</span>
    <div class="flex flex-wrap items-end gap-2.5">
      <div class="flex flex-col items-center gap-1.5"><span class="font-mono text-[26px] font-bold tracking-wide text-white">{{ artistCode }}</span><span class="text-[10px] text-dark-muted-2">{{ t('master_data.artist_label') }}</span></div>
      <div class="flex flex-col items-center gap-1.5"><span class="font-mono text-[26px] font-bold tracking-wide text-mint-accent">{{ categoryCode }}</span><span class="text-[10px] text-dark-muted-2">{{ t('master_data.category_label') }}</span></div>
      <div class="flex flex-col items-center gap-1.5"><span class="font-mono text-[26px] font-bold tracking-wide text-white">{{ segment }}</span><span class="text-[10px] text-dark-muted-2">{{ t('master_data.product_label') }}</span></div>
      <div class="flex flex-1 flex-col items-end gap-1.5">
        <span class="rounded-lg border border-code-chip-border bg-code-chip px-3 py-1.5 font-mono text-[15px] font-bold text-white">{{ preview }}</span>
        <span class="text-[10.5px] text-dark-muted-2">{{ t('master_data.preview_note') }}</span>
      </div>
    </div>
  </div>
</template>
