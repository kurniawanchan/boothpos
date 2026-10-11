<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useToastStore } from '../../stores/toast';
import { listPaymentChannels, createPaymentChannel, updatePaymentChannel, deletePaymentChannel } from '../../api/payments';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import BaseSelect from '../../components/ui/BaseSelect.vue';
import BaseModal from '../../components/ui/BaseModal.vue';
import ConfirmDialog from '../../components/ui/ConfirmDialog.vue';
import StatusPill from '../../components/ui/StatusPill.vue';
import SettingsSection from './SettingsSection.vue';

// --- Kanal pembayaran ------------------------------------------------------
const { t } = useI18n();
const toast = useToastStore();

const channels = ref([]);
const showChannelForm = ref(false);
const editingChannel = ref(null); // null = buat baru, selain itu kanal yang sedang diedit
const deleteChannelId = ref(null);
const deletingChannel = ref(false);
const channelForm = reactive({ type: 'bank_transfer', provider: '', account_name: '', account_number: '', display_order: 0 });
const savingChannel = ref(false);
const channelErrors = reactive({});

// Guard berkas di sisi klien, pola yang sama dengan cek .xlsx MasterDataImportModal —
// gagal cepat sebelum round trip. ASSUMPTION: batas 5 MB dan mime image/*,
// karena kontrak backend tidak menyebut batas.
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

// 024-invoice-layout-shipping-slip (US6) — hapus kanal pembayaran (soft delete).
function askDeleteChannel(channel) {
  deleteChannelId.value = channel.id;
}
async function confirmDeleteChannel() {
  if (!deleteChannelId.value) return;
  deletingChannel.value = true;
  try {
    await deletePaymentChannel(deleteChannelId.value);
    toast.success(t('settings.channel_deleted'));
    await loadChannels();
  } catch (err) {
    toast.error(err.message || t('settings.channel_delete_failed'));
  } finally {
    deletingChannel.value = false;
    deleteChannelId.value = null;
  }
}

onMounted(loadChannels);
</script>

<template>
  <SettingsSection :title="t('settings.payment_channels')">
    <div class="flex justify-end">
      <BaseButton variant="secondary" size="sm" @click="openChannelForm">{{ t('settings.add') }}</BaseButton>
    </div>
    <div v-for="c in channels" :key="c.id" class="flex items-center gap-3 border-2 border-line-3 bg-surface-subtle px-3.5 py-3">
      <img v-if="c.qr_image_url" :src="c.qr_image_url" :alt="t('pos.qr_code_for', { provider: c.provider })" class="h-10 w-10 flex-none border-2 border-line object-contain" />
      <i v-else class="ph-duotone text-[21px] text-brand" :class="c.type === 'bank_transfer' ? 'ph-bank' : 'ph-qr-code'" aria-hidden="true"></i>
      <div class="flex flex-1 flex-col gap-0.5">
        <span class="text-[13.5px] font-bold">{{ c.provider }}</span>
        <span class="font-mono text-[12px] text-muted-2">{{ t('settings.channel_account_number', { number: c.account_number || '—', name: c.account_name }) }}</span>
      </div>
      <StatusPill :variant="c.is_active ? 'success' : 'neutral'">{{ c.is_active ? t('common.active') : t('common.inactive') }}</StatusPill>
      <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-danger-text" @click="askDeleteChannel(c)">{{ t('common.delete') }}</button>
      <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openChannelEdit(c)">{{ t('common.edit') }}</button>
    </div>
    <p class="border-t-2 border-line-3 pt-3.5 text-[11.5px] leading-relaxed text-muted-3">
      {{ t('settings.cashier_masked_number_note') }}
    </p>

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
            class="h-28 w-28 self-start border-2 border-line object-contain"
          />
          <input
            id="channel-qr-image"
            ref="qrImageInputEl"
            type="file"
            accept="image/*"
            class="border-2 border-line bg-white px-3.5 py-2.5 text-[13px] file:mr-3 file:border-0 file:bg-mint-100 file:px-3 file:py-1.5 file:text-[12.5px] file:font-bold file:text-brand-active"
            @change="onQrImageChange"
          />
          <p v-if="qrImageError" class="text-[12px] font-semibold text-danger-text">{{ qrImageError }}</p>
          <label v-if="editingChannel?.qr_image_url" class="flex items-center gap-2 text-[12.5px] font-semibold text-muted-4">
            <input v-model="removeQrImage" type="checkbox" class="h-4 w-4 border-line accent-brand" />
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

    <ConfirmDialog
      :open="deleteChannelId !== null"
      :title="t('settings.delete_channel')"
      :message="t('settings.delete_channel_confirm', { provider: channels.find((c) => c.id === deleteChannelId)?.provider ?? '' })"
      :confirm-label="t('common.delete')"
      :loading="deletingChannel"
      @confirm="confirmDeleteChannel"
      @close="deleteChannelId = null"
    />
  </SettingsSection>
</template>
