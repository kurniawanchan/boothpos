<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useToastStore } from '../stores/toast';
import { getPaymentSettings, updatePaymentSettings } from '../api/settings';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';

/**
 * 019-billing-system (T069, US4) — halaman submenu baru
 * Pengaturan > Pembayaran, terpisah dari SettingsView.vue yang lama
 * (research.md R7'): sesuai permintaan "submenu" berarti rute/nav tersendiri,
 * bukan section baru di halaman satu-scroll yang sudah ada.
 *
 * Digerbang menu key 'settings' yang SUDAH ADA lewat router meta
 * (lihat router/index.js) — bukan permission baru (R7').
 */
const { t } = useI18n();
const toast = useToastStore();

const form = reactive({
  bank_name: '',
  account_number: '',
  account_holder: '',
  instructions: '',
});
const loading = ref(true);
const saving = ref(false);
const errors = reactive({});

async function load() {
  loading.value = true;
  try {
    const res = await getPaymentSettings();
    form.bank_name = res.bank_name ?? '';
    form.account_number = res.account_number ?? '';
    form.account_holder = res.account_holder ?? '';
    form.instructions = res.instructions ?? '';
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  Object.keys(errors).forEach((k) => delete errors[k]);
  try {
    await updatePaymentSettings({ ...form });
    toast.success(t('settings_payment.saved'));
  } catch (err) {
    if (err.isValidation) Object.assign(errors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
    else toast.error(err.message);
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="max-w-[560px] rounded-card border border-line-2 bg-white p-5">
      <span class="text-[15px] font-bold tracking-tight">{{ t('settings_payment.title') }}</span>
      <p class="mt-1 text-[12px] leading-relaxed text-muted-3">{{ t('settings_payment.description') }}</p>

      <div v-if="loading" class="py-10 text-center text-[13px] text-muted-3">{{ t('common.loading_data') }}</div>
      <form v-else class="mt-4 flex flex-col gap-3.5" @submit.prevent="save">
        <BaseInput v-model="form.bank_name" :label="t('settings_payment.bank_name')" :error="errors.bank_name" />
        <BaseInput v-model="form.account_number" :label="t('settings_payment.account_number')" :error="errors.account_number" />
        <BaseInput v-model="form.account_holder" :label="t('settings_payment.account_holder')" :error="errors.account_holder" />
        <BaseTextarea v-model="form.instructions" :label="t('settings_payment.instructions')" :rows="4" :error="errors.instructions" />
        <BaseButton class="self-start" type="submit" :loading="saving">{{ t('common.save') }}</BaseButton>
      </form>
    </div>
  </div>
</template>
