<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useToastStore } from '../stores/toast';
import { getPaymentSettings, updatePaymentSettings } from '../api/settings';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import PageHeader from '../components/page/PageHeader.vue';
import LoadingState from '../components/ui/LoadingState.vue';
import ErrorState from '../components/ui/ErrorState.vue';

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
const loadError = ref('');
const saving = ref(false);
const errors = reactive({});

async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    const res = await getPaymentSettings();
    form.bank_name = res.bank_name ?? '';
    form.account_number = res.account_number ?? '';
    form.account_holder = res.account_holder ?? '';
    form.instructions = res.instructions ?? '';
  } catch (err) {
    // Gagal memuat ditampilkan sebagai keadaan galat dengan tombol coba lagi, bukan formulir kosong
    // yang bila disimpan akan menimpa pengaturan yang sebenarnya ada.
    loadError.value = err.message || t('page.load_failed');
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
  <PageHeader :title="t('nav.settings_payment')" :description="t('settings_payment.description')" />

  <div class="px-[26px] pb-10">
    <section class="max-w-[640px] border-2 border-line bg-white">
      <h2 class="border-b-2 border-line px-5 py-3.5 text-[15px] font-bold tracking-tight">{{ t('settings_payment.title') }}</h2>

      <LoadingState v-if="loading" />
      <ErrorState v-else-if="loadError" :message="loadError" @retry="load" />
      <form v-else class="flex flex-col gap-3.5 p-5" @submit.prevent="save">
        <BaseInput v-model="form.bank_name" :label="t('settings_payment.bank_name')" :error="errors.bank_name" />
        <BaseInput v-model="form.account_number" :label="t('settings_payment.account_number')" :error="errors.account_number" />
        <BaseInput v-model="form.account_holder" :label="t('settings_payment.account_holder')" :error="errors.account_holder" />
        <BaseTextarea v-model="form.instructions" :label="t('settings_payment.instructions')" :rows="4" :error="errors.instructions" />
        <BaseButton class="self-start" type="submit" :loading="saving">{{ t('common.save') }}</BaseButton>
      </form>
    </section>
  </div>
</template>
