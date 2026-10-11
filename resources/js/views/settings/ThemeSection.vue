<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import BaseButton from '../../components/ui/BaseButton.vue';
import ThemeColorPicker from '../../components/settings/ThemeColorPicker.vue';
import { DEFAULT_ACCENT_COLOR } from '../../utils/theme';
import SettingsSection from './SettingsSection.vue';

// --- Warna tema (US6) ---------------------------------------------------
const settings = useSettingsStore();
const { t } = useI18n();
const toast = useToastStore();

const themeColor = ref(settings.themeAccentColor || DEFAULT_ACCENT_COLOR);
// Bagian ini kini dipasang saat dipilih (bukan saat halaman dibuka), jadi nilai tersimpan bisa tiba
// setelah setup — ikuti store supaya tidak menampilkan warna bawaan yang usang.
watch(() => settings.themeAccentColor, (c) => { if (c) themeColor.value = c; });
const savingTheme = ref(false);

async function saveTheme() {
  savingTheme.value = true;
  try {
    await settings.setThemeAccentColor(themeColor.value);
    toast.success(t('settings.theme_saved'));
  } catch (err) {
    toast.error(err.message);
  } finally {
    savingTheme.value = false;
  }
}
</script>

<template>
  <SettingsSection :title="t('settings.theme_section_title')">
    <ThemeColorPicker v-model="themeColor" />
    <BaseButton size="sm" class="self-start" :loading="savingTheme" @click="saveTheme">{{ t('settings.save_theme') }}</BaseButton>
  </SettingsSection>
</template>
