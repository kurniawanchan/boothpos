<script setup>
import { useI18n } from 'vue-i18n';

// 090 / US2 — kartu QR satu meja: label outlet + nomor meja di atas, QR di tengah, keterangan di bawah. Dipakai lembar
// satu QR (diunduh sebagai PNG lewat pdfCapture) dan pratinjau lembar massal.
defineProps({
  item: { type: Object, required: true }, // { outlet_name, table_name, area, qr }
});
const { t } = useI18n();
</script>

<template>
  <div class="flex w-[260px] flex-col items-center gap-2 border border-line bg-white p-5 text-center" data-testid="qr-card">
    <p class="text-[12px] font-semibold text-muted-4">{{ item.outlet_name }}</p>
    <p class="text-[26px] font-extrabold leading-none text-ink">{{ item.table_name }}</p>
    <img v-if="item.qr" :src="item.qr" :alt="t('dining_setup.qr_alt', { name: item.table_name })" class="h-[200px] w-[200px]" />
    <p class="text-[11px] text-muted">{{ t('dining_setup.qr_caption') }}</p>
  </div>
</template>
