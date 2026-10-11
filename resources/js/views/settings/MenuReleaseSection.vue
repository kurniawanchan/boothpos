<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { listSettings } from '../../api/settings';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import BaseSelect from '../../components/ui/BaseSelect.vue';
import LoadingState from '../../components/ui/LoadingState.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import SettingsSection from './SettingsSection.vue';

/**
 * 043 (US10, T068) — Pengaturan -> Rilis menu: saklar `menu_release_enabled`, ambang persetujuan harga (%) dan
 * pembulatan bawaan ubah harga massal. Nilai bawaan di bawah SAMA dengan bawaan server (App\Support\MenuSettings):
 * baris yang belum pernah disimpan tidak ada di GET /settings, dan form harus menampilkan yang benar-benar berlaku
 * (rilis mati = perubahan berlaku seketika, instalasi booth lama tidak berubah perilaku).
 *
 * Halaman Pengaturan sudah digerbang menu `settings` (owner/admin); server memvalidasi kuncinya lagi. Ambang hanya
 * relevan saat rilis aktif, jadi kolomnya tampil hanya saat saklar menyala. Ketiga nilai disimpan dalam satu
 * permintaan massal.
 */
const DEFAULTS = { menu_release_enabled: false, price_approval_threshold_pct: '15', price_rounding_default: '500' };

const settings = useSettingsStore();
const toast = useToastStore();
const { t } = useI18n();

const form = reactive({ enabled: DEFAULTS.menu_release_enabled, threshold: DEFAULTS.price_approval_threshold_pct, rounding: DEFAULTS.price_rounding_default });
const errors = reactive({});
const loading = ref(true);
const loadFailed = ref(false);
const saving = ref(false);

const roundingOptions = computed(() => [
  { value: 'none', label: t('settings.menu_release_rounding_none') },
  { value: '500', label: t('settings.menu_release_rounding_500') },
]);

async function load() {
  loading.value = true;
  loadFailed.value = false;
  try {
    const res = await listSettings();
    const byKey = Object.fromEntries((res.data ?? []).map((s) => [s.key, s.value]));
    const enabled = byKey.menu_release_enabled;
    form.enabled = enabled === undefined || enabled === null || enabled === '' ? DEFAULTS.menu_release_enabled : enabled === true || enabled === 'true' || enabled === '1' || enabled === 1;
    const threshold = byKey.price_approval_threshold_pct;
    form.threshold = threshold === undefined || threshold === null || threshold === '' ? DEFAULTS.price_approval_threshold_pct : String(threshold);
    const rounding = byKey.price_rounding_default;
    form.rounding = ['none', '500'].includes(String(rounding)) ? String(rounding) : DEFAULTS.price_rounding_default;
  } catch {
    loadFailed.value = true;
  } finally {
    loading.value = false;
  }
}

// Validasi sisi klien hanya untuk umpan balik cepat; aturan yang sama ditegakkan server (UpdateSettingsRequest).
function validate() {
  Object.keys(errors).forEach((k) => delete errors[k]);
  const n = Number(form.threshold);
  if (form.threshold === '' || !Number.isFinite(n) || n < 0 || n > 100) errors.threshold = t('settings.menu_release_invalid_threshold');
  return Object.keys(errors).length === 0;
}

async function save() {
  if (!validate()) return;
  saving.value = true;
  try {
    await settings.setMenuRelease({ enabled: form.enabled, thresholdPct: String(Number(form.threshold)), roundingDefault: form.rounding });
    toast.success(t('settings.menu_release_saved'));
  } catch (err) {
    if (err.isValidation) {
      // Galat server terikat indeks array ('settings.1.value'); dipetakan ke kolom lewat urutan kunci yang dikirim.
      const order = [null, 'threshold', 'rounding'];
      Object.entries(err.errors ?? {}).forEach(([field, messages]) => {
        const match = field.match(/^settings\.(\d+)\.value$/);
        const key = match ? order[Number(match[1])] : null;
        if (key) errors[key] = messages[0];
      });
      if (Object.keys(errors).length === 0) toast.error(err.message);
    } else {
      toast.error(err.message);
    }
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <SettingsSection :title="t('settings.menu_release_section_title')">
    <LoadingState v-if="loading" :rows="3" />
    <ErrorState v-else-if="loadFailed" @retry="load" />
    <template v-else>
      <p class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.menu_release_intro') }}</p>
      <label class="flex cursor-pointer items-start gap-3">
        <input v-model="form.enabled" type="checkbox" role="switch" class="mt-0.5 h-4 w-4 flex-none accent-brand" />
        <span class="flex flex-col gap-0.5">
          <span class="text-[13.5px] font-bold">{{ t('settings.menu_release_enable') }}</span>
          <span class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.menu_release_enable_hint') }}</span>
        </span>
      </label>
      <BaseInput
        v-if="form.enabled"
        v-model="form.threshold"
        type="number"
        min="0"
        max="100"
        step="any"
        :label="t('settings.menu_release_threshold')"
        :hint="t('settings.menu_release_threshold_hint')"
        :error="errors.threshold"
      />
      <BaseSelect v-model="form.rounding" :label="t('settings.menu_release_rounding')" :options="roundingOptions" :error="errors.rounding" />
      <BaseButton size="sm" class="self-start" :loading="saving" @click="save">{{ t('settings.menu_release_save') }}</BaseButton>
    </template>
  </SettingsSection>
</template>
