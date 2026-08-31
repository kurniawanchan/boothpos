<script setup>
import { reactive, ref, onMounted } from 'vue';
import { useSettingsStore } from '../stores/settings';
import { useToastStore } from '../stores/toast';
import { listSettings, updateSettings } from '../api/settings';
import { listPaymentChannels, createPaymentChannel } from '../api/payments';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import StatusPill from '../components/ui/StatusPill.vue';

const settings = useSettingsStore();
const toast = useToastStore();

const changingTier = ref(false);

async function pickTier(enabled) {
  if (enabled === settings.multiArtistEnabled) return;
  changingTier.value = true;
  try {
    await updateSettings([{ key: 'multi_artist_enabled', value: enabled, type: 'boolean', group: 'licensing' }]);
    toast.success(`Beralih ke ${enabled ? 'Master' : 'Pro'}.`);
    await settings.load();
  } catch (err) {
    toast.error(err.message);
  } finally {
    changingTier.value = false;
  }
}

// --- Store identity ------------------------------------------------------
const storeForm = reactive({ store_name: '', store_contact: '' });
const savingStore = ref(false);

async function loadStoreIdentity() {
  const res = await listSettings();
  const byKey = Object.fromEntries(res.data.map((s) => [s.key, s.value]));
  storeForm.store_name = byKey.store_name ?? '';
  storeForm.store_contact = byKey.store_contact ?? '';
}

async function saveStoreIdentity() {
  savingStore.value = true;
  try {
    await updateSettings([
      { key: 'store_name', value: storeForm.store_name, type: 'string', group: 'receipt' },
      { key: 'store_contact', value: storeForm.store_contact, type: 'string', group: 'receipt' },
    ]);
    toast.success('Identitas toko disimpan.');
  } catch (err) {
    toast.error(err.message);
  } finally {
    savingStore.value = false;
  }
}

// --- Payment channels ------------------------------------------------------
const channels = ref([]);
const showChannelForm = ref(false);
const channelForm = reactive({ type: 'bank_transfer', provider: '', account_name: '', account_number: '', display_order: 0 });
const savingChannel = ref(false);
const channelErrors = reactive({});

async function loadChannels() {
  channels.value = (await listPaymentChannels()).data;
}

function openChannelForm() {
  Object.assign(channelForm, { type: 'bank_transfer', provider: '', account_name: '', account_number: '', display_order: channels.value.length });
  Object.keys(channelErrors).forEach((k) => delete channelErrors[k]);
  showChannelForm.value = true;
}

async function saveChannel() {
  savingChannel.value = true;
  Object.keys(channelErrors).forEach((k) => delete channelErrors[k]);
  try {
    await createPaymentChannel({ ...channelForm, display_order: Number(channelForm.display_order) || 0 });
    toast.success('Kanal pembayaran ditambahkan.');
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
      <span class="text-[15px] font-bold tracking-tight">Tingkat lisensi</span>
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
            <span class="text-[13.5px] font-bold">Pro</span>
            <span class="text-[12px] leading-relaxed text-muted-3">Satu artist aktif (mewakili toko sendiri).</span>
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
            <span class="text-[13.5px] font-bold">Master</span>
            <span class="text-[12px] leading-relaxed text-muted-3">Jumlah artist tidak dibatasi.</span>
          </span>
        </button>
      </div>
      <p class="border-t border-line-3 pt-3.5 text-[11.5px] leading-relaxed text-muted-3">
        Peralihan Master → Pro tidak menghapus atau menggabungkan data artist yang sudah ada; sistem hanya memblokir pembuatan artist baru.
      </p>
    </div>

    <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
      <div class="flex items-center justify-between">
        <span class="text-[15px] font-bold tracking-tight">Kanal pembayaran</span>
        <BaseButton variant="secondary" size="sm" @click="openChannelForm">Tambah</BaseButton>
      </div>
      <div v-for="c in channels" :key="c.id" class="flex items-center gap-3 rounded-lg border border-line-3 bg-surface-subtle px-3.5 py-3">
        <i class="ph-duotone text-[21px] text-brand" :class="c.type === 'bank_transfer' ? 'ph-bank' : 'ph-qr-code'" aria-hidden="true"></i>
        <div class="flex flex-1 flex-col gap-0.5">
          <span class="text-[13.5px] font-bold">{{ c.provider }}</span>
          <span class="font-mono text-[12px] text-muted-2">{{ c.account_number || '—' }} · a.n. {{ c.account_name }}</span>
        </div>
        <StatusPill :variant="c.is_active ? 'mint' : 'neutral'">{{ c.is_active ? 'Aktif' : 'Nonaktif' }}</StatusPill>
      </div>
      <p class="border-t border-line-3 pt-3.5 text-[11.5px] leading-relaxed text-muted-3">
        Peran kasir menerima nomor tersamar pada daftar; nomor penuh hanya muncul saat kanal dipilih untuk satu transaksi.
      </p>
    </div>

    <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
      <span class="text-[15px] font-bold tracking-tight">Identitas toko</span>
      <BaseInput v-model="storeForm.store_name" label="Nama toko (tercetak di struk)" />
      <BaseInput v-model="storeForm.store_contact" label="Kontak toko" />
      <BaseButton class="self-start" :loading="savingStore" @click="saveStoreIdentity">Simpan</BaseButton>
    </div>

    <div class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
      <span class="text-[15px] font-bold tracking-tight">Cadangan data</span>
      <div class="flex items-center gap-3 rounded-lg border border-mint-border bg-mint-50 px-3.5 py-3">
        <i class="ph-duotone ph-hard-drives text-[22px] text-brand" aria-hidden="true"></i>
        <div class="flex flex-1 flex-col gap-0.5">
          <span class="text-[13px] font-bold text-brand-active">Dijalankan dari konsol server</span>
          <span class="text-[11.5px] text-muted-4">php artisan app:backup — tidak ada endpoint HTTP untuk ini.</span>
        </div>
      </div>
      <p class="text-[11.5px] leading-relaxed text-muted-3">
        Berkas bukti pembayaran dicadangkan terpisah dari dump basis data. Jalankan perintah di atas langsung di server tempat BoothPOS terpasang, bukan dari layar ini.
      </p>
    </div>

    <BaseModal :open="showChannelForm" title="Tambah kanal pembayaran" max-width-class="max-w-[440px]" @close="showChannelForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveChannel">
        <BaseSelect v-model="channelForm.type" label="Tipe" :options="[{ value: 'bank_transfer', label: 'Transfer bank' }, { value: 'qr_ewallet', label: 'QR e-wallet' }]" />
        <BaseInput v-model="channelForm.provider" label="Nama penyedia" required :error="channelErrors.provider" />
        <BaseInput v-model="channelForm.account_name" label="Nama pemilik rekening" required :error="channelErrors.account_name" />
        <BaseInput v-if="channelForm.type === 'bank_transfer'" v-model="channelForm.account_number" label="Nomor rekening" required :error="channelErrors.account_number" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showChannelForm = false">Batal</BaseButton>
          <BaseButton :loading="savingChannel" @click="saveChannel">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
