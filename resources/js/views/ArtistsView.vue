<script setup>
import { reactive, ref, onMounted } from 'vue';
import { usePaginatedList } from '../composables/usePaginatedList';
import { listArtists, createArtist, updateArtist, deleteArtist } from '../api/artists';
import { useSettingsStore } from '../stores/settings';
import { useToastStore } from '../stores/toast';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import DataTable from '../components/ui/DataTable.vue';
import TablePagination from '../components/ui/TablePagination.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';
import BaseTextarea from '../components/ui/BaseTextarea.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';

const settings = useSettingsStore();
const toast = useToastStore();

const { items, meta, params, loading, load, setPage, setFilter } = usePaginatedList(listArtists);
const search = ref('');
const debouncedSearch = useDebouncedFn(() => setFilter({ search: search.value || undefined }), 300);

onMounted(async () => {
  await settings.load();
  await load();
});

const columns = [
  { key: 'code', label: 'Kode' },
  { key: 'name', label: 'Nama' },
  { key: 'contact', label: 'Kontak' },
  { key: 'product_count', label: 'Produk' },
  { key: 'is_active', label: 'Status' },
  { key: 'actions', label: '' },
];

const showForm = ref(false);
const editingArtist = ref(null);
const form = reactive({ code: '', name: '', contact_phone: '', contact_email: '', notes: '', is_active: true });
const formErrors = reactive({});
const saving = ref(false);

const showDelete = ref(false);
const deleteTarget = ref(null);
const deleting = ref(false);

const canCreate = () => settings.multiArtistEnabled || !settings.artistLimitReached;

