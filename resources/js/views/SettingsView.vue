<script setup>
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../stores/settings';
import { useQueryState } from '../composables/useQueryState';
import PageHeader from '../components/page/PageHeader.vue';
import SettingsLayout from '../components/page/SettingsLayout.vue';
import BackupRestoreSection from '../components/settings/BackupRestoreSection.vue';
import StoreIdentitySection from './settings/StoreIdentitySection.vue';
import ReceiptDisplaySection from './settings/ReceiptDisplaySection.vue';
import PaymentChannelsSection from './settings/PaymentChannelsSection.vue';
import AppNameSection from './settings/AppNameSection.vue';
import ThemeSection from './settings/ThemeSection.vue';
import SystemModeSection from './settings/SystemModeSection.vue';
import LicenseTierSection from './settings/LicenseTierSection.vue';
import BusinessDaySection from './settings/BusinessDaySection.vue';
import ReasonCodesSection from './settings/ReasonCodesSection.vue';
import MenuReleaseSection from './settings/MenuReleaseSection.vue';
import ServerAddressSection from './settings/ServerAddressSection.vue';

/**
 * 041-back-office-ui-redesign (US2) — Pengaturan dipecah dari satu halaman 8 kartu menjadi daftar bagian di kiri
 * dan SATU bagian di kanan (<SettingsLayout>), bagian aktif disalin ke address bar (?section=) supaya bisa
 * ditautkan dan bertahan saat refresh. Semua pengaturan lama tetap ada dengan perilaku yang sama — tiap bagian
 * ada di views/settings/*Section.vue (cadangan & pemulihan tetap komponen lamanya).
 *
 * Halaman ini digerbang menu 'settings' (owner/admin) lewat router meta; server menegakkannya lagi.
 */
const settings = useSettingsStore();
const { t } = useI18n();

const DEFAULT_SECTION = 'store';
const { state, set } = useQueryState({ section: DEFAULT_SECTION });

const SECTIONS = {
  store: StoreIdentitySection,
  receipt: ReceiptDisplaySection,
  channels: PaymentChannelsSection,
  app: AppNameSection,
  theme: ThemeSection,
  mode: SystemModeSection,
  tier: LicenseTierSection,
  business_day: BusinessDaySection,
  reasons: ReasonCodesSection,
  menu_release: MenuReleaseSection,
  server: ServerAddressSection,
  backup: BackupRestoreSection,
};

const groups = computed(() => [
  {
    key: 'store',
    label: t('settings.group_store'),
    items: [
      { key: 'store', label: t('settings.store_identity') },
      { key: 'receipt', label: t('settings.receipt_display_section_title') },
      { key: 'channels', label: t('settings.payment_channels') },
    ],
  },
  {
    key: 'appearance',
    label: t('settings.group_appearance'),
    items: [
      { key: 'app', label: t('settings.app_name_section_title') },
      { key: 'theme', label: t('settings.theme_section_title') },
    ],
  },
  {
    key: 'system',
    label: t('settings.group_system'),
    items: [
      { key: 'mode', label: t('settings.system_mode_section_title') },
      { key: 'server', label: t('settings.server_address_section_title') },
      { key: 'tier', label: t('settings.license_tier') },
      { key: 'business_day', label: t('settings.business_day_section_title') },
      // 043 — kode alasan (86, diskontinu, tolak persetujuan) diatur admin; dibaca dialog alasan di halaman Produk.
      { key: 'reasons', label: t('settings.reasons_section_title') },
      // 043 — saklar rilis menu, ambang persetujuan harga, pembulatan bawaan ubah harga massal.
      { key: 'menu_release', label: t('settings.menu_release_section_title') },
      { key: 'backup', label: t('settings.nav_backup') },
    ],
  },
]);

// Nilai ?section= yang tak dikenal jatuh ke bagian pertama, bukan halaman kosong.
const active = computed(() => (state.section in SECTIONS ? state.section : DEFAULT_SECTION));

onMounted(() => settings.load());
</script>

<template>
  <PageHeader :title="t('nav.settings')" :description="t('nav.settings_subtitle')" />
  <SettingsLayout :groups="groups" :model-value="active" :label="t('settings.sections_aria')" @update:model-value="(key) => set({ section: key })">
    <!-- :key memaksa bagian dipasang ulang saat berpindah, sehingga setiap bagian memuat datanya sendiri. -->
    <component :is="SECTIONS[active]" :key="active" />
  </SettingsLayout>
</template>
