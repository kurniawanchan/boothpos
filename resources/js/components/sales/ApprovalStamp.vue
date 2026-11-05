<script setup>
import { useI18n } from 'vue-i18n';

// 059 — artwork stempel "APPROVED": bawaan, bukan unggahan. Hanya token desain (tanpa hex), jadi mengikuti tema.
// Stempel BUKAN tanda tangan: ia mencatat bahwa pengguna terautentikasi menekan setuju (nama + tanggal ikut tampil).
defineProps({
  seller: { type: String, default: '' },
  approver: { type: String, default: '' },
  date: { type: String, default: '' },
});
const { t } = useI18n();
</script>

<template>
  <div
    data-testid="approval-stamp"
    class="inline-flex -rotate-3 flex-col items-center gap-0.5 rounded-lg border-2 border-brand px-4 py-2 text-center text-brand-active"
    role="img"
    :aria-label="`${t('approvals.stamp_text')} — ${seller}`"
  >
    <span class="text-[15px] font-extrabold uppercase tracking-[0.18em]">{{ t('approvals.stamp_text') }}</span>
    <span v-if="seller" class="max-w-[200px] truncate text-[11.5px] font-bold">{{ seller }}</span>
    <span v-if="approver || date" class="max-w-[220px] truncate text-[10.5px] font-semibold opacity-80">{{ [approver, date].filter(Boolean).join(' · ') }}</span>
  </div>
</template>
