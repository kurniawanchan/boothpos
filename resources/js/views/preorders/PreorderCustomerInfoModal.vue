<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getCustomer } from '../../api/customers';
import { useToastStore } from '../../stores/toast';
import BaseModal from '../../components/ui/BaseModal.vue';

/**
 * Requested: clicking a customer's name in the list shows their full
 * info (name/phone/email/social handle/address/notes) — ringkasan baca-saja, memakai field set
 * CustomerResource. Baris daftar hanya membawa nama, jadi satu pelanggan diambil sesuai kebutuhan lewat
 * GET /customers/{id}. Dipecah dari PreordersView.vue (041); dibuka lewat ref: `open(customerId)`.
 */
const toast = useToastStore();
const { t } = useI18n();

const showCustomerInfo = ref(false);
const customerInfoLoading = ref(false);
const customerInfo = ref(null);

async function open(customerId) {
  if (!customerId) return;
  showCustomerInfo.value = true;
  customerInfoLoading.value = true;
  customerInfo.value = null;
  try {
    customerInfo.value = await getCustomer(customerId);
  } catch (err) {
    toast.error(err.message || t('preorders.load_failed'));
    showCustomerInfo.value = false;
  } finally {
    customerInfoLoading.value = false;
  }
}
defineExpose({ open });
</script>

<template>
  <BaseModal :open="showCustomerInfo" :title="t('preorders.customer_info_title')" max-width-class="max-w-[420px]" @close="showCustomerInfo = false">
    <div class="flex flex-col gap-3 px-6 py-5">
      <div v-if="customerInfoLoading" class="py-8 text-center text-[13px] text-muted-3">{{ t('preorders.loading') }}</div>
      <div v-else-if="customerInfo" class="flex flex-col gap-3">
        <span class="text-[15px] font-bold">{{ customerInfo.name }}</span>
        <div class="flex flex-col gap-2 text-[13px]">
          <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.phone') }}</span><span class="font-medium">{{ customerInfo.phone || '—' }}</span></div>
          <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.email') }}</span><span class="font-medium">{{ customerInfo.email || '—' }}</span></div>
          <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.social_handle') }}</span><span class="font-medium">{{ customerInfo.social_handle || '—' }}</span></div>
          <div class="flex justify-between gap-3"><span class="text-muted-3">{{ t('events_sessions.address') }}</span><span class="text-right font-medium">{{ customerInfo.address || '—' }}</span></div>
          <div v-if="customerInfo.notes" class="flex flex-col gap-1 border-t border-dashed border-line-2 pt-2.5"><span class="text-muted-3">{{ t('events_sessions.notes') }}</span><span class="font-medium">{{ customerInfo.notes }}</span></div>
        </div>
      </div>
    </div>
  </BaseModal>
</template>
