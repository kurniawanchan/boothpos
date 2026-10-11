<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { resendPreorderNotification } from '../../api/preorders';
import { useAuthStore } from '../../stores/auth';
import { useToastStore } from '../../stores/toast';
import BaseButton from '../../components/ui/BaseButton.vue';

/**
 * 007-preorder-import-export-notify (US4) — hanya owner/admin,
 * menyamai gerbang server-side isOwnerOrAdmin() (bukan pura-pura
 * tersedia lalu ditolak 403, per Constitution III).
 * Memiliki sendiri aksi "kirim ulang"; hasilnya dilaporkan lewat `notified` supaya detail menyimpan
 * notifikasi terakhir. Dipecah dari PreordersView.vue (041).
 */
const props = defineProps({
  detail: { type: Object, required: true },
});
const emit = defineEmits(['notified']);
const auth = useAuthStore();
const toast = useToastStore();
const { t } = useI18n();
const isOwnerOrAdmin = computed(() => ['owner', 'admin'].includes((auth.role || '').toLowerCase()));

const resendingNotification = ref(false);

async function doResendNotification() {
  if (!props.detail) return;
  resendingNotification.value = true;
  try {
    const result = await resendPreorderNotification(props.detail.id);
    emit('notified', { trigger: 'manual_resend', status: result.status, sent_at: result.sent_at, error_message: null });
    if (result.status === 'sent') toast.success(t('preorders.notification_resent'));
    else toast.warning(t(`preorders.notification_status_${result.status}`));
  } catch (err) {
    toast.error(err.message || t('preorders.notification_resend_failed'));
  } finally {
    resendingNotification.value = false;
  }
}
</script>

<template>
  <div v-if="isOwnerOrAdmin" data-testid="notification-card" class="flex flex-col gap-2.5 rounded-card border border-line-2 bg-white p-5">
    <span class="text-[14.5px] font-bold">{{ t('preorders.notification_section_title') }}</span>
    <div v-if="detail.latest_notification" class="flex items-center gap-2 text-[12.5px]">
      <i
        class="ph-duotone text-[16px]"
        :class="detail.latest_notification.status === 'sent' ? 'ph-check-circle text-brand-active' : detail.latest_notification.status === 'failed' ? 'ph-x-circle text-danger-text' : 'ph-warning-circle text-warn-text'"
        aria-hidden="true"
      ></i>
      <span>{{ t(`preorders.notification_status_${detail.latest_notification.status}`) }}</span>
    </div>
    <p v-else class="text-[12px] text-muted-3">{{ t('preorders.notification_none_yet') }}</p>
    <BaseButton variant="secondary" size="sm" :loading="resendingNotification" @click="doResendNotification">
      <i class="ph-duotone ph-paper-plane-tilt text-[15px]" aria-hidden="true"></i>
      {{ t('preorders.resend_notification_action') }}
    </BaseButton>
  </div>
</template>
