<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';
import BaseInput from '../ui/BaseInput.vue';
import BaseModal from '../ui/BaseModal.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import { listBackups, createBackup, downloadBackup, restoreBackup, restoreFromUpload, deleteBackup } from '../../api/backups';
import { useToastStore } from '../../stores/toast';
import { formatDateTime } from '../../utils/date';
import { reloadApp } from '../../utils/reloadApp';

/**
 * Cadangan & pemulihan database. Seluruh aturan penting ada di server
 * (BackupController/BackupService): owner/admin saja, kata konfirmasi
 * "RESTORE", cadangan pengaman otomatis sebelum menimpa data. Komponen ini
 * hanya membuat langkah-langkah itu terlihat dan sulit dilakukan tanpa sengaja.
 *
 * Berkas bukti pembayaran ikut DICADANGKAN tapi tidak dipulihkan di sini
 * (sama seperti perintah `app:restore`) — dijelaskan di catatan pada layar.
 */
const CONFIRM_WORD = 'RESTORE';

const { t } = useI18n();
const toast = useToastStore();

const backups = ref([]);
const loading = ref(true);
const creating = ref(false);
const downloadingId = ref(null);

async function load() {
  loading.value = true;
  try {
    backups.value = (await listBackups()).data ?? [];
  } catch (err) {
    toast.error(err.message);
  } finally {
    loading.value = false;
  }
}
onMounted(load);

function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

async function create() {
  creating.value = true;
  try {
    await createBackup();
    toast.success(t('backup.created'));
    await load();
  } catch (err) {
    toast.error(err.message || t('backup.create_failed'));
  } finally {
    creating.value = false;
  }
}

