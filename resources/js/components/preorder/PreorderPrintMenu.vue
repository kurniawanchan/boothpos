<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseButton from '../ui/BaseButton.vue';

/**
 * Tombol dropdown "Cetak" di panel detail pre-order: satu tombol, dua
 * dokumen (invoice pre-order & invoice pembayaran). Komponen ini hanya
 * mengurus buka/tutup menu — membuka modal dokumennya tetap tanggung jawab
 * pemanggil lewat event, supaya jalur cetak yang sudah ada di
 * PreordersView tidak diduplikasi.
 *
 * Invoice pembayaran butuh minimal satu pembayaran tercatat; tanpa itu
 * item-nya dinonaktifkan (bukan disembunyikan) agar alasannya terlihat.
 */
defineProps({
  hasPayment: { type: Boolean, default: false },
});
const emit = defineEmits(['print-invoice', 'print-payment']);

const { t } = useI18n();
const open = ref(false);
const root = ref(null);

function choose(event) {
  open.value = false;
  emit(event);
}

function onDocumentClick(e) {
  if (open.value && root.value && !root.value.contains(e.target)) open.value = false;
}
function onKeydown(e) {
  if (e.key === 'Escape') open.value = false;
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick);
  document.addEventListener('keydown', onKeydown);
});
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick);
  document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
  <div ref="root" class="relative inline-block">
    <BaseButton variant="secondary" size="sm" aria-haspopup="menu" :aria-expanded="open" @click="open = !open">
      <i class="ph-duotone ph-printer text-[15px]" aria-hidden="true"></i>
      {{ t('preorders.print_menu') }}
      <i class="ph-bold ph-caret-down text-[12px]" aria-hidden="true"></i>
    </BaseButton>
    <div
      v-if="open"
      role="menu"
      class="absolute right-0 z-20 mt-1.5 flex w-56 flex-col overflow-hidden rounded-lg border border-line bg-white py-1 shadow-lg"
    >
      <button
        type="button"
        role="menuitem"
        class="px-3.5 py-2.5 text-left text-[13px] font-semibold text-muted-5 hover:bg-line-7"
        @click="choose('print-invoice')"
      >{{ t('preorders.print_preorder_invoice') }}</button>
      <button
        type="button"
        role="menuitem"
        :disabled="!hasPayment"
        :title="hasPayment ? '' : t('preorders.print_payment_invoice_disabled')"
        class="px-3.5 py-2.5 text-left text-[13px] font-semibold text-muted-5 hover:bg-line-7 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"
        @click="choose('print-payment')"
      >{{ t('preorders.print_payment_invoice') }}</button>
    </div>
  </div>
</template>
