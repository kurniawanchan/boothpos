<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { getServerAddress, saveServerAddress } from '../../api/settings';
import { normalizeAxiosError } from '../../utils/errors';
import { useToastStore } from '../../stores/toast';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import SettingsSection from './SettingsSection.vue';

// --- Alamat server (054 / US3) ----------------------------------------
// Tablet memakai alamat yang dipilih di sini. Aturan URL dan pilihan ditegakkan server (422 per kolom);
// form hanya menampilkan pesan itu di kolom yang sama, tanpa menebak aturannya sendiri.
const { t } = useI18n();
const toast = useToastStore();

const form = reactive({ local: '', online: '', selected: 'local' });
const errors = reactive({});
const loading = ref(true);
const saving = ref(false);
const loadError = ref('');

function apply(data) {
  form.local = data.local ?? '';
  form.online = data.online ?? '';
  form.selected = data.selected ?? 'local';
}

async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    apply(await getServerAddress());
  } catch (err) {
    loadError.value = normalizeAxiosError(err).message;
  } finally {
    loading.value = false;
  }
}

async function save() {
  Object.keys(errors).forEach((k) => delete errors[k]);
  saving.value = true;
  try {
    apply(await saveServerAddress({ local: form.local || null, online: form.online || null, selected: form.selected }));
    toast.success(t('settings.server_address_saved'));
  } catch (err) {
    const normalised = normalizeAxiosError(err);
    if (normalised.isValidation) {
      Object.entries(normalised.errors ?? {}).forEach(([field, messages]) => {
        if (field in form) errors[field] = messages[0];
      });
    } else {
      toast.error(normalised.message);
    }
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<template>
  <SettingsSection :title="t('settings.server_address_section_title')">
    <p class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.server_address_intro') }}</p>
    <p v-if="loadError" class="text-[13px] text-danger">{{ loadError }}</p>
    <BaseInput v-model="form.local" :label="t('settings.server_address_local_label')" :error="errors.local" :hint="t('settings.server_address_hint')" />
    <BaseInput v-model="form.online" :label="t('settings.server_address_online_label')" :error="errors.online" />
    <fieldset class="flex flex-col gap-2">
      <legend class="text-[13px] font-medium">{{ t('settings.server_address_selected_label') }}</legend>
      <label class="flex items-center gap-2 text-[14px]">
        <input v-model="form.selected" type="radio" value="local" />
        {{ t('settings.server_address_choose_local') }}
      </label>
      <label class="flex items-center gap-2 text-[14px]">
        <input v-model="form.selected" type="radio" value="online" />
        {{ t('settings.server_address_choose_online') }}
      </label>
      <p v-if="errors.selected" class="text-[12px] text-danger">{{ errors.selected }}</p>
    </fieldset>
    <BaseButton size="sm" class="self-start" :loading="saving" :disabled="loading" @click="save">{{ t('settings.save_server_address') }}</BaseButton>
  </SettingsSection>
</template>
