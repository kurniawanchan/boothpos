<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import { updateSettings } from '../../api/settings';
import SettingsSection from './SettingsSection.vue';

// Pro vs Master = satu setting `multi_outlet_enabled` (lihat LicenseGate di backend).
const settings = useSettingsStore();
const { t } = useI18n();
const toast = useToastStore();

const changingTier = ref(false);

async function pickTier(enabled) {
  if (enabled === settings.multiOutletEnabled) return;
  changingTier.value = true;
  try {
    await updateSettings([{ key: 'multi_outlet_enabled', value: enabled, type: 'boolean', group: 'licensing' }]);
    toast.success(t('settings.switched_to', { tier: enabled ? t('settings.master') : t('settings.pro') }));
    await settings.load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    changingTier.value = false;
  }
}
</script>

<template>
  <SettingsSection :title="t('settings.license_tier')">
    <div class="flex flex-col gap-2.5">
      <button
        type="button"
        class="flex items-start gap-3 border-2 px-4 py-3.5 text-left transition-colors"
        :class="!settings.multiOutletEnabled ? 'border-brand bg-mint-50' : 'border-line hover:border-brand'"
        :disabled="changingTier"
        @click="pickTier(false)"
      >
        <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full" :class="!settings.multiOutletEnabled ? 'bg-brand' : 'bg-line-2'"></span>
        <span class="flex flex-col gap-0.5">
          <span class="text-[13.5px] font-bold">{{ t('settings.pro') }}</span>
          <span class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.pro_desc') }}</span>
        </span>
      </button>
      <button
        type="button"
        class="flex items-start gap-3 border-2 px-4 py-3.5 text-left transition-colors"
        :class="settings.multiOutletEnabled ? 'border-brand bg-mint-50' : 'border-line hover:border-brand'"
        :disabled="changingTier"
        @click="pickTier(true)"
      >
        <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full" :class="settings.multiOutletEnabled ? 'bg-brand' : 'bg-line-2'"></span>
        <span class="flex flex-col gap-0.5">
          <span class="text-[13.5px] font-bold">{{ t('settings.master') }}</span>
          <span class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.master_desc') }}</span>
        </span>
      </button>
    </div>
    <p class="border-t-2 border-line-3 pt-3.5 text-[11.5px] leading-relaxed text-muted-3">
      {{ t('settings.tier_switch_note') }}
    </p>
  </SettingsSection>
</template>
