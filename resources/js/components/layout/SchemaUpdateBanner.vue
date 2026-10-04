<script setup>
import { useI18n } from 'vue-i18n';

/**
 * 035-po-row-actions — database tertinggal dari versi aplikasi. Hanya dipasang
 * untuk owner/admin (server yang menentukan lewat `schema_update_required`).
 * Sengaja TIDAK menyebut tabel/kolom/SQL dan tidak menawarkan tombol
 * "perbarui": aplikasi tidak pernah menjalankan migrasi dari sebuah request.
 */
const { t } = useI18n();
</script>

<template>
  <div role="alert" class="flex items-start gap-2.5 border-b border-warn-border bg-warn-bg px-6 py-3 text-[13px] text-warn-text">
    <i class="ph-duotone ph-warning mt-0.5 text-[16px]" aria-hidden="true"></i>
    <div class="flex flex-col gap-0.5">
      <span class="font-bold">{{ t('schema.update_title') }}</span>
      <span>{{ t('schema.update_body') }}</span>
    </div>
  </div>
</template>
