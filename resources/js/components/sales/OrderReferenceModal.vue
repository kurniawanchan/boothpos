<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import { getReference, getReferenceImageUrl, saveReference, deleteReference } from '../../api/orders';
import { formatDateTime } from '../../utils/date';

/**
 * 054 (US8) — referensi transaksi: catatan teks dan/atau satu gambar pada sebuah penjualan, untuk
 * menelusuri penjualan ke bukti awalnya (mis. foto WhatsApp yang diterima sebelum penjualannya dicatat).
 * Satu dialog untuk melihat DAN mengubah: kolom edit hanya tampil bila SERVER menyatakan `can_edit`
 * (owner/admin atau kasir penjualannya); komponen ini tak menebak izin dari peran. Pemeriksaan jenis
 * dan ukuran berkas di sini hanya kemudahan — server memeriksa ulang isi berkasnya.
 *
 * Gambar tak bisa dimuat lewat <img src> biasa (endpoint mengotorisasi tiap permintaan), jadi diunduh
 * dengan token lalu ditampilkan lewat blob URL yang dibebaskan saat ditutup/diganti.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();

const MAX_BYTES = 5 * 1024 * 1024;
const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

const data = ref(null);
const loading = ref(false);
const loadError = ref(false);
const note = ref('');
const imageUrl = ref(null);
const pickedFile = ref(null);
const pickedPreview = ref(null);
const removeImage = ref(false);
const fileError = ref('');
const saving = ref(false);
const confirmDelete = ref(false);
const lightbox = ref(false);
const fileInput = ref(null);

function revoke(url) {
  if (url && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url);
}

function resetEdits() {
  revoke(pickedPreview.value);
  pickedFile.value = null;
  pickedPreview.value = null;
  removeImage.value = false;
  fileError.value = '';
  if (fileInput.value) fileInput.value.value = '';
}

async function load() {
  loading.value = true;
  loadError.value = false;
  revoke(imageUrl.value);
  imageUrl.value = null;
  resetEdits();
  try {
    const res = await getReference(props.orderId);
    data.value = res;
    note.value = res.note ?? '';
    if (res.has_image) imageUrl.value = await getReferenceImageUrl(props.orderId);
  } catch {
    data.value = null;
    loadError.value = true;
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.open, props.orderId],
  ([isOpen, id]) => {
    if (isOpen && id != null) load();
    if (!isOpen) {
      revoke(imageUrl.value);
      imageUrl.value = null;
      resetEdits();
    }
  },
  { immediate: true },
);
onBeforeUnmount(() => { revoke(imageUrl.value); revoke(pickedPreview.value); });

const editable = computed(() => !!data.value?.can_edit);
const hasReference = computed(() => !!(data.value && (data.value.note || data.value.has_image)));
const noteChanged = computed(() => note.value.trim() !== (data.value?.note ?? ''));
const shownImage = computed(() => pickedPreview.value ?? (removeImage.value ? null : imageUrl.value));
// Setelah disimpan harus tersisa catatan atau gambar (server menolak hasil kosong; "hapus semua" = tombol Hapus referensi).
const somethingLeft = computed(() => note.value.trim() !== '' || !!pickedFile.value || (!!data.value?.has_image && !removeImage.value));
const dirty = computed(() => noteChanged.value || !!pickedFile.value || removeImage.value);
const canSave = computed(() => editable.value && dirty.value && somethingLeft.value && !saving.value);

function onFile(e) {
  const file = e.target.files?.[0];
  fileError.value = '';
  revoke(pickedPreview.value);
  pickedPreview.value = null;
  pickedFile.value = null;
  if (!file) return;
  if (!ACCEPTED.includes(file.type)) {
    fileError.value = t('sales_reference.file_type_error');
    e.target.value = '';
    return;
  }
  if (file.size > MAX_BYTES) {
    fileError.value = t('sales_reference.file_size_error');
    e.target.value = '';
    return;
  }
  pickedFile.value = file;
  removeImage.value = false;
  if (typeof URL.createObjectURL === 'function') pickedPreview.value = URL.createObjectURL(file);
}

async function save() {
  if (!canSave.value) return;
  saving.value = true;
  try {
    const result = await saveReference(props.orderId, {
      note: noteChanged.value ? note.value : undefined,
      image: pickedFile.value,
      removeImage: removeImage.value,
    });
    emit('saved', result);
    emit('close');
  } catch {
    // 403/422 sudah ditampilkan interceptor global; dialog tetap terbuka supaya bisa diperbaiki.
  } finally {
    saving.value = false;
  }
}

async function performDelete() {
  saving.value = true;
  try {
    const result = await deleteReference(props.orderId);
    confirmDelete.value = false;
    emit('saved', result);
    emit('close');
  } catch {
    confirmDelete.value = false;
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="t('sales_reference.title')" max-width-class="max-w-[560px]" @close="emit('close')">
    <div class="flex flex-col gap-4 px-6 py-5">
      <p v-if="loading" class="text-[13px] text-muted-3">{{ t('sales_reference.loading') }}</p>

      <div v-else-if="loadError" class="flex flex-col items-start gap-2 text-[13px]">
        <span class="text-danger-text">{{ t('sales_reference.load_failed') }}</span>
        <BaseButton variant="secondary" size="sm" @click="load">{{ t('common.retry') }}</BaseButton>
      </div>

      <template v-else-if="data">
        <!-- Hanya melihat -->
        <template v-if="!editable">
          <p v-if="!hasReference" class="text-[13px] text-muted-3">{{ t('sales_reference.none') }}</p>
          <p v-if="data.note" class="whitespace-pre-line break-words text-[13.5px]" data-testid="reference-note">{{ data.note }}</p>
          <button v-if="imageUrl" type="button" class="w-fit cursor-zoom-in" @click="lightbox = true">
            <img :src="imageUrl" :alt="t('sales_reference.image_alt')" class="max-h-[320px] max-w-full rounded-lg border border-line-2 object-contain" />
          </button>
        </template>

        <!-- Mengubah -->
        <template v-else>
          <BaseTextarea v-model="note" :label="t('sales_reference.note_label')" :placeholder="t('sales_reference.note_placeholder')" rows="4" maxlength="1000" />

          <div class="flex flex-col gap-2">
            <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">{{ t('sales_reference.image_label') }}</span>
            <button v-if="shownImage" type="button" class="w-fit cursor-zoom-in" @click="lightbox = true">
              <img :src="shownImage" :alt="t('sales_reference.image_alt')" class="max-h-[240px] max-w-full rounded-lg border border-line-2 object-contain" />
            </button>
            <div class="flex flex-wrap items-center gap-2">
              <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-line px-3 py-2 text-[12.5px] font-semibold text-muted-5 hover:border-brand">
                <i class="ph-duotone ph-image text-[16px]" aria-hidden="true"></i>
                {{ shownImage ? t('sales_reference.replace_image') : t('sales_reference.choose_image') }}
                <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onFile" />
              </label>
              <BaseButton v-if="data.has_image && !pickedFile && !removeImage" variant="secondary" size="sm" @click="removeImage = true">
                {{ t('sales_reference.remove_image') }}
              </BaseButton>
              <BaseButton v-else-if="removeImage" variant="secondary" size="sm" @click="removeImage = false">{{ t('sales_reference.keep_image') }}</BaseButton>
            </div>
            <p v-if="fileError" class="text-[12px] text-danger-text" role="alert">{{ fileError }}</p>
            <p class="text-[11.5px] text-muted-3">{{ t('sales_reference.image_hint') }}</p>
          </div>
        </template>

        <p v-if="data.updated_at" class="text-[11.5px] text-muted-3">
          {{ t('sales_reference.updated', { name: data.updated_by_name ?? '—', when: formatDateTime(data.updated_at) }) }}
        </p>
      </template>
    </div>

    <template #footer>
      <div class="flex items-center justify-between gap-2.5">
        <BaseButton v-if="editable && hasReference" variant="danger" size="sm" @click="confirmDelete = true">{{ t('sales_reference.delete') }}</BaseButton>
        <span v-else></span>
        <div class="flex gap-2.5">
          <BaseButton variant="secondary" @click="emit('close')">{{ editable ? t('common.cancel') : t('common.close') }}</BaseButton>
          <BaseButton v-if="editable" :disabled="!canSave" :loading="saving" @click="save">{{ t('common.save') }}</BaseButton>
        </div>
      </div>
    </template>
  </BaseModal>

  <ConfirmDialog
    :open="confirmDelete"
    :title="t('sales_reference.delete')"
    :message="t('sales_reference.delete_confirm')"
    :confirm-label="t('common.delete')"
    :loading="saving"
    @close="confirmDelete = false"
    @confirm="performDelete"
  />
  <ImageLightbox :open="lightbox" :src="shownImage ?? imageUrl" :alt="t('sales_reference.image_alt')" @close="lightbox = false" />
</template>
