<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { exportMasterData } from '../api/masterData';
import { useAuthStore } from '../stores/auth';
import { useToastStore } from '../stores/toast';
import BaseButton from '../components/ui/BaseButton.vue';
import PageHeader from '../components/page/PageHeader.vue';
import MasterDataImportModal from '../components/masterData/MasterDataImportModal.vue';
import MaterialsTable from '../components/masterData/MaterialsTable.vue';

/**
 * Layar Bahan Baku: kepala halaman + ekspor/impor; seluruh daftar, formulir, hapus dan harga vendor ada di
 * MaterialsTable (dipakai juga oleh tab bahan di halaman Produk, 043). Perilaku tidak berubah.
 */
const auth = useAuthStore();
const { t } = useI18n();
const toast = useToastStore();

const table = ref(null);
const exporting = ref(false);
const showImportModal = ref(false);

async function doExport() {
  exporting.value = true;
  try {
    await exportMasterData('materials');
  } catch {
    toast.error(t('vendors_materials.export_material_failed'));
  } finally {
    exporting.value = false;
  }
}

async function afterImport() {
  await table.value?.reload();
}
</script>

<template>
  <div class="pb-10">
    <PageHeader :title="t('nav.materials')" :description="t('nav.materials_subtitle')">
      <template #actions>
        <template v-if="auth.canAccessMenu('materials')">
          <BaseButton variant="secondary" :loading="exporting" @click="doExport">
            <i class="ph-duotone ph-microsoft-excel-logo text-[16px]" aria-hidden="true"></i>
            {{ t('common.export_xlsx') }}
          </BaseButton>
          <BaseButton variant="secondary" @click="showImportModal = true">
            <i class="ph-duotone ph-file-arrow-up text-[16px]" aria-hidden="true"></i>
            {{ t('common.bulk_import') }}
          </BaseButton>
        </template>
        <BaseButton @click="table.openCreate()">
          <i class="ph-duotone ph-plus text-[16px]" aria-hidden="true"></i>
          {{ t('vendors_materials.new_material_btn') }}
        </BaseButton>
      </template>
    </PageHeader>

    <MaterialsTable ref="table" />

    <MasterDataImportModal :open="showImportModal" @close="showImportModal = false" @imported="afterImport" />
  </div>
</template>
