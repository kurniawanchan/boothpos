<script setup>
import { ref, computed } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import { importMasterData, downloadImportTemplate } from '../../api/masterData';
import { useToastStore } from '../../stores/toast';

/**
 * One shared import flow reused on /products, /artists, /categories, and
 * /stock (PRD 7.15) — a single .xlsx carries all four sheets, so the modal
 * itself has no notion of "which screen opened it"; every screen just
 * listens for `imported` and refreshes its own list.
 *
 * Preview-then-apply by design: `dry_run` runs the exact same validation
 * path as a real import, so what the user sees in the preview is what
 * will happen — for a bulk operation that can rewrite all master data,
 * that's the difference between "safe" and "scary".
 */
defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'imported']);

const toast = useToastStore();

// Mirrors ImportMasterDataRequest::MAX_KILOBYTES server-side — checked
// client-side too so an oversized file fails fast instead of waiting on a
// round trip just to be told the same thing.
const MAX_BYTES = 10 * 1024 * 1024;

const SHEET_LABEL = {
  artists: 'Artist',
  categories: 'Kategori',
  products: 'Produk',
  stock: 'Stok',
  vendors: 'Vendor',
  materials: 'Bahan Baku',
  vendor_prices: 'Harga Vendor',
  bom: 'BOM',
  roles: 'Peran',
  users: 'Pengguna',
};

const fileInputEl = ref(null);
const imagesInputEl = ref(null);
const selectedFile = ref(null);
const selectedImages = ref([]); // batch of files matched by image_filename in the sheet
const clientError = ref(''); // client-side file validation, before any request
const simpleError = ref(''); // 422 shapes (a) missing/wrong-type file, (b) defensive rejection — message only
const result = ref(null); // parsed body of a dry-run preview OR a successful apply
const rejected = ref(null); // parsed body of a 422 row-level rejection — applied:false, errors:[...]
const downloadingTemplate = ref(false);
const previewing = ref(false);
const applying = ref(false);

function resetState() {
  selectedFile.value = null;
  selectedImages.value = [];
  clientError.value = '';
  simpleError.value = '';
  result.value = null;
  rejected.value = null;
  if (fileInputEl.value) fileInputEl.value.value = '';
  if (imagesInputEl.value) imagesInputEl.value.value = '';
}

function close() {
  resetState();
  emit('close');
}

async function downloadTemplate() {
  downloadingTemplate.value = true;
  try {
    await downloadImportTemplate();
  } catch {
    toast.error('Gagal mengunduh template impor.');
  } finally {
    downloadingTemplate.value = false;
  }
}

function onFileChange(e) {
  const file = e.target.files?.[0] ?? null;
  clientError.value = '';
  simpleError.value = '';
  result.value = null;
  rejected.value = null;
  selectedFile.value = null;
  if (!file) return;

  if (!file.name.toLowerCase().endsWith('.xlsx')) {
    clientError.value = 'Berkas impor harus berformat .xlsx.';
    e.target.value = '';
    return;
  }
  if (file.size > MAX_BYTES) {
    clientError.value = 'Ukuran berkas impor maksimal 10 MB.';
    e.target.value = '';
    return;
  }
  selectedFile.value = file;
}

function onImagesChange(e) {
  selectedImages.value = Array.from(e.target.files ?? []);
}

async function runImport(dryRun) {
  if (!selectedFile.value) return;
  simpleError.value = '';
  result.value = null;
  rejected.value = null;
  if (dryRun) previewing.value = true;
  else applying.value = true;

  try {
    const data = await importMasterData(selectedFile.value, { dryRun, images: selectedImages.value });
    result.value = data;
    if (!dryRun) {
      toast.success('Impor berhasil.');
      emit('imported');
    }
  } catch (err) {
    if (err.status === 422 && Array.isArray(err.errors) && err.errors.length) {
      // Shape (c): full envelope, row-level, all-or-nothing — nothing written.
      rejected.value = err.data;
    } else if (err.status === 422) {
      // Shape (a) field validation (missing/oversized/wrong-extension file)
      // or shape (b) defensive rejection — message only, no row detail.
      simpleError.value = err.errors?.file?.[0] ?? err.message;
    }
    // 403 is already toasted by the shared axios interceptor — this
    // control is hidden for anyone without master-data menu access anyway.
  } finally {
    previewing.value = false;
    applying.value = false;
  }
}