async function download(backup) {
  downloadingId.value = backup.id;
  try {
    const blob = await downloadBackup(backup.id);
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `boothpos-backup-${backup.id}.sql`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (err) {
    toast.error(err.message || t('backup.download_failed'));
  } finally {
    downloadingId.value = null;
  }
}

// --- Hapus ------------------------------------------------------------------
// Hanya menghapus salinan LOKAL; konfirmasi biasa cukup (beda dari pemulihan,
// yang menimpa seluruh data dan minta kata konfirmasi).
const deleteTarget = ref(null);
const deleting = ref(false);

async function performDelete() {
  if (!deleteTarget.value) return;
  deleting.value = true;
  try {
    await deleteBackup(deleteTarget.value.id);
    toast.success(t('backup.deleted'));
    deleteTarget.value = null;
    await load();
  } catch (err) {
    toast.error(err.message || t('backup.delete_failed'));
    deleteTarget.value = null;
  } finally {
    deleting.value = false;
  }
}

// --- Pemulihan --------------------------------------------------------------
const chosenFile = ref(null);
function onFileChosen(event) {
  chosenFile.value = event.target.files?.[0] ?? null;
}

// Sumber yang akan dipulihkan: { kind: 'backup', id } atau { kind: 'file', file }.
const target = ref(null);
const confirmText = ref('');
const restoring = ref(false);
const restoreError = ref('');

const dialogOpen = computed(() => target.value !== null);
const confirmed = computed(() => confirmText.value === CONFIRM_WORD);
const targetLabel = computed(() => {
  if (!target.value) return '';

  return target.value.kind === 'backup'
    ? t('backup.restore_source_backup', { id: target.value.id })
    : t('backup.restore_source_file', { name: target.value.file.name });
});

function askRestore(newTarget) {
  target.value = newTarget;
  confirmText.value = '';
  restoreError.value = '';
}
function closeDialog() {
  if (restoring.value) return; // jangan ditutup di tengah proses
  target.value = null;
}

async function performRestore() {
  if (!confirmed.value || !target.value) return;
  restoring.value = true;
  restoreError.value = '';
  try {
    const result = target.value.kind === 'backup'
      ? await restoreBackup(target.value.id)
      : await restoreFromUpload(target.value.file);
    toast.success(t('backup.restore_done', { id: result?.safety_backup?.id ?? '—' }));
    target.value = null;
    reloadApp();
  } catch (err) {
    restoreError.value = err?.errors?.file?.[0] || err?.message || t('backup.restore_failed');
  } finally {
    restoring.value = false;
  }
}
</script>

<template>
  <section class="flex flex-col gap-4 rounded-card border border-line-2 bg-white p-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex flex-col gap-1">
        <span class="text-[15px] font-bold tracking-tight">{{ t('backup.title') }}</span>
        <p class="max-w-[640px] text-[12px] leading-relaxed text-muted-3">{{ t('backup.description') }}</p>
      </div>
      <BaseButton size="sm" :loading="creating" @click="create">{{ t('backup.create') }}</BaseButton>
    </div>

    <p v-if="loading" class="text-[12.5px] text-muted-3">{{ t('backup.loading') }}</p>
    <p v-else-if="backups.length === 0" class="text-[12.5px] text-muted-3">{{ t('backup.empty') }}</p>
    <ul v-else class="flex flex-col divide-y divide-line-6 rounded-lg border border-line-2">
      <li v-for="backup in backups" :key="backup.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
        <div class="flex min-w-0 flex-col gap-0.5">
          <span class="font-mono text-[12.5px] font-semibold">{{ backup.id }}</span>
          <span class="text-[11.5px] text-muted-3">
            {{ formatDateTime(backup.created_at) }} ·
            <span>{{ formatBytes(backup.size_bytes) }}</span>
            <template v-if="backup.has_payment_proofs"> · <span>{{ t('backup.has_proofs') }}</span></template>
          </span>
        </div>
        <div class="flex items-center gap-2">
          <BaseButton variant="secondary" size="sm" :loading="downloadingId === backup.id" @click="download(backup)">{{ t('backup.download') }}</BaseButton>
          <BaseButton variant="danger" size="sm" @click="askRestore({ kind: 'backup', id: backup.id })">{{ t('backup.restore') }}</BaseButton>
          <BaseButton variant="secondary" size="sm" @click="deleteTarget = backup">{{ t('common.delete') }}</BaseButton>
        </div>
      </li>
    </ul>

    <div class="flex flex-col gap-2 border-t border-dashed border-line-2 pt-4">
      <span class="text-[13.5px] font-bold">{{ t('backup.upload_title') }}</span>
      <p class="text-[12px] text-muted-3">{{ t('backup.upload_hint') }}</p>
      <div class="flex flex-wrap items-center gap-3">
        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-line bg-white px-3.5 py-2 text-[12.5px] font-bold text-muted-5 hover:border-brand">
          <span>{{ t('backup.upload_choose') }}</span>
          <input type="file" accept=".sql" class="sr-only" @change="onFileChosen" />
        </label>
        <span v-if="chosenFile" class="font-mono text-[12px] text-muted-4">{{ chosenFile.name }}</span>
        <BaseButton variant="danger" size="sm" :disabled="!chosenFile" @click="askRestore({ kind: 'file', file: chosenFile })">{{ t('backup.upload_restore') }}</BaseButton>
      </div>
    </div>

    <ul class="flex flex-col gap-1 text-[11.5px] leading-relaxed text-muted-3">
      <li>{{ t('backup.proofs_note') }}</li>
      <li>{{ t('backup.session_note') }}</li>
    </ul>

    <ConfirmDialog
      :open="deleteTarget !== null"
      :title="t('backup.delete_title')"
      :message="deleteTarget ? t('backup.delete_message', { id: deleteTarget.id }) : ''"
      :confirm-label="t('common.delete')"
      :loading="deleting"
      @close="deleteTarget = null"
      @confirm="performDelete"
    />

    <BaseModal :open="dialogOpen" :title="t('backup.restore_title')" max-width-class="max-w-[460px]" @close="closeDialog">
      <div class="flex flex-col gap-3.5 px-6 py-5">
        <p class="text-[13px] leading-relaxed">
          <strong>{{ t('backup.restore_warning_overwrite') }}</strong>
          {{ t('backup.restore_warning_safety') }}
        </p>
        <!-- Pulihan mengganti SELURUH database, termasuk license_activations yang
             terikat ke sidik jari mesin ini (018). Keputusan product owner (026):
             semua data tetap ikut dipulihkan apa adanya, lisensi diaktivasi ulang —
             jadi cukup diperingatkan di sini, bukan diam-diam dipertahankan. -->
        <p data-testid="restore-license-warning" class="rounded-lg border border-warn-border bg-warn-bg px-3 py-2 text-[12.5px] leading-relaxed text-warn-text">
          {{ t('backup.restore_warning_license') }}
        </p>
        <p class="rounded-lg bg-line-7 px-3 py-2 font-mono text-[12px]">{{ targetLabel }}</p>
        <BaseInput v-model="confirmText" :label="t('backup.restore_type_word', { word: CONFIRM_WORD })" autocomplete="off" data-autofocus />
        <p v-if="restoreError" role="alert" class="rounded-lg bg-danger-bg px-3 py-2 text-[12.5px] font-semibold text-danger-text">{{ restoreError }}</p>
      </div>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" :disabled="restoring" @click="closeDialog">{{ t('common.cancel') }}</BaseButton>
          <BaseButton variant="danger" :disabled="!confirmed" :loading="restoring" @click="performRestore">{{ t('backup.restore_confirm') }}</BaseButton>
        </div>
      </template>
    </BaseModal>
  </section>
</template>
