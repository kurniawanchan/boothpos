<script setup>
import { useI18n } from 'vue-i18n';
import { formatDate, formatIDR } from '../../utils/formatters';
import ItemSection from './ItemSection.vue';
import BaseButton from '../ui/BaseButton.vue';

/**
 * 043 (US2) — "Riwayat harga": lini masa, TERBARU DULU (urutan dari server dipertahankan, tidak diurutkan ulang
 * di sini). Tiap entri: tanggal, "Harga dasar Rp 8.000" (+ nama varian bila item punya lebih dari satu), dan baris
 * kecil "Diajukan {oleh} · disetujui {oleh} · rilis v{n}" memuat HANYA bagian yang ada. Entri terakhir (source
 * `created`) berjudul "Item dibuat". Berpaginasi: "Muat lebih banyak" bila masih ada halaman berikutnya.
 */
const props = defineProps({
  entries: { type: Array, default: () => [] },
  state: { type: String, default: 'ready' }, // loading | error | ready
  hasMore: { type: Boolean, default: false },
  loadingMore: { type: Boolean, default: false },
  multiVariant: { type: Boolean, default: false },
});
defineEmits(['retry', 'more']);
const { t } = useI18n();

function title(e) {
  if (e.source === 'created') return t('products.history_created');
  // 045: perubahan harga OUTLET menyebut outletnya; tanpa outlet_code = harga dasar.
  const price = e.outlet_code
    ? t('products.history_outlet_price', { outlet: e.outlet_code, price: formatIDR(e.new_price) })
    : t('products.history_price', { price: formatIDR(e.new_price) });
  const head = props.multiVariant && e.variant ? `${e.variant} · ${price}` : price;
  return e.old_price !== null && e.old_price !== undefined ? `${head} (${t('products.history_price_prev', { price: formatIDR(e.old_price) })})` : head;
}

function sub(e) {
  const parts = [];
  if (e.source === 'created') {
    parts.push(t('products.history_initial_price', { price: formatIDR(e.new_price) }));
    if (e.by) parts.push(t('products.history_created_by', { name: e.by }));
    return parts.join(' · ');
  }
  // "Diajukan" hanya bila perubahan melewati persetujuan/rilis; perubahan yang langsung berlaku = "Diubah oleh".
  const viaRelease = Boolean(e.approved_by || e.release_version);
  if (e.by) parts.push(t(viaRelease ? 'products.history_proposed' : 'products.history_changed', { name: e.by }));
  if (e.approved_by) parts.push(t('products.history_approved', { name: e.approved_by }));
  if (e.release_version) parts.push(t('products.history_release', { version: e.release_version }));
  return parts.join(' · ');
}
</script>

<template>
  <ItemSection :title="t('products.sec_history')" :state="state" testid="section-history" @retry="$emit('retry')">
    <p v-if="!entries.length" class="px-5 py-6 text-[13px] text-muted-4">{{ t('products.history_empty') }}</p>
    <template v-else>
      <ol class="flex flex-col px-5 py-4" data-testid="history-list">
        <li v-for="(e, i) in entries" :key="`${e.at}-${i}`" class="relative flex gap-4 pb-4 last:pb-0" data-testid="history-entry">
          <span class="relative flex w-3 flex-none justify-center" aria-hidden="true">
            <span class="mt-1.5 h-2.5 w-2.5 flex-none border-2 border-brand bg-white"></span>
            <span v-if="i < entries.length - 1" class="absolute bottom-[-16px] top-4 w-px bg-line"></span>
          </span>
          <div class="flex min-w-0 flex-col gap-0.5">
            <span class="text-[11.5px] font-semibold text-muted">{{ formatDate(e.at) }}</span>
            <span class="text-[13.5px] font-bold tabular-nums">{{ title(e) }}</span>
            <span v-if="sub(e)" class="text-[12px] text-muted-4">{{ sub(e) }}</span>
            <span v-if="e.note" class="text-[12px] text-muted">{{ e.note }}</span>
          </div>
        </li>
      </ol>
      <div v-if="hasMore" class="border-t-2 border-line px-5 py-3">
        <BaseButton variant="secondary" size="sm" :loading="loadingMore" @click="$emit('more')">{{ t('products.history_more') }}</BaseButton>
      </div>
    </template>
  </ItemSection>
</template>
