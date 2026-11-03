<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { listSettings, updateSettings } from '../../api/settings';
import { useToastStore } from '../../stores/toast';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import LoadingState from '../../components/ui/LoadingState.vue';
import ErrorState from '../../components/ui/ErrorState.vue';
import SettingsSection from './SettingsSection.vue';
import { isReachableBaseUrl } from '../../utils/selfServiceBaseUrl';

// 090 / US2 — alamat dasar yang masuk ke QR meja. Harus bisa dijangkau ponsel pelanggan (alamat LAN atau domain), bukan
// localhost. Kosong = pakai asal halaman staf bila bukan localhost. Aturan yang sama ditegakkan server (SelfServiceSettings).
const KEY = 'self_service_base_url';
const { t } = useI18n();
const toast = useToastStore();

const value = ref('');
const error = ref('');
const loading = ref(true);
const loadFailed = ref(false);
const saving = ref(false);

async function load() {
  loading.value = true;
  loadFailed.value = false;
  try {
    const res = await listSettings();
    value.value = String((res.data ?? []).find((s) => s.key === KEY)?.value ?? '');
  } catch {
    loadFailed.value = true;
  } finally {
    loading.value = false;
  }
}

async function save() {
  error.value = '';
  if (value.value.trim() !== '' && !isReachableBaseUrl(value.value)) {
    error.value = t('settings.self_service_invalid');
    return;
  }
  saving.value = true;
  try {
    await updateSettings([{ key: KEY, value: value.value.trim() || null, type: 'string', group: 'self_service' }]);
    toast.success(t('settings.self_service_saved'));
  } catch (err) {
    if (err.isValidation) error.value = Object.values(err.errors ?? {})[0]?.[0] ?? err.message;
    else toast.error(err.message);
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <SettingsSection :title="t('settings.self_service_section_title')">
    <LoadingState v-if="loading" :rows="2" />
    <ErrorState v-else-if="loadFailed" @retry="load" />
    <template v-else>
      <p class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.self_service_intro') }}</p>
      <BaseInput
        v-model="value"
        type="url"
        :label="t('settings.self_service_base_url_label')"
        :placeholder="t('settings.self_service_base_url_placeholder')"
        :hint="t('settings.self_service_base_url_hint')"
        :error="error"
        autocomplete="off"
      />
      <BaseButton size="sm" class="self-start" :loading="saving" @click="save">{{ t('settings.self_service_save') }}</BaseButton>
    </template>
  </SettingsSection>
</template>
