<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../stores/settings';
import { useToastStore } from '../stores/toast';
import { listSettings, updateSettings, uploadStoreLogo } from '../api/settings';
import { listPaymentChannels, createPaymentChannel, updatePaymentChannel } from '../api/payments';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import StatusPill from '../components/ui/StatusPill.vue';

const settings = useSettingsStore();
const { t } = useI18n();
const toast = useToastStore();

const changingTier = ref(false);

async function pickTier(enabled) {
  if (enabled === settings.multiArtistEnabled) return;
  changingTier.value = true;
  try {
    await updateSettings([{ key: 'multi_artist_enabled', value: enabled, type: 'boolean', group: 'licensing' }]);
    toast.success(t('settings.switched_to', { tier: enabled ? t('settings.master') : t('settings.pro') }));
    await settings.load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    changingTier.value = false;
  }
}

// --- Store identity (T047, US3 — profil toko lengkap) ---------------------
const storeForm = reactive({
  store_name: '',
  store_contact: '',
  store_address: '',
  store_contact_person: '',
  store_contact_phone: '',
  store_contact_email: '',
});
const storeErrors = reactive({});
const savingStore = ref(false);
const storeLogoPath = ref(null);
// value mentah dari `settings` (mis. 'store-logo/uuid.png') bukan URL —
// SettingResource tidak menyertakan *_url turunan seperti CategoryResource,
// jadi URL dibangun di sini mengikuti konvensi disk 'public' ImageUploadService
// (storage:link -> /storage/...), sama seperti bagaimana ProductResource/
// CategoryResource sendiri pada akhirnya membangunnya via Storage::url().
const storeLogoUrl = computed(() => (storeLogoPath.value ? `/storage/${storeLogoPath.value}` : null));
const logoFile = ref(null);
const logoError = ref('');
const uploadingLogo = ref(false);
const logoInputEl = ref(null);

async function loadStoreIdentity() {
  const res = await listSettings();
  const byKey = Object.fromEntries(res.data.map((s) => [s.key, s.value]));
  storeForm.store_name = byKey.store_name ?? '';
  storeForm.store_contact = byKey.store_contact ?? '';
  storeForm.store_address = byKey.store_address ?? '';
  storeForm.store_contact_person = byKey.store_contact_person ?? '';
  storeForm.store_contact_phone = byKey.store_contact_phone ?? '';
  storeForm.store_contact_email = byKey.store_contact_email ?? '';
  storeLogoPath.value = byKey.store_logo_path ?? null;
}

async function saveStoreIdentity() {
  savingStore.value = true;
  Object.keys(storeErrors).forEach((k) => delete storeErrors[k]);
  try {
    await updateSettings([
      { key: 'store_name', value: storeForm.store_name, type: 'string', group: 'receipt' },
      { key: 'store_contact', value: storeForm.store_contact, type: 'string', group: 'receipt' },
      { key: 'store_address', value: storeForm.store_address, type: 'string', group: 'receipt' },
      { key: 'store_contact_person', value: storeForm.store_contact_person, type: 'string', group: 'receipt' },
      { key: 'store_contact_phone', value: storeForm.store_contact_phone, type: 'string', group: 'receipt' },
      { key: 'store_contact_email', value: storeForm.store_contact_email, type: 'string', group: 'receipt' },
    ]);
    toast.success(t('settings.store_identity_saved'));
  } catch (err) {
    if (err.isValidation) {
      // Backend mengembalikan error terikat indeks array ('settings.5.value')
      // — dipetakan balik ke nama field yang dikenali form ini lewat urutan
      // key yang sama persis dengan array di atas.
      const order = ['store_name', 'store_contact', 'store_address', 'store_contact_person', 'store_contact_phone', 'store_contact_email'];
      Object.entries(err.errors).forEach(([field, messages]) => {
        const match = field.match(/^settings\.(\d+)\.value$/);
        if (match) storeErrors[order[Number(match[1])]] = messages[0];
      });
      if (Object.keys(storeErrors).length === 0) toast.error(err.message);
    } else {
      toast.error(err.message);
    }
  } finally {
    savingStore.value = false;
  }
}

function onLogoChange(e) {
  const file = e.target.files?.[0] ?? null;
  logoError.value = '';
  logoFile.value = null;
  if (!file) return;
  if (!file.type.startsWith('image/')) {
    logoError.value = t('settings.image_must_be_image');
    e.target.value = '';
    return;
  }
  if (file.size > MAX_IMAGE_BYTES) {
    logoError.value = t('settings.image_max_size');
    e.target.value = '';
    return;
  }
  logoFile.value = file;
}

