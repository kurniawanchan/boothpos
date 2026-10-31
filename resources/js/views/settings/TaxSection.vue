<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import SettingsSection from './SettingsSection.vue';

// --- Pajak (083) ----------------------------------------------------------
// Tarif, label, dan tampil/sembunyi. Toggle HANYA tampilan: pajak tetap ditagih dan ikut total saat dimatikan
// (server tidak membacanya). Validasi sebenarnya di server (422); input ini hanya membantu mengetik.
const settings = useSettingsStore();
const { t } = useI18n();
const toast = useToastStore();

const rate = ref(String(settings.taxRate));
const label = ref(settings.taxLabel || '');
const show = ref(settings.taxShow);
// Dipasang saat dipilih, jadi ikuti store bila nilai tersimpan tiba setelah setup.
watch(() => settings.taxRate, (v) => { rate.value = String(v); });
watch(() => settings.taxLabel, (v) => { label.value = v || ''; });
watch(() => settings.taxShow, (v) => { show.value = v; });
const saving = ref(false);

async function save() {
  saving.value = true;
  try {
    await settings.setTax({ rate: rate.value === '' ? 0 : rate.value, label: label.value.trim(), show: show.value });
    toast.success(t('settings.tax_saved'));
  } catch (err) {
    toast.error(err.message);
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <SettingsSection :title="t('settings.tax_section_title')">
    <BaseInput v-model="rate" type="number" min="0" max="100" step="0.01" :label="t('settings.tax_rate_label')" data-testid="tax-rate" />
    <BaseInput v-model="label" :maxlength="30" :label="t('settings.tax_label_label')" :placeholder="t('settings.tax_label_placeholder')" data-testid="tax-label" />
    <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
      <input v-model="show" type="checkbox" class="h-4 w-4 border-line accent-brand" data-testid="tax-show" />
      {{ t('settings.tax_show_label') }}
    </label>
    <p class="text-[12.5px] text-muted-3">{{ t('settings.tax_help_hidden_not_charged') }}</p>
    <p class="text-[12.5px] text-muted-3">{{ t('settings.tax_help_shared_modes') }}</p>
    <BaseButton size="sm" class="self-start" :loading="saving" data-testid="tax-save" @click="save">{{ t('settings.save_tax') }}</BaseButton>
  </SettingsSection>
</template>
