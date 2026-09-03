<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../../stores/settings';

/**
 * Status DEMO/LIVE (003-seed-demo-live, FR-005) — selalu terlihat untuk
 * SEMUA role, terlepas dari siapa yang boleh MENGUBAHNYA (itu digerbang
 * terpisah di SettingsView, lihat canAccessMenu('settings')). Dipasang di
 * AppTopbar.vue persis seperti LanguageSwitcher — status global lintas
 * layar, bukan opt-in per halaman.
 */
const settings = useSettingsStore();
const { t } = useI18n();

const isDemo = computed(() => settings.isDemoMode);
const label = computed(() => t(isDemo.value ? 'settings.system_mode_demo' : 'settings.system_mode_live'));
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-[12.5px] font-semibold"
    :class="isDemo ? 'border-warn-border bg-warn-bg text-warn-text' : 'border-mint-border bg-mint-100 text-ink'"
    :title="t('settings.system_mode_label')"
  >
    <span class="h-1.5 w-1.5 rounded-full" :class="isDemo ? 'bg-warn-text' : 'bg-brand'" />
    {{ label }}
  </span>
</template>
