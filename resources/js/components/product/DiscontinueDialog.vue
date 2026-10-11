<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { discontinueProduct } from '../../api/products';
import { useSettingsStore } from '../../stores/settings';
import ItemReasonDialog from './ItemReasonDialog.vue';

/**
 * 043 (US4, T052) — "Discontinue {nama}?". Item tak bisa ditambahkan ke order baru tetapi tetap tampil di histori
 * dan laporan (tidak ada yang dihapus). Kalimat "Berlaku setelah rilis menu berikutnya" hanya muncul bila rilis menu
 * aktif di instalasi ini (settings.menuReleaseEnabled); tanpa rilis perubahan berlaku seketika dan kalimat itu
 * tak boleh tampil. Alasan wajib (kategori `discontinue`).
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  product: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done', 'conflict']);
const { t } = useI18n();
const settings = useSettingsStore();

const title = computed(() => t('products.discontinue_title', { name: props.product?.name ?? '' }));
const message = computed(() =>
  settings.menuReleaseEnabled ? `${t('products.discontinue_message')} ${t('products.discontinue_after_release')}` : t('products.discontinue_message')
);
const submit = (payload) => discontinueProduct(props.product.id, payload);
</script>

<template>
  <ItemReasonDialog
    :open="open && !!product"
    category="discontinue"
    :title="title"
    :message="message"
    :confirm-label="t('products.discontinue_confirm')"
    :danger="true"
    :submit="submit"
    @close="emit('close')"
    @done="emit('done', $event)"
    @conflict="emit('conflict')"
  />
</template>
