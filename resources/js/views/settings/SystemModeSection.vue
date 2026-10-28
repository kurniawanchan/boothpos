<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../../stores/auth';
import { useOutletStore } from '../../stores/outlet';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import ConfirmDialog from '../../components/ui/ConfirmDialog.vue';
import SettingsSection from './SettingsSection.vue';

// --- Mode sistem DEMO/LIVE (003-seed-demo-live, FR-004/FR-013) -----------
// Halaman Pengaturan sudah digerbang canAccessMenu('settings') lewat router
// meta (lihat router/index.js), jadi cashier/inventory tidak pernah sampai
// ke sini — tidak perlu pengecekan tampilan tambahan (Constitution III:
// hilang total, bukan disabled).
const settings = useSettingsStore();
const auth = useAuthStore();
const outlet = useOutletStore();
const { t } = useI18n();
const toast = useToastStore();

const pendingMode = ref(null); // mode yang MAU dipilih, menunggu konfirmasi
const switchingMode = ref(false);

function requestModeSwitch(mode) {
  if (mode === settings.systemMode) return;
  pendingMode.value = mode;
}

async function confirmModeSwitch() {
  const mode = pendingMode.value;
  switchingMode.value = true;
  try {
    await settings.setSystemMode(mode);
    // Outlet DEMO dan LIVE adalah baris yang berbeda (HasDataMode): tanpa memuat ulang cakupan, bilah atas dan pilihan
    // outlet di formulir tetap menampilkan outlet mode sebelumnya, dan pilihan tersimpan bisa menjadi header yang ditolak 403.
    // Identitas toko (nama, logo) juga bergantung pada mode.
    outlet.select(null);
    // Mode sudah tersimpan di server; kegagalan memuat ulang tidak boleh tampil sebagai kegagalan beralih mode.
    await Promise.all([auth.user?.id != null ? outlet.load(auth.user.id) : null, settings.load()]).catch(() => {});
    toast.success(t('settings.system_mode_switched', { mode: t(`settings.system_mode_${mode}`) }));
    pendingMode.value = null;
  } catch (err) {
    toast.error(err.message);
  } finally {
    switchingMode.value = false;
  }
}
</script>

<template>
  <SettingsSection :title="t('settings.system_mode_section_title')">
    <div class="flex flex-col gap-2.5">
      <button
        type="button"
        class="flex items-start gap-3 border-2 px-4 py-3.5 text-left transition-colors"
        :class="settings.systemMode === 'live' ? 'border-brand bg-mint-50' : 'border-line hover:border-brand'"
        :disabled="switchingMode"
        @click="requestModeSwitch('live')"
      >
        <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full" :class="settings.systemMode === 'live' ? 'bg-brand' : 'bg-line-2'"></span>
        <span class="text-[13.5px] font-bold">{{ t('settings.system_mode_live') }}</span>
      </button>
      <button
        type="button"
        class="flex items-start gap-3 border-2 px-4 py-3.5 text-left transition-colors"
        :class="settings.systemMode === 'demo' ? 'border-warn-border-strong bg-warn-bg' : 'border-line hover:border-brand'"
        :disabled="switchingMode"
        @click="requestModeSwitch('demo')"
      >
        <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full" :class="settings.systemMode === 'demo' ? 'bg-warn-text' : 'bg-line-2'"></span>
        <span class="text-[13.5px] font-bold">{{ t('settings.system_mode_demo') }}</span>
      </button>
    </div>
    <p class="border-t-2 border-line-3 pt-3.5 text-[11.5px] leading-relaxed text-muted-3">
      {{ t('settings.system_mode_section_desc') }}
    </p>

    <ConfirmDialog
      :open="pendingMode !== null"
      :title="t('settings.system_mode_section_title')"
      :message="pendingMode ? t('settings.system_mode_switch_confirm', { mode: t(`settings.system_mode_${pendingMode}`) }) : ''"
      :confirm-label="t('common.continue')"
      :loading="switchingMode"
      @confirm="confirmModeSwitch"
      @close="pendingMode = null"
    />
  </SettingsSection>
</template>
