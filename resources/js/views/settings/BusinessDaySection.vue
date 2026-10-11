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

// 042-dashboard-revamp (T015) — empat pengaturan yang menentukan "hari bisnis" dan peringatan Dashboard.
// Nilai bawaan di bawah SAMA dengan bawaan server (data-model.md; App\Support\BusinessDay): baris yang belum
// pernah disimpan tidak ada di GET /settings, dan form harus menampilkan angka yang benar-benar berlaku.
const DEFAULTS = {
  store_timezone: 'Asia/Jakarta',
  business_day_cutoff: '00:00',
  cash_difference_tolerance: '5000',
  device_offline_after_minutes: '5',
};

// Zona waktu yang relevan untuk toko di Indonesia ditambah beberapa lain; daftar IANA penuh (400+ nama) hanya
// menyulitkan pemilihan. Nilai yang tersimpan di luar daftar ini tetap ditampilkan (lihat `timezoneOptions`)
// supaya membuka bagian ini tidak diam-diam mengganti zona waktu toko.
const COMMON_TIMEZONES = [
  'Asia/Jakarta',
  'Asia/Makassar',
  'Asia/Jayapura',
  'Asia/Singapore',
  'Asia/Kuala_Lumpur',
  'Asia/Bangkok',
  'Asia/Tokyo',
  'Australia/Perth',
  'UTC',
];

const settings = useSettingsStore();
const toast = useToastStore();
const { t } = useI18n();

const form = reactive({
  store_timezone: DEFAULTS.store_timezone,
  business_day_cutoff: DEFAULTS.business_day_cutoff,
  cash_difference_tolerance: DEFAULTS.cash_difference_tolerance,
  device_offline_after_minutes: DEFAULTS.device_offline_after_minutes,
});
const errors = reactive({});
const loading = ref(true);
const loadFailed = ref(false);
const saving = ref(false);

const timezoneOptions = computed(() => {
  const zones = COMMON_TIMEZONES.includes(form.store_timezone) || !form.store_timezone
    ? COMMON_TIMEZONES
    : [form.store_timezone, ...COMMON_TIMEZONES];
  return zones.map((z) => ({ value: z, label: z }));
});

async function load() {
  loading.value = true;
  loadFailed.value = false;
  try {
    const res = await listSettings();
    const byKey = Object.fromEntries((res.data ?? []).map((s) => [s.key, s.value]));
    Object.keys(DEFAULTS).forEach((key) => {
      const v = byKey[key];
      form[key] = v === undefined || v === null || v === '' ? DEFAULTS[key] : String(v);
    });
  } catch {
    loadFailed.value = true;
  } finally {
    loading.value = false;
  }
}

// Validasi sisi klien hanya untuk umpan balik cepat; aturan yang sama ditegakkan server (UpdateSettingsRequest).
function validate() {
  Object.keys(errors).forEach((k) => delete errors[k]);
  if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(form.business_day_cutoff)) errors.business_day_cutoff = t('settings.business_day_invalid_cutoff');
  const tolerance = Number(form.cash_difference_tolerance);
  if (form.cash_difference_tolerance === '' || !Number.isFinite(tolerance) || tolerance < 0) errors.cash_difference_tolerance = t('settings.business_day_invalid_tolerance');
  const minutes = Number(form.device_offline_after_minutes);
  if (form.device_offline_after_minutes === '' || !Number.isInteger(minutes) || minutes < 1 || minutes > 1440) errors.device_offline_after_minutes = t('settings.business_day_invalid_offline');
  if (!form.store_timezone) errors.store_timezone = t('settings.business_day_invalid_timezone');
  return Object.keys(errors).length === 0;
}

async function save() {
  if (!validate()) return;
  saving.value = true;
  try {
    await settings.setBusinessDay({
      storeTimezone: form.store_timezone,
      businessDayCutoff: form.business_day_cutoff,
      cashDifferenceTolerance: String(Number(form.cash_difference_tolerance)),
      deviceOfflineAfterMinutes: Number(form.device_offline_after_minutes),
    });
    toast.success(t('settings.business_day_saved'));
  } catch (err) {
    if (err.isValidation) {
      // Galat server terikat indeks array ('settings.2.value'); dipetakan ke field lewat urutan kunci yang dikirim.
      const order = ['store_timezone', 'business_day_cutoff', 'cash_difference_tolerance', 'device_offline_after_minutes'];
      Object.entries(err.errors ?? {}).forEach(([field, messages]) => {
        const match = field.match(/^settings\.(\d+)\.value$/);
        if (match) errors[order[Number(match[1])]] = messages[0];
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
  <SettingsSection :title="t('settings.business_day_section_title')">
    <LoadingState v-if="loading" :rows="4" />
    <ErrorState v-else-if="loadFailed" @retry="load" />
    <template v-else>
      <p class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.business_day_intro') }}</p>
      <BaseSelect
        v-model="form.store_timezone"
        :label="t('settings.business_day_timezone_label')"
        :options="timezoneOptions"
        :hint="t('settings.business_day_timezone_hint')"
        :error="errors.store_timezone"
        searchable
      />
      <BaseInput
        v-model="form.business_day_cutoff"
        type="time"
        :label="t('settings.business_day_cutoff_label')"
        :hint="t('settings.business_day_cutoff_hint')"
        :error="errors.business_day_cutoff"
      />
      <BaseInput
        v-model="form.cash_difference_tolerance"
        type="number"
        min="0"
        step="any"
        :label="t('settings.business_day_tolerance_label')"
        :hint="t('settings.business_day_tolerance_hint')"
        :error="errors.cash_difference_tolerance"
      />
      <BaseInput
        v-model="form.device_offline_after_minutes"
        type="number"
        min="1"
        max="1440"
        step="1"
        :label="t('settings.business_day_offline_label')"
        :hint="t('settings.business_day_offline_hint')"
        :error="errors.device_offline_after_minutes"
      />
      <BaseButton size="sm" class="self-start" :loading="saving" @click="save">{{ t('settings.business_day_save') }}</BaseButton>
    </template>
  </SettingsSection>
</template>
