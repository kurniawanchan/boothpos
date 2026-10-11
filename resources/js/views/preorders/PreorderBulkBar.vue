<script setup>
import { useI18n } from 'vue-i18n';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseSelect from '../../components/ui/BaseSelect.vue';

/**
 * Bilah aksi massal pre-order (022 US5). Tampilannya mengikuti BulkBar kit (bingkai mint, jumlah terpilih,
 * "Batalkan pilihan"), tetapi tidak memakai BulkBar karena bilah ini membawa pemilih jenis dokumen
 * (invoice / invoice pembayaran) dan tombol bermuatan (loading) yang tidak bisa dinyatakan sebagai
 * `actions` BulkBar. Hanya dirender saat ada baris terpilih (Edge Cases spec 022).
 */
const props = defineProps({
  count: { type: Number, default: 0 },
  documentType: { type: String, default: 'invoice' },
  downloading: { type: Boolean, default: false },
  emailing: { type: Boolean, default: false },
  duplicating: { type: Boolean, default: false },
});
const emit = defineEmits(['update:documentType', 'download', 'download-slips', 'email', 'duplicate', 'clear']);
const { t } = useI18n();
</script>

<template>
  <div v-if="count > 0" class="mx-5 mb-3 flex flex-wrap items-center gap-2.5 border-2 border-brand bg-mint-50 px-4 py-2.5" role="toolbar" :aria-label="t('page.bulk_actions')">
    <span class="text-[13px] font-bold">{{ t('preorders.selected_count', { count }) }}</span>
    <BaseSelect
      :model-value="documentType"
      class="w-48"
      :options="[{ value: 'invoice', label: t('preorders.document_invoice_title') }, { value: 'payment_invoice', label: t('preorders.payment_receipt_title') }]"
      @update:model-value="(v) => emit('update:documentType', v)"
    />
    <BaseButton variant="secondary" size="sm" :loading="downloading" @click="emit('download')">
      <i class="ph-duotone ph-file-zip text-[15px]" aria-hidden="true"></i>
      {{ t('preorders.bulk_download_action') }}
    </BaseButton>
    <BaseButton variant="secondary" size="sm" :loading="downloading" @click="emit('download-slips')">
      <i class="ph-duotone ph-truck text-[15px]" aria-hidden="true"></i>
      {{ t('preorders.bulk_download_shipping_slip') }}
    </BaseButton>
    <BaseButton variant="secondary" size="sm" :loading="emailing" @click="emit('email')">
      <i class="ph-duotone ph-envelope-simple text-[15px]" aria-hidden="true"></i>
      {{ t('preorders.bulk_email_action') }}
    </BaseButton>
    <!-- 027 — salinan dibuat satu per pre-order terpilih (tidak digabung). -->
    <BaseButton variant="secondary" size="sm" :loading="duplicating" data-testid="bulk-duplicate" @click="emit('duplicate')">
      <i class="ph-duotone ph-copy text-[15px]" aria-hidden="true"></i>
      {{ t('preorders.duplicate_selected') }}
    </BaseButton>
    <button type="button" class="ml-auto text-[12.5px] font-bold text-muted underline decoration-dotted hover:text-ink" @click="emit('clear')">{{ t('page.clear_selection') }}</button>
  </div>
</template>
