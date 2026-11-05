<script setup>
import { useI18n } from 'vue-i18n';

/**
 * 058 — seller milik pengguna dinonaktifkan: tampilan kosong yang DIJELASKAN, bukan jatuh ke tampilan penuh.
 * Server yang menegakkan pembatasan; banner ini hanya memberi tahu mengapa layar kosong.
 */
const { t } = useI18n();
</script>

<template>
  <div role="alert" class="flex items-start gap-2.5 border-b border-warn-border bg-warn-bg px-6 py-3 text-[13px] text-warn-text" data-testid="seller-inactive-banner">
    <i class="ph-duotone ph-warning mt-0.5 text-[16px]" aria-hidden="true"></i>
    <div class="flex flex-col gap-0.5">
      <span class="font-bold">{{ t('seller_scope.inactive_title') }}</span>
      <span>{{ t('seller_scope.inactive_body') }}</span>
    </div>
  </div>
</template>