async function saveLogo() {
  if (!logoFile.value) return;
  uploadingLogo.value = true;
  try {
    const res = await uploadStoreLogo(logoFile.value);
    storeLogoPath.value = res.data.value;
    logoFile.value = null;
    if (logoInputEl.value) logoInputEl.value.value = '';
    toast.success(t('settings.store_logo_updated'));
  } catch (err) {
    logoError.value = err.isValidation ? Object.values(err.errors)[0]?.[0] ?? err.message : err.message;
  } finally {
    uploadingLogo.value = false;
  }
}

// --- Payment channels ------------------------------------------------------
const channels = ref([]);
const showChannelForm = ref(false);
const editingChannel = ref(null); // null = create, otherwise the channel being edited
const channelForm = reactive({ type: 'bank_transfer', provider: '', account_name: '', account_number: '', display_order: 0 });
const savingChannel = ref(false);
const channelErrors = reactive({});

// Client-side file guard, same pattern as MasterDataImportModal's .xlsx
// check — fail fast before a round trip. ASSUMPTION: 5 MB cap and
// image/* mime, since the backend contract doesn't specify a limit here.
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const qrImageFile = ref(null);
const qrImageError = ref('');
const removeQrImage = ref(false);
const qrImageInputEl = ref(null);

async function loadChannels() {
  channels.value = (await listPaymentChannels()).data;
}

function resetChannelForm() {
  qrImageFile.value = null;
  qrImageError.value = '';
  removeQrImage.value = false;
  if (qrImageInputEl.value) qrImageInputEl.value.value = '';
}

function openChannelForm() {
  editingChannel.value = null;
  Object.assign(channelForm, { type: 'bank_transfer', provider: '', account_name: '', account_number: '', display_order: channels.value.length });
  Object.keys(channelErrors).forEach((k) => delete channelErrors[k]);
  resetChannelForm();
  showChannelForm.value = true;
}

function openChannelEdit(channel) {
  editingChannel.value = channel;
  Object.assign(channelForm, {
    type: channel.type,
    provider: channel.provider,
    account_name: channel.account_name,
    account_number: channel.account_number ?? '',
    display_order: channel.display_order ?? 0,
  });
  Object.keys(channelErrors).forEach((k) => delete channelErrors[k]);
  resetChannelForm();
  showChannelForm.value = true;
}

function onQrImageChange(e) {
  const file = e.target.files?.[0] ?? null;
  qrImageError.value = '';
  qrImageFile.value = null;
  if (!file) return;
  if (!file.type.startsWith('image/')) {
    qrImageError.value = t('settings.image_must_be_image');
    e.target.value = '';
    return;
  }
  if (file.size > MAX_IMAGE_BYTES) {
    qrImageError.value = t('settings.image_max_size');
    e.target.value = '';
    return;
  }
  qrImageFile.value = file;
  removeQrImage.value = false;
}

