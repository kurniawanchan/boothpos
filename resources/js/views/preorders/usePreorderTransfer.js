import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { exportPreorders, downloadPreorderImportTemplate, importPreorders } from '../../api/preorders';
import { useToastStore } from '../../stores/toast';

/**
 * Ekspor/impor Excel pre-order (007-preorder-import-export-notify US3), owner/admin saja (server menegakkan).
 * Dipindahkan apa adanya dari PreordersView.vue (041).
 *
 * @param {object} deps
 * @param {object} deps.params   params reaktif usePaginatedList — satu-satunya sumber "filter yang aktif"
 * @param {() => Promise<void>} deps.reload memuat ulang daftar setelah impor
 */
export function usePreorderTransfer({ params, reload }) {
  const toast = useToastStore();
  const { t } = useI18n();

  const exporting = ref(false);
  const importFileInput = ref(null);
  const importing = ref(false);

  function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  async function doExportPreorders() {
    exporting.value = true;
    try {
      // 007-preorder-import-export-notify (US3, Acceptance Scenario 1) —
      // ekspor menghormati filter yang sedang aktif, dibaca langsung dari
      // params reaktif usePaginatedList (satu-satunya sumber "apa yang
      // sedang aktif" saat ini, bukan disalin ke ref terpisah).
      saveBlob(await exportPreorders({ ...params }), 'preorders.xlsx');
    } catch (err) {
      toast.error(err.message || t('preorders.export_failed'));
    } finally {
      exporting.value = false;
    }
  }

  async function doDownloadImportTemplate() {
    saveBlob(await downloadPreorderImportTemplate(), 'template-preorders.xlsx');
  }

  function triggerImportFile() {
    importFileInput.value?.click();
  }

  async function onImportFileSelected(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    importing.value = true;
    try {
      const result = await importPreorders(file);
      toast.success(t('preorders.import_success', { count: result.created_count }));
      await reload();
    } catch (err) {
      if (err.status === 409 && err.data?.row_errors) {
        const rowErrors = err.data.row_errors;
        const first = rowErrors[0];
        const suffix = rowErrors.length > 1 ? t('preorders.import_more_rows_failed', { count: rowErrors.length - 1 }) : '';
        toast.error(t('preorders.import_row_error', { row: first.row, error: first.errors[0] }) + suffix, { timeout: 12000 });
      } else {
        toast.error(err.message || t('preorders.import_failed'));
      }
    } finally {
      importing.value = false;
    }
  }

  return { exporting, importFileInput, importing, doExportPreorders, doDownloadImportTemplate, triggerImportFile, onImportFileSelected };
}
