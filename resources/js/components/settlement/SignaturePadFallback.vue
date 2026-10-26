<script setup>
import { useI18n } from 'vue-i18n';

// 076 — tampil bila kanvas tanda tangan (chunk yang dimuat belakangan) gagal dimuat, mis. halaman lama
// setelah aplikasi diperbarui. Tanpa ini dialog hanya menampilkan kolom nama dan tampak "tanpa kanvas".
const { t } = useI18n();
</script>

<template>
  <div role="alert" class="flex flex-col gap-2 rounded-lg border border-warn-border bg-warn-bg px-4 py-3 text-[13px] text-warn-text">
    <span class="font-semibold">{{ t('settlements.ack_pad_failed') }}</span>
    <button type="button" class="self-start font-bold underline" @click="() => $el.ownerDocument.defaultView.location.reload()">{{ t('settlements.ack_pad_reload') }}</button>
  </div>
</template>
