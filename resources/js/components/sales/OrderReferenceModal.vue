<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseTextarea from '../ui/BaseTextarea.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import ReferenceThumbnails from './ReferenceThumbnails.vue';
import { getReference, saveReference, deleteReference } from '../../api/orders';
import { formatDateTime } from '../../utils/date';
import { triageReferenceFiles, REFERENCE_IMAGE_TYPES } from '../../utils/referenceFiles';
import { useAuthStore } from '../../stores/auth';

/**
 * 054 (US8) — referensi transaksi: catatan teks dan/atau gambar pada sebuah penjualan, untuk
 * menelusuri penjualan ke bukti awalnya (mis. foto WhatsApp yang diterima sebelum penjualannya dicatat).
 * Satu dialog untuk melihat DAN mengubah: kolom edit hanya tampil bila SERVER menyatakan `can_edit`
 * (owner/admin atau kasir penjualannya); komponen ini tak menebak izin dari peran. Pemeriksaan jenis
 * dan ukuran berkas di sini hanya kemudahan — server memeriksa ulang isi berkasnya.
 *
 * 055 — sampai `max_images` gambar per penjualan. Pengguna memilih BANYAK berkas sekaligus (yang tidak
 * valid disebut namanya, yang valid tetap ikut) dan menandai gambar tersimpan untuk dihapus (bisa
 * dibatalkan sebelum Simpan). Simpan mengirim SATU permintaan berisi DELTA (gambar baru + id yang dihapus),
 * tidak pernah daftar lengkap, supaya simpanan pengguna lain tidak tertimpa. Bila hasilnya kosong sama
 * sekali (tanpa catatan dan tanpa gambar), Simpan diarahkan ke konfirmasi hapus referensi.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();

const data = ref(null);
const loading = ref(false);
const loadError = ref(false);
const note = ref('');
const picked = ref([]); // [{ file, url }]
const marked = ref([]); // id gambar tersimpan yang akan dihapus
const refused = ref([]); // [{ name, reason }]
const serverError = ref('');
const saving = ref(false);
const confirmDelete = ref(false);
const fileInput = ref(null);

function revoke(url) {
  if (url && typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(url);
}

function resetEdits() {
  picked.value.forEach((p) => revoke(p.url));
  picked.value = [];
  marked.value = [];
  refused.value = [];
  serverError.value = '';
  if (fileInput.value) fileInput.value.value = '';
}

async function load({ keepEdits = false } = {}) {
  if (!keepEdits) {
    loading.value = true;
    resetEdits();
  }
  loadError.value = false;
  try {
    const res = await getReference(props.orderId);
    data.value = res;
    if (!keepEdits) note.value = res.note ?? '';
    // Gambar yang sudah tak ada lagi di server tak bisa ditandai untuk dihapus.
    const ids = new Set((res.images ?? []).map((i) => i.id));
    marked.value = marked.value.filter((id) => ids.has(id));
  } catch {
    if (!keepEdits) data.value = null;
    loadError.value = !keepEdits;
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.open, props.orderId],
  ([isOpen, id]) => {
    if (isOpen && id != null) load();
    if (!isOpen) resetEdits();
  },
  { immediate: true },
);
onBeforeUnmount(() => picked.value.forEach((p) => revoke(p.url)));

// 057 — `can_edit` dari server DAN aksi `reference` peran penampil (Sales = View saja ⇒ hanya melihat).
const auth = useAuthStore();
const editable = computed(() => !!data.value?.can_edit && auth.canDo('sales', 'reference'));
const storedImages = computed(() => data.value?.images ?? []);
const maxImages = computed(() => data.value?.max_images ?? 10);
const hasReference = computed(() => !!(data.value && (data.value.note || storedImages.value.length)));
const noteChanged = computed(() => note.value.trim() !== (data.value?.note ?? ''));
const imagesAfterSave = computed(() => storedImages.value.length - marked.value.length + picked.value.length);
const slotsLeft = computed(() => Math.max(0, maxImages.value - imagesAfterSave.value));
const dirty = computed(() => noteChanged.value || picked.value.length > 0 || marked.value.length > 0);
// Setelah disimpan harus tersisa catatan atau gambar; bila tidak, Simpan = hapus referensi (dengan konfirmasi).
const emptiesReference = computed(() => note.value.trim() === '' && imagesAfterSave.value === 0);
const canSave = computed(() => editable.value && dirty.value && !saving.value && (!emptiesReference.value || hasReference.value));

const refusedMessage = computed(() => {
  if (!refused.value.length) return '';
  const reasons = {
    type: t('sales_reference.reason_type'),
    size: t('sales_reference.reason_size'),
    limit: t('sales_reference.reason_limit', { max: maxImages.value }),
  };
  const names = refused.value.map((r) => `${r.name} (${reasons[r.reason]})`).join(', ');
  return t('sales_reference.file_refused', { names });
});

function onFiles(e) {
  const files = Array.from(e.target.files ?? []);
  e.target.value = ''; // supaya berkas yang sama bisa dipilih lagi setelah dibatalkan
  serverError.value = '';
  const { accepted, refused: rejected } = triageReferenceFiles(files, slotsLeft.value);
  refused.value = rejected;
  picked.value = [
    ...picked.value,
    ...accepted.map((file) => ({ file, url: typeof URL.createObjectURL === 'function' ? URL.createObjectURL(file) : null })),
  ];
}

function dropPicked(index) {
  revoke(picked.value[index]?.url);
  picked.value = picked.value.filter((_, i) => i !== index);
  refused.value = [];
}

function toggleRemove(id) {
  marked.value = marked.value.includes(id) ? marked.value.filter((m) => m !== id) : [...marked.value, id];
}

async function save() {
  if (!canSave.value) return;
  if (emptiesReference.value) {
    confirmDelete.value = true;
    return;
  }
  saving.value = true;
  serverError.value = '';
  try {
    const result = await saveReference(props.orderId, {
      note: noteChanged.value ? note.value : undefined,
      images: picked.value.map((p) => p.file),
      removeImageIds: [...marked.value],
    });
    emit('saved', result);
    emit('close');
  } catch (err) {
    if (err?.status === 409) {
      // Pesan batas sudah ditampilkan interceptor global; muat ulang jumlah tersimpan supaya sisa slot benar.
      await load({ keepEdits: true });
    } else if (err?.status === 422) {
      serverError.value = Object.values(err.errors ?? {}).flat()[0] ?? err.message ?? '';
    }
    // 403 juga sudah ditampilkan interceptor global; dialog tetap terbuka supaya bisa diperbaiki.
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
        <BaseButton variant="secondary" size="sm" @click="load()">{{ t('common.retry') }}</BaseButton>
      </div>

      <template v-else-if="data">
        <!-- Hanya melihat -->
        <template v-if="!editable">
          <p v-if="!hasReference" class="text-[13px] text-muted-3">{{ t('sales_reference.none') }}</p>
          <p v-if="data.note" class="whitespace-pre-line break-words text-[13.5px]" data-testid="reference-note">{{ data.note }}</p>
          <ReferenceThumbnails v-if="storedImages.length" :order-id="orderId" :images="storedImages" />
        </template>

        <!-- Mengubah -->
        <template v-else>
          <BaseTextarea v-model="note" :label="t('sales_reference.note_label')" :placeholder="t('sales_reference.note_placeholder')" rows="4" maxlength="1000" />

          <div class="flex flex-col gap-2">
            <span class="text-[12px] font-bold uppercase tracking-wider text-muted-3">{{ t('sales_reference.images_title') }}</span>

            <ReferenceThumbnails
              v-if="storedImages.length"
              :order-id="orderId"
              :images="storedImages"
              removable
              :marked="marked"
              @toggle-remove="toggleRemove"
            />

            <ul v-if="picked.length" class="flex flex-wrap gap-2">
              <li v-for="(p, i) in picked" :key="p.url ?? i" data-testid="picked-image" class="flex w-[88px] flex-col gap-1">
                <div class="relative h-[88px] w-[88px] overflow-hidden rounded-lg border border-brand bg-surface-subtle">
                  <img v-if="p.url" :src="p.url" :alt="p.file.name" class="h-full w-full object-cover" />
                  <span class="absolute left-1 top-1 rounded bg-brand px-1.5 py-0.5 text-[10px] font-bold text-white">{{ t('sales_reference.new_badge') }}</span>
                </div>
                <button type="button" class="text-left text-[11px] font-semibold text-danger-text hover:underline" @click="dropPicked(i)">
                  {{ t('sales_reference.remove_from_selection') }}
                </button>
              </li>
            </ul>

            <div class="flex flex-wrap items-center gap-2">
              <label
                class="inline-flex items-center gap-1.5 rounded-lg border border-line px-3 py-2 text-[12.5px] font-semibold text-muted-5"
                :class="slotsLeft > 0 ? 'cursor-pointer hover:border-brand' : 'cursor-not-allowed opacity-50'"
              >
                <i class="ph-duotone ph-images text-[16px]" aria-hidden="true"></i>
                {{ t('sales_reference.add_images') }}
                <input
                  ref="fileInput"
                  type="file"
                  multiple
                  :accept="REFERENCE_IMAGE_TYPES.join(',')"
                  class="sr-only"
                  :disabled="slotsLeft === 0"
                  @change="onFiles"
                />
              </label>
              <span class="text-[12px] text-muted-4">
                {{ slotsLeft > 0 ? t('sales_reference.remaining_slots', { count: slotsLeft }) : t('sales_reference.limit_reached', { max: maxImages }) }}
              </span>
            </div>
            <p v-if="refusedMessage || serverError" class="text-[12px] text-danger-text" role="alert">{{ serverError || refusedMessage }}</p>
            <p class="text-[11.5px] text-muted-3">{{ t('sales_reference.images_hint', { max: maxImages }) }}</p>
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
</template>