async function saveChannel() {
  savingChannel.value = true;
  Object.keys(channelErrors).forEach((k) => delete channelErrors[k]);
  const payload = { ...channelForm, display_order: Number(channelForm.display_order) || 0 };
  try {
    if (editingChannel.value) {
      await updatePaymentChannel(editingChannel.value.id, payload, { qrImage: qrImageFile.value, removeQrImage: removeQrImage.value });
      toast.success(t('settings.channel_updated'));
    } else {
      await createPaymentChannel(payload, { qrImage: qrImageFile.value });
      toast.success(t('settings.channel_created'));
    }
    showChannelForm.value = false;
    await loadChannels();
  } catch (err) {
    if (err.isValidation) Object.assign(channelErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
  } finally {
    savingChannel.value = false;
  }
}

onMounted(async () => {
  await settings.load();
  await Promise.all([loadStoreIdentity(), loadChannels()]);
});
</script>

<template>
  <div class="grid grid-cols-1 items-start gap-[18px] px-[26px] pb-10 pt-[22px] xl:grid-cols-2">
    <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
      <span class="text-[15px] font-bold tracking-tight">{{ t('settings.license_tier') }}</span>
      <div class="flex flex-col gap-2.5">
        <button
          type="button"
          class="flex items-start gap-3 rounded-lg border px-4 py-3.5 text-left transition-colors"
          :class="!settings.multiArtistEnabled ? 'border-brand bg-mint-50' : 'border-line hover:border-brand'"
          :disabled="changingTier"
          @click="pickTier(false)"
        >
          <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full" :class="!settings.multiArtistEnabled ? 'bg-brand' : 'bg-line-2'"></span>
          <span class="flex flex-col gap-0.5">
            <span class="text-[13.5px] font-bold">{{ t('settings.pro') }}</span>
            <span class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.pro_desc') }}</span>
          </span>
        </button>
        <button
          type="button"
          class="flex items-start gap-3 rounded-lg border px-4 py-3.5 text-left transition-colors"
          :class="settings.multiArtistEnabled ? 'border-brand bg-mint-50' : 'border-line hover:border-brand'"
          :disabled="changingTier"
          @click="pickTier(true)"
        >
          <span class="mt-1 h-2.5 w-2.5 flex-none rounded-full" :class="settings.multiArtistEnabled ? 'bg-brand' : 'bg-line-2'"></span>
          <span class="flex flex-col gap-0.5">
            <span class="text-[13.5px] font-bold">{{ t('settings.master') }}</span>
            <span class="text-[12px] leading-relaxed text-muted-3">{{ t('settings.master_desc') }}</span>
          </span>
        </button>
      </div>
      <p class="border-t border-line-3 pt-3.5 text-[11.5px] leading-relaxed text-muted-3">
        {{ t('settings.tier_switch_note') }}
      </p>
    </div>

    <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
      <div class="flex items-center justify-between">
        <span class="text-[15px] font-bold tracking-tight">{{ t('settings.payment_channels') }}</span>
        <BaseButton variant="secondary" size="sm" @click="openChannelForm">{{ t('settings.add') }}</BaseButton>
      </div>
      <div v-for="c in channels" :key="c.id" class="flex items-center gap-3 rounded-lg border border-line-3 bg-surface-subtle px-3.5 py-3">
        <img v-if="c.qr_image_url" :src="c.qr_image_url" :alt="t('pos.qr_code_for', { provider: c.provider })" class="h-10 w-10 flex-none rounded-md border border-line-2 object-contain" />
        <i v-else class="ph-duotone text-[21px] text-brand" :class="c.type === 'bank_transfer' ? 'ph-bank' : 'ph-qr-code'" aria-hidden="true"></i>
        <div class="flex flex-1 flex-col gap-0.5">
          <span class="text-[13.5px] font-bold">{{ c.provider }}</span>
          <span class="font-mono text-[12px] text-muted-2">{{ t('settings.channel_account_number', { number: c.account_number || '—', name: c.account_name }) }}</span>
        </div>
        <StatusPill :variant="c.is_active ? 'mint' : 'neutral'">{{ c.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
        <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openChannelEdit(c)">{{ t('common.edit') }}</button>
      </div>
      <p class="border-t border-line-3 pt-3.5 text-[11.5px] leading-relaxed text-muted-3">
        {{ t('settings.cashier_masked_number_note') }}
      </p>
    </div>

    <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
      <span class="text-[15px] font-bold tracking-tight">{{ t('settings.store_identity') }}</span>

      <div class="flex flex-col gap-1.5">
        <label class="text-[12.5px] font-semibold text-muted-4" for="store-logo">{{ t('settings.store_logo') }}</label>
        <div class="flex items-center gap-3">
          <img
            v-if="storeLogoUrl"
            :src="storeLogoUrl"
            :alt="t('settings.current_store_logo')"
            class="h-16 w-16 flex-none rounded-md border border-line-2 object-contain"
          />
          <div class="flex flex-1 flex-col gap-1.5">
            <input
              id="store-logo"
              ref="logoInputEl"
              type="file"
              accept="image/*"
              class="rounded-lg border border-line bg-white px-3.5 py-2.5 text-[13px] file:mr-3 file:rounded-md file:border-0 file:bg-mint-100 file:px-3 file:py-1.5 file:text-[12.5px] file:font-bold file:text-brand-active"
              @change="onLogoChange"
            />
            <p v-if="logoError" class="text-[12px] font-semibold text-danger-text">{{ logoError }}</p>
            <BaseButton v-if="logoFile" size="sm" class="self-start" :loading="uploadingLogo" @click="saveLogo">{{ t('settings.upload_logo') }}</BaseButton>
          </div>
        </div>
      </div>

      <BaseInput v-model="storeForm.store_name" :label="t('settings.store_name_receipt')" />
      <label class="flex flex-col gap-1.5">
        <span class="text-[12.5px] font-semibold text-muted-4">{{ t('settings.full_address') }}</span>
        <textarea
          v-model="storeForm.store_address"
          rows="3"
          class="rounded-lg border bg-white px-3.5 py-2.5 text-[14.5px] text-ink outline-none transition-colors placeholder:text-muted-3 focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          :class="storeErrors.store_address ? 'border-danger-border' : 'border-line'"
        ></textarea>
        <span v-if="storeErrors.store_address" class="text-[12px] font-medium text-danger-text">{{ storeErrors.store_address }}</span>
      </label>
      <BaseInput v-model="storeForm.store_contact" :label="t('settings.store_contact')" />
      <BaseInput v-model="storeForm.store_contact_person" :label="t('settings.contact_person_name')" :error="storeErrors.store_contact_person" />
      <BaseInput v-model="storeForm.store_contact_phone" :label="t('settings.phone')" :error="storeErrors.store_contact_phone" />
      <BaseInput v-model="storeForm.store_contact_email" :label="t('settings.email')" type="email" :error="storeErrors.store_contact_email" />
      <BaseButton class="self-start" :loading="savingStore" @click="saveStoreIdentity">{{ t('common.save') }}</BaseButton>
    </div>

    <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
      <span class="text-[15px] font-bold tracking-tight">{{ t('settings.data_backup') }}</span>
      <div class="flex items-center gap-3 rounded-lg border border-mint-border bg-mint-50 px-3.5 py-3">
        <i class="ph-duotone ph-hard-drives text-[22px] text-brand" aria-hidden="true"></i>
        <div class="flex flex-1 flex-col gap-0.5">
          <span class="text-[13px] font-bold text-brand-active">{{ t('settings.run_from_server_console') }}</span>
          <span class="text-[11.5px] text-muted-4">{{ t('settings.backup_command_note') }}</span>
        </div>
      </div>
      <p class="text-[11.5px] leading-relaxed text-muted-3">
        {{ t('settings.backup_files_note') }}
      </p>
    </div>

    <BaseModal :open="showChannelForm" :title="editingChannel ? t('settings.edit_channel') : t('settings.add_channel')" max-width-class="max-w-[440px]" @close="showChannelForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveChannel">
        <BaseSelect v-model="channelForm.type" :label="t('settings.type')" :options="[{ value: 'bank_transfer', label: t('settings.bank_transfer') }, { value: 'qr_ewallet', label: t('settings.qr_ewallet') }]" />
        <BaseInput v-model="channelForm.provider" :label="t('settings.provider_name')" required :error="channelErrors.provider" />
        <BaseInput v-model="channelForm.account_name" :label="t('settings.account_holder_name')" required :error="channelErrors.account_name" />
        <BaseInput v-if="channelForm.type === 'bank_transfer'" v-model="channelForm.account_number" :label="t('settings.account_number')" required :error="channelErrors.account_number" />

        <div v-if="channelForm.type === 'qr_ewallet'" class="flex flex-col gap-1.5">
          <label class="text-[12.5px] font-semibold text-muted-4" for="channel-qr-image">{{ t('settings.qr_code_image') }}</label>
          <img
            v-if="editingChannel?.qr_image_url && !removeQrImage && !qrImageFile"
            :src="editingChannel.qr_image_url"
            :alt="t('settings.current_qr_code')"
            class="h-28 w-28 self-start rounded-md border border-line-2 object-contain"
          />
          <input
            id="channel-qr-image"
            ref="qrImageInputEl"
            type="file"
            accept="image/*"
            class="rounded-lg border border-line bg-white px-3.5 py-2.5 text-[13px] file:mr-3 file:rounded-md file:border-0 file:bg-mint-100 file:px-3 file:py-1.5 file:text-[12.5px] file:font-bold file:text-brand-active"
            @change="onQrImageChange"
          />
          <p v-if="qrImageError" class="text-[12px] font-semibold text-danger-text">{{ qrImageError }}</p>
          <label v-if="editingChannel?.qr_image_url" class="flex items-center gap-2 text-[12.5px] font-semibold text-muted-4">
            <input v-model="removeQrImage" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
            {{ t('settings.remove_existing_qr') }}
          </label>
        </div>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showChannelForm = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="savingChannel" @click="saveChannel">{{ t('common.save') }}</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
