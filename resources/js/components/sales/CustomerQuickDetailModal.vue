<script setup>
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';

// Dipecah dari SalesView.vue (041, US2) tanpa mengubah perilaku. Follow-up 2 (FR-022): popover ringan yang
// hanya memakai data yang SUDAH ada di baris transaksi (nama/telepon/email) — tidak ada panggilan API baru.
// `customer` null = tertutup.
defineProps({
  customer: { type: Object, default: null },
});
defineEmits(['close']);
const { t } = useI18n();
</script>

<template>
  <BaseModal :open="customer !== null" :title="t('reports.customer_detail')" max-width-class="max-w-[360px]" @close="$emit('close')">
    <div v-if="customer" class="flex flex-col gap-2.5 px-6 py-5 text-[13.5px]">
      <div class="flex flex-col gap-0.5">
        <span class="text-[11.5px] font-semibold text-muted-3">{{ t('reports.col_customer') }}</span>
        <span class="font-semibold">{{ customer.name }}</span>
      </div>
      <div v-if="customer.phone" class="flex flex-col gap-0.5">
        <span class="text-[11.5px] font-semibold text-muted-3">{{ t('settings.phone') }}</span>
        <span>{{ customer.phone }}</span>
      </div>
      <div v-if="customer.email" class="flex flex-col gap-0.5">
        <span class="text-[11.5px] font-semibold text-muted-3">{{ t('settings.email') }}</span>
        <span>{{ customer.email }}</span>
      </div>
    </div>
  </BaseModal>
</template>