function openCreate() {
  editingArtist.value = null;
  Object.assign(form, { code: '', name: '', contact_phone: '', contact_email: '', notes: '', is_active: true });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

function openEdit(artist) {
  editingArtist.value = artist;
  Object.assign(form, {
    code: artist.code,
    name: artist.name,
    contact_phone: artist.contact_phone ?? '',
    contact_email: artist.contact_email ?? '',
    notes: artist.notes ?? '',
    is_active: artist.is_active,
  });
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  showForm.value = true;
}

async function saveArtist() {
  saving.value = true;
  Object.keys(formErrors).forEach((k) => delete formErrors[k]);
  const payload = {
    code: form.code.toUpperCase(),
    name: form.name,
    contact_phone: form.contact_phone || null,
    contact_email: form.contact_email || null,
    notes: form.notes || null,
    is_active: form.is_active,
  };
  try {
    if (editingArtist.value) {
      await updateArtist(editingArtist.value.id, payload);
      toast.success('Artist diperbarui.');
    } else {
      await createArtist(payload);
      toast.success('Artist dibuat.');
      await settings.load();
    }
    showForm.value = false;
    await load();
  } catch (err) {
    if (err.isValidation) Object.assign(formErrors, Object.fromEntries(Object.entries(err.errors).map(([k, v]) => [k, v[0]])));
    // 403 kuota lisensi Pro sudah ditoast oleh interceptor bersama.
  } finally {
    saving.value = false;
  }
}

function confirmDelete(artist) {
  deleteTarget.value = artist;
  showDelete.value = true;
}

async function performDelete() {
  deleting.value = true;
  try {
    await deleteArtist(deleteTarget.value.id);
    toast.success('Artist dinonaktifkan.');
    showDelete.value = false;
    await Promise.all([load(), settings.load()]);
  } catch {
    // 409 (masih ada produk aktif) sudah ditoast oleh interceptor bersama.
  } finally {
    deleting.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-3.5 px-[26px] pb-10 pt-5">
    <div class="flex flex-wrap items-center gap-2.5">
      <div class="relative flex min-w-[230px] flex-1 items-center">
        <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[16px] text-muted-3" aria-hidden="true"></i>
        <label class="sr-only" for="artist-search">Cari artist</label>
        <input
          id="artist-search"
          v-model="search"
          placeholder="Cari nama artist…"
          class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          @input="debouncedSearch"
        />
      </div>
      <BaseButton :disabled="!canCreate()" @click="openCreate">
        <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
        Tambah artist
      </BaseButton>
    </div>

    <div v-if="!settings.multiArtistEnabled && settings.artistLimitReached" class="flex items-start gap-3 rounded-card border border-warn-border bg-warn-bg px-4 py-3.5">
      <i class="ph-duotone ph-lock-key text-[19px] text-warn-text" aria-hidden="true"></i>
      <div class="flex flex-1 flex-col gap-0.5">
        <span class="text-[13px] font-bold text-warn-text">Lisensi Pro — satu artist</span>
        <span class="text-[12.5px] leading-relaxed text-warn-text opacity-90">
          Tombol tambah artist dinonaktifkan karena kuota tercapai. Server tetap menolak
          <span class="font-mono text-[11.5px]">POST /artists</span> dengan 403 walau UI dipaksa.
        </span>
      </div>
      <RouterLink :to="{ name: 'settings' }" class="flex-none rounded-md border border-warn-border-strong bg-white px-3 py-1.5 text-[12.5px] font-bold text-warn-text hover:bg-surface-subtle">
        Upgrade ke Master
      </RouterLink>
    </div>

    <div class="overflow-hidden rounded-card border border-line-2 bg-white">
      <DataTable :columns="columns" :rows="items" :loading="loading" empty-message="Belum ada artist.">
        <template #cell-code="{ row }"><span class="font-mono text-[12.5px] font-bold text-brand-active">{{ row.code }}</span></template>
        <template #cell-contact="{ row }">
          <div class="flex flex-col gap-0.5 text-[12.5px] text-muted-4">
            <span>{{ row.contact_phone || '—' }}</span>
            <span class="text-muted-3">{{ row.contact_email || '' }}</span>
          </div>
        </template>
        <template #cell-is_active="{ row }">
          <StatusPill :variant="row.is_active ? 'mint' : 'neutral'">{{ row.is_active ? 'Aktif' : 'Nonaktif' }}</StatusPill>
        </template>
        <template #cell-actions="{ row }">
          <div class="flex justify-end gap-2">
            <button type="button" class="text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" @click="openEdit(row)">Edit</button>
            <button type="button" class="text-[12.5px] font-semibold text-danger-text hover:text-danger-text" @click="confirmDelete(row)">Hapus</button>
          </div>
        </template>
      </DataTable>
      <TablePagination :meta="meta" @change="setPage" />
    </div>

    <BaseModal :open="showForm" :title="editingArtist ? 'Ubah artist' : 'Artist baru'" max-width-class="max-w-[480px]" @close="showForm = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="saveArtist">
        <BaseInput
          v-model="form.code"
          label="Kode (3 huruf)"
          maxlength="3"
          required
          :disabled="!!editingArtist"
          hint="Permanen setelah dibuat — jadi bagian dari SKU."
          :error="formErrors.code"
        />
        <BaseInput v-model="form.name" label="Nama artist" required maxlength="100" :error="formErrors.name" />
        <BaseInput v-model="form.contact_phone" label="Telepon" :error="formErrors.contact_phone" />
        <BaseInput v-model="form.contact_email" label="Email" type="email" :error="formErrors.contact_email" />
        <BaseTextarea v-model="form.notes" label="Catatan" :rows="2" :error="formErrors.notes" />
        <label class="flex items-center gap-2.5 text-[13px] font-semibold text-muted-4">
          <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line accent-brand" />
          Aktif
        </label>
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showForm = false">Batal</BaseButton>
          <BaseButton :loading="saving" @click="saveArtist">Simpan</BaseButton>
        </div>
      </template>
    </BaseModal>

    <ConfirmDialog
      :open="showDelete"
      title="Nonaktifkan artist"
      :message="`Nonaktifkan ${deleteTarget?.name}? Ditolak bila masih ada produk aktif miliknya.`"
      confirm-label="Ya, nonaktifkan"
      :loading="deleting"
      @close="showDelete = false"
      @confirm="performDelete"
    />
  </div>
</template>
