<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseTextarea from '../../components/ui/BaseTextarea.vue';
import SettingsSection from './SettingsSection.vue';

// --- Tampilan struk (US7) -----------------------------------------------
const settings = useSettingsStore();
const { t } = useI18n();
const toast = useToastStore();

const receiptFooterText = ref(settings.receiptFooterText || '');
const receiptShowLogo = ref(settings.receiptShowLogo);
// Dipasang saat dipilih, jadi ikuti store bila nilai tersimpan tiba setelah setup.
watch(() => settings.receiptFooterText, (v) => { receiptFooterText.value = v || ''; });
watch(() => settings.receiptShowLogo, (v) => { receiptShowLogo.value = v; });
const savingReceiptDisplay = ref(false);

async function saveReceiptDisplay() {
  savingReceiptDisplay.value = true;
  try {
    await settings.setReceiptDisplay({ footerText: receiptFooterText.value, showLogo: receiptShowLogo.value });
    toast.success(t('settings.receipt_display_saved'));
  } catch (err) {
    toast.error(err.message);
  } finally {
    savingReceiptDisplay.value = false;
  }
}
</script>

<template>
  <SettingsSection :title="t('settings.receipt_display_section_title')">
    <BaseTextarea v-model="receiptFooterText" :label="t('settings.receipt_footer_text')" :rows="3" :placeholder="t('settings.receipt_footer_placeholder')" />
    <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
      <input v-model="receiptShowLogo" type="checkbox" class="h-4 w-4 border-line accent-brand" />
      {{ t('settings.receipt_show_logo_label') }}
    </label>
    <BaseButton size="sm" class="self-start" :loading="savingReceiptDisplay" @click="saveReceiptDisplay">{{ t('settings.save_receipt_display') }}</BaseButton>
  </SettingsSection>
</template>
