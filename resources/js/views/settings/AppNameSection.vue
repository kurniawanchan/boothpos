<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import SettingsSection from './SettingsSection.vue';

// --- Nama aplikasi (merek produk) -------------------------------------
// Bukan nama toko (itu identitas penjual di profil toko): ini yang tampil di
// sidebar dan di teks "Powered by …" pada dokumen. Panjang maksimal
// mencerminkan aturan server (App\Support\AppName::MAX_LENGTH).
const APP_NAME_MAX_LENGTH = 50;

const settings = useSettingsStore();
const { t } = useI18n();
const toast = useToastStore();

const appNameInput = ref(settings.appName);
watch(() => settings.appName, (name) => { appNameInput.value = name; });
const savingAppName = ref(false);

async function saveAppName() {
  savingAppName.value = true;
  try {
    await settings.setAppName(appNameInput.value);
    appNameInput.value = settings.appName;
    toast.success(t('settings.app_name_saved'));
  } catch (err) {
    toast.error(err.message);
  } finally {
    savingAppName.value = false;
  }
}
</script>

<template>
  <SettingsSection :title="t('settings.app_name_section_title')">
    <BaseInput v-model="appNameInput" :label="t('settings.app_name_label')" :maxlength="APP_NAME_MAX_LENGTH" />
    <p class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.app_name_hint') }}</p>
    <BaseButton size="sm" class="self-start" :loading="savingAppName" @click="saveAppName">{{ t('settings.save_app_name') }}</BaseButton>
  </SettingsSection>
</template>
