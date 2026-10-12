<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { soldOut } from '../../api/products';
import { useOutletStore } from '../../stores/outlet';
import ItemReasonDialog from './ItemReasonDialog.vue';

/**
 * 043 (US3, T046) — "Tandai {nama} sold out?". Teksnya HANYA menyatakan apa yang benar-benar dilakukan sistem:
 * layar pemesanan instalasi ini berhenti menerima item itu dalam ~5 detik (polling, lihat useMenuAvailability) dan
 * status 86 berlaku sampai hari bisnis berakhir. Tidak ada janji kanal online — belum ada integrasinya (research D6).
 * Alasan wajib (kode dari kategori `sold_out`), catatan opsional; tombol Tandai 86 nonaktif sampai alasan dipilih
 * (perilaku ReasonDialog). Pesanan yang sudah ada tidak berubah.
 *
 * 045 Stage 3: dengan lebih dari satu outlet terlihat, dialog menawarkan checkbox outlet (bawaan: outlet terpilih,
 * atau semua bila tidak ada yang dipilih) dan mengirim `outlet_ids`. Disembunyikan di satu outlet; server tetap
 * memeriksa outlet mana yang boleh ditandai.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  product: { type: Object, default: null },
});
const emit = defineEmits(['close', 'done', 'conflict']);
const { t } = useI18n();

const title = computed(() => t('products.sold_out_title', { name: props.product?.name ?? '' }));
const outletStore = useOutletStore();
const multi = computed(() => outletStore.multi && outletStore.outlets.length > 1);
const chosen = ref([]);
watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) chosen.value = outletStore.selected != null ? [outletStore.selected] : outletStore.outlets.map((o) => o.id);
  },
  { immediate: true }
);
function toggle(id, checked) {
  const set = new Set(chosen.value);
  if (checked) set.add(id);
  else set.delete(id);
  chosen.value = outletStore.outlets.map((o) => o.id).filter((x) => set.has(x));
}
const submit = (payload) => soldOut(props.product.id, multi.value ? { ...payload, outlet_ids: chosen.value } : payload);
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
    :extra-valid="!multi || chosen.length > 0"
    @close="emit('close')"
    @done="emit('done', $event)"
    @conflict="emit('conflict')"
  >
    <template v-if="multi" #extra>
      <fieldset class="flex flex-col gap-1.5" data-testid="sold-out-outlets">
        <legend class="mb-1 text-[12.5px] font-semibold text-muted-4">{{ t('products.sold_out_outlets') }}</legend>
        <label v-for="o in outletStore.outlets" :key="o.id" class="flex cursor-pointer items-center gap-2.5 text-[13px]">
          <input type="checkbox" class="h-4 w-4 accent-brand" :checked="chosen.includes(o.id)" @change="toggle(o.id, $event.target.checked)" />
          {{ o.code }} · {{ o.name }}
        </label>
      </fieldset>
    </template>
  </ItemReasonDialog>
</template>
