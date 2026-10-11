<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { soldOut } from '../../api/products';
import ItemReasonDialog from './ItemReasonDialog.vue';

/**
 * 043 (US3, T046) — "Tandai {nama} sold out?". Teksnya HANYA menyatakan apa yang benar-benar dilakukan sistem:
 * layar pemesanan instalasi ini berhenti menerima item itu dalam ~5 detik (polling, lihat useMenuAvailability) dan
 * status 86 berlaku sampai hari bisnis berakhir. Tidak ada janji kanal online — belum ada integrasinya (research D6).
 * Alasan wajib (kode dari kategori `sold_out`), catatan opsional; tombol Tandai 86 nonaktif sampai alasan dipilih
 * (perilaku ReasonDialog). Pesanan yang sudah ada tidak berubah.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  product: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done', 'conflict']);
const { t } = useI18n();

const title = computed(() => t('products.sold_out_title', { name: props.product?.name ?? '' }));
const submit = (payload) => soldOut(props.product.id, payload);
</script>

<template>
  <ItemReasonDialog
    :open="open && !!product"
    category="sold_out"
    :title="title"
    :message="t('products.sold_out_message')"
    :confirm-label="t('products.sold_out_confirm')"
    :danger="false"
    :submit="submit"
    @close="emit('close')"
    @done="emit('done', $event)"
    @conflict="emit('conflict')"
  />
</template>