const canPreview = computed(() => !!selectedFile.value && !previewing.value);
const canApply = computed(() => !!result.value && result.value.dry_run && !result.value.applied);
const isDone = computed(() => !!result.value?.applied);
</script>

<template>
  <BaseModal :open="open" title="Impor data master" max-width-class="max-w-[640px]" @close="close">
    <div class="flex flex-col gap-4 px-6 py-5">
      <div class="flex items-start gap-3 rounded-lg border border-mint-border bg-mint-50 px-3.5 py-3.5">
        <i class="ph-duotone ph-file-arrow-down text-[19px] text-brand-active" aria-hidden="true"></i>
        <div class="flex flex-1 flex-col gap-1">
          <span class="text-[13px] font-bold text-brand-active">Belum punya berkasnya?</span>
          <span class="text-[12px] leading-relaxed text-muted-4">
            Template berisi 10 sheet (artist, kategori, produk, stok, vendor, bahan baku, harga vendor, BOM, peran, pengguna) lengkap dengan
            judul kolom dan contoh baris — sheet produk dan kategori kini juga memuat contoh kolom
            <span class="font-semibold">image_filename</span>.
            Berkas hasil <span class="font-semibold">ekspor</span> dari layar mana pun juga memakai kolom yang sama —
            bisa langsung disunting dan diunggah balik ke sini.
          </span>
          <button
            type="button"
            class="mt-1 self-start text-[12.5px] font-bold text-brand-active underline decoration-dotted disabled:opacity-50"
            :disabled="downloadingTemplate"
            @click="downloadTemplate"
          >
            {{ downloadingTemplate ? 'Mengunduh…' : 'Unduh template impor' }}
          </button>
        </div>
      </div>

      <div class="flex flex-col gap-1.5">
        <label class="text-[12.5px] font-semibold text-muted-4" for="master-data-import-file">Berkas .xlsx (maks 10 MB)</label>
        <input
          id="master-data-import-file"
          ref="fileInputEl"
          type="file"
          accept=".xlsx"
          class="rounded-lg border border-line bg-white px-3.5 py-2.5 text-[13px] file:mr-3 file:rounded-md file:border-0 file:bg-mint-100 file:px-3 file:py-1.5 file:text-[12.5px] file:font-bold file:text-brand-active"
          @change="onFileChange"
        />
        <p v-if="clientError" class="text-[12px] font-semibold text-danger-text">{{ clientError }}</p>
        <p v-else-if="simpleError" class="text-[12px] font-semibold text-danger-text">{{ simpleError }}</p>
        <p v-else class="text-[11.5px] text-muted-3">Sheet yang tidak dikenali di dalam berkas akan dilewati, bukan menggagalkan impor.</p>
      </div>

      <div class="flex flex-col gap-1.5">
        <label class="text-[12.5px] font-semibold text-muted-4" for="master-data-import-images">
          Gambar produk/kategori (opsional)
        </label>
        <input
          id="master-data-import-images"
          ref="imagesInputEl"
          type="file"
          accept="image/*"
          multiple
          class="rounded-lg border border-line bg-white px-3.5 py-2.5 text-[13px] file:mr-3 file:rounded-md file:border-0 file:bg-mint-100 file:px-3 file:py-1.5 file:text-[12.5px] file:font-bold file:text-brand-active"
          @change="onImagesChange"
        />
        <p class="text-[11.5px] text-muted-3">
          Nama berkas harus cocok dengan kolom <span class="font-mono">image_filename</span> di sheet produk/kategori.
          <template v-if="selectedImages.length">{{ selectedImages.length }} berkas dipilih.</template>
        </p>
      </div>

      <div v-if="rejected" class="flex flex-col gap-3 rounded-lg border border-danger-border bg-danger-bg px-4 py-3.5">
        <div class="flex items-center gap-2">
          <i class="ph-duotone ph-x-circle text-[18px] text-danger-text" aria-hidden="true"></i>
          <span class="text-[13px] font-bold text-danger-text">{{ rejected.message }}</span>
        </div>
        <p class="text-[12px] font-semibold text-danger-text">Tidak ada data yang diubah — perbaiki baris di bawah lalu unggah ulang.</p>
        <div class="max-h-[220px] overflow-auto rounded-md border border-danger-border-hover bg-white">
          <table class="w-full border-collapse text-[12px]">
            <thead>
              <tr class="bg-surface-subtle text-left">
                <th class="px-3 py-2 font-bold text-muted-2">Sheet</th>
                <th class="px-3 py-2 font-bold text-muted-2">Baris</th>
                <th class="px-3 py-2 font-bold text-muted-2">Kolom</th>
                <th class="px-3 py-2 font-bold text-muted-2">Masalah</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(e, i) in rejected.errors" :key="i" class="border-t border-line-5 align-top">
                <td class="px-3 py-2">{{ e.sheet ?? '—' }}</td>
                <td class="px-3 py-2 font-mono">{{ e.row ?? '—' }}</td>
                <td class="px-3 py-2 font-mono">{{ e.column ?? '—' }}</td>
                <td class="px-3 py-2">{{ e.message }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="result" class="flex flex-col gap-3 rounded-lg border px-4 py-3.5" :class="result.applied ? 'border-mint-border bg-mint-50' : 'border-line-2 bg-surface-subtle'">
        <div class="flex items-center gap-2">
          <i class="ph-duotone text-[18px]" :class="result.applied ? 'ph-check-circle text-brand-active' : 'ph-eye text-muted-4'" aria-hidden="true"></i>
          <span class="text-[13px] font-bold" :class="result.applied ? 'text-brand-active' : 'text-muted-5'">
            {{ result.applied ? 'Impor diterapkan — data sudah diperbarui.' : 'Pratinjau — belum ada data yang diubah.' }}
          </span>
        </div>
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
          <div v-for="(s, sheet) in result.sheets" :key="sheet" class="flex flex-col gap-1 rounded-md border border-line-3 bg-white px-3 py-2.5">
            <span class="text-[11px] font-bold uppercase tracking-wide text-muted-3">{{ SHEET_LABEL[sheet] ?? sheet }}</span>
            <span class="text-[12px] text-muted-4">{{ s.rows }} baris</span>
            <span class="text-[11.5px] text-brand-active">+{{ s.created }} baru</span>
            <span class="text-[11.5px] text-muted-4">{{ s.updated }} diperbarui · {{ s.unchanged }} sama</span>
          </div>
        </div>
        <p v-if="result.ignored_sheets?.length" class="text-[11.5px] text-warn-text">
          Sheet diabaikan (nama tidak dikenali): {{ result.ignored_sheets.join(', ') }}
        </p>
      </div>
    </div>

    <template #footer>
      <div class="flex items-center justify-between gap-2.5">
        <span class="text-[11.5px] text-muted-3">Digerbang owner/admin/inventory.</span>
        <div class="flex gap-2.5">
          <BaseButton variant="secondary" @click="close">Tutup</BaseButton>
          <BaseButton v-if="isDone" variant="secondary" @click="resetState">Impor berkas lain</BaseButton>
          <BaseButton v-else-if="canApply" :loading="applying" @click="runImport(false)">Terapkan impor</BaseButton>
          <BaseButton v-else :disabled="!canPreview" :loading="previewing" @click="runImport(true)">Pratinjau</BaseButton>
        </div>
      </div>
    </template>
  </BaseModal>
</template>
