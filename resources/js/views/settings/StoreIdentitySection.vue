<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useSettingsStore } from '../../stores/settings';
import { useToastStore } from '../../stores/toast';
import { listSettings, updateSettings, uploadStoreLogo } from '../../api/settings';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseInput from '../../components/ui/BaseInput.vue';
import SettingsSection from './SettingsSection.vue';

// --- Identitas toko (T047, US3 — profil toko lengkap) ----------------------
const settings = useSettingsStore();
const { t } = useI18n();
const toast = useToastStore();

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
// 023-event-availability-invoice-redesign (US4, research.md Decision 8) —
// SEBELUMNYA dibangun manual di sini (`/storage/${path}`), satu-satunya
// tempat di seluruh produk ini yang menebak URL gambar publik sendiri
// alih-alih memakai ImageUploadService::url() seperti gambar lain
// (produk, kategori, QR kanal pembayaran, dokumen invoice). Sekarang
// backend yang mengembalikan URL final lewat GET /settings /
// POST /settings/store-logo — dihapus, bukan diperbaiki, supaya tidak
// ada dua jalur pembangunan URL yang bisa berbeda.
const storeLogoUrl = ref(null);
const logoFile = ref(null);
const logoError = ref('');
const uploadingLogo = ref(false);
const logoInputEl = ref(null);

// Guard berkas di sisi klien — gagal cepat sebelum round trip. ASSUMPTION:
// batas 5 MB dan mime image/*, karena kontrak backend tidak menyebut batas.
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

// 003-seed-demo-live follow-up — nama toko disimpan sebagai KEY TERPISAH
// per mode ('store_name' untuk LIVE, 'store_name_demo' untuk DEMO) supaya
// mengedit satu tidak pernah menimpa yang lain (lihat SakanaFridgeDemoSeeder
// dan OrderController::receipt() untuk sisi baca lainnya). Field lain
// (kontak, alamat) sengaja tetap satu key bersama — hanya "nama toko" yang
// diminta dibedakan per mode.
const storeNameKey = computed(() => (settings.isDemoMode ? 'store_name_demo' : 'store_name'));

async function loadStoreIdentity() {
  const res = await listSettings();
  const byKey = Object.fromEntries(res.data.map((s) => [s.key, s.value]));
  storeForm.store_name = byKey[storeNameKey.value] ?? '';
  storeForm.store_contact = byKey.store_contact ?? '';
  storeForm.store_address = byKey.store_address ?? '';
  storeForm.store_contact_person = byKey.store_contact_person ?? '';
  storeForm.store_contact_phone = byKey.store_contact_phone ?? '';
  storeForm.store_contact_email = byKey.store_contact_email ?? '';
  storeLogoUrl.value = res.store_logo_url ?? null;
}

async function saveStoreIdentity() {
  savingStore.value = true;
  Object.keys(storeErrors).forEach((k) => delete storeErrors[k]);
  try {
    await updateSettings([
      { key: storeNameKey.value, value: storeForm.store_name, type: 'string', group: 'receipt' },
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
    storeLogoUrl.value = res.store_logo_url;
    logoFile.value = null;
    if (logoInputEl.value) logoInputEl.value.value = '';
    toast.success(t('settings.store_logo_updated'));
  } catch (err) {
    logoError.value = err.isValidation ? Object.values(err.errors)[0]?.[0] ?? err.message : err.message;
  } finally {
    uploadingLogo.value = false;
  }
}

onMounted(loadStoreIdentity);
</script>

<template>
  <SettingsSection :title="t('settings.store_identity')">
    <div class="flex flex-col gap-1.5">
      <label class="text-[12.5px] font-semibold text-muted-4" for="store-logo">{{ t('settings.store_logo') }}</label>
      <div class="flex items-center gap-3">
        <img
          v-if="storeLogoUrl"
          :src="storeLogoUrl"
          :alt="t('settings.current_store_logo')"
          class="h-16 w-16 flex-none border-2 border-line object-contain"
        />
        <div class="flex flex-1 flex-col gap-1.5">
          <input
            id="store-logo"
            ref="logoInputEl"
            type="file"
            accept="image/*"
            class="border-2 border-line bg-white px-3.5 py-2.5 text-[13px] file:mr-3 file:border-0 file:bg-mint-100 file:px-3 file:py-1.5 file:text-[12.5px] file:font-bold file:text-brand-active"
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
        class="border-2 bg-white px-3.5 py-2.5 text-[14.5px] text-ink outline-none transition-colors placeholder:text-muted-3 focus:border-brand focus:ring-[3px] focus:ring-mint-100"
        :class="storeErrors.store_address ? 'border-danger-border' : 'border-line'"
      ></textarea>
      <span v-if="storeErrors.store_address" class="text-[12px] font-medium text-danger-text">{{ storeErrors.store_address }}</span>
    </label>
    <BaseInput v-model="storeForm.store_contact" :label="t('settings.store_contact')" />
    <BaseInput v-model="storeForm.store_contact_person" :label="t('settings.contact_person_name')" :error="storeErrors.store_contact_person" />
    <BaseInput v-model="storeForm.store_contact_phone" :label="t('settings.phone')" :error="storeErrors.store_contact_phone" />
    <BaseInput v-model="storeForm.store_contact_email" :label="t('settings.email')" type="email" :error="storeErrors.store_contact_email" />
    <BaseButton class="self-start" :loading="savingStore" @click="saveStoreIdentity">{{ t('common.save') }}</BaseButton>
  </SettingsSection>
</template>
