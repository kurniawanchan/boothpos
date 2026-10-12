<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatIDR } from '../../utils/money';
import { formatElapsed } from '../../utils/floorBoard';
import StatusPill from '../ui/StatusPill.vue';

/**
 * 044-sales-table-view (US2) — satu ubin papan Meja di halaman Sales. SENGAJA bukan `TableCard` milik layar
 * kasir (restaurant/TableCard.vue): kartu itu dibangun untuk MEMBUKA/MENGELOLA tab, sedangkan papan ini
 * hanya-baca dan punya keadaan sendiri (sebagian dibayar, lewat 45 menit, nonaktif).
 *
 * Murni tampilan: `tile` adalah keluaran utils/floorBoard.js (keadaan, lama terbuka, nominal sudah dihitung
 * di sana). Tidak ada perhitungan di sini — jadi angka ubin tak bisa menyimpang dari ringkasan empat angka
 * (SC-004) dan "jam berdetak" cukup memberi ubin baru dari induk, tanpa mengambil data lagi.
 *
 * Hanya ubin terisi/takeaway yang menjadi <button> sungguhan (Enter/Spasi bawaan); ubin kosong bukan kontrol
 * sehingga tidak ikut urutan Tab dan tak pernah memancarkan apa pun.
 */
const props = defineProps({
  tile: { type: Object, required: true },
});
const emit = defineEmits(['select']);
const { t } = useI18n();

const occupied = computed(() => props.tile.state === 'occupied');
const elapsed = computed(() => formatElapsed(props.tile.elapsedMinutes, t));

// Isian: sebagian dibayar (kuning) > terisi (biru muda) > kosong (putih). Lewat 45 menit menambah GARIS LUAR
// kuning, bukan isian — jadi keduanya bisa tampil bersamaan (data-model.md, "Tile state precedence").
const fillClass = computed(() => {
  if (!occupied.value) return 'border-line bg-white';
  return props.tile.partial ? 'border-warn-border bg-warn-bg' : 'border-line bg-sky-bg';
});
const outlineClass = computed(() => (props.tile.overLimit ? 'ring-2 ring-warn-border-strong' : ''));

const badge = computed(() => {
  if (!occupied.value) return { label: t('sales.badge_empty'), variant: 'neutral' };
  if (props.tile.partial) return { label: t('sales.badge_partial'), variant: 'warning' };
  return { label: t('sales.badge_occupied'), variant: 'info' };
});

// Nama aksesibel: ringkasan dari kunci terjemahan + keadaan tambahan, supaya warna/garis luar bukan satu-satunya penanda.
const ariaLabel = computed(() => {
  const parts = [t('sales.tile_aria', {
    name: props.tile.name,
    order: props.tile.orderNumber,
    elapsed: elapsed.value,
    amount: formatIDR(props.tile.amount),
  })];
  if (props.tile.partial) parts.push(t('sales.legend_partial'));
  if (props.tile.overLimit) parts.push(t('sales.legend_over'));
  if (props.tile.inactive) parts.push(t('sales.legend_inactive'));
  return parts.join(', ');
});

function onSelect(event) {
  emit('select', props.tile, event.currentTarget);
}
</script>

<template>
  <button
    v-if="occupied"
    type="button"
    class="flex min-h-[104px] w-full flex-col gap-1.5 rounded-card border p-3 text-left transition-colors hover:border-brand focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-brand"
    :class="[fillClass, outlineClass]"
    :aria-label="ariaLabel"
    :data-testid="`board-tile-${tile.key}`"
    :data-state="tile.state"
    :data-partial="tile.partial ? 'true' : undefined"
    :data-over="tile.overLimit ? 'true' : undefined"
    @click="onSelect"
  >
    <span class="flex items-start justify-between gap-2">
      <span class="min-w-0 truncate text-[16px] font-extrabold text-ink">{{ tile.name }}</span>
      <span class="flex flex-none flex-wrap justify-end gap-1">
        <StatusPill v-if="tile.inactive" variant="neutral">{{ t('sales.badge_inactive') }}</StatusPill>
        <StatusPill :variant="badge.variant">{{ badge.label }}</StatusPill>
      </span>
    </span>
    <span class="truncate font-mono text-[11.5px] font-semibold text-muted-4">{{ tile.orderNumber }}</span>
    <span class="mt-auto flex items-end justify-between gap-2">
      <span class="flex items-center gap-1 text-[12px] font-semibold" :class="tile.overLimit ? 'text-warn-text' : 'text-muted-4'">
        <i v-if="tile.overLimit" class="ph-duotone ph-clock-countdown text-[14px]" aria-hidden="true"></i>
        {{ elapsed }}
      </span>
      <span class="text-[15px] font-bold tabular-nums text-ink">{{ formatIDR(tile.amount) }}</span>
    </span>
  </button>

  <div
    v-else
    class="flex min-h-[104px] w-full flex-col gap-1.5 rounded-card border p-3"
    :class="fillClass"
    :data-testid="`board-tile-${tile.key}`"
    :data-state="tile.state"
  >
    <div class="flex items-start justify-between gap-2">
      <span class="min-w-0 truncate text-[16px] font-extrabold text-ink">{{ tile.name }}</span>
      <span class="flex flex-none flex-wrap justify-end gap-1">
        <StatusPill v-if="tile.inactive" variant="neutral">{{ t('sales.badge_inactive') }}</StatusPill>
        <StatusPill :variant="badge.variant">{{ badge.label }}</StatusPill>
      </span>
    </div>
    <span class="mt-auto text-[12.5px] font-semibold text-muted-3">{{ t('sales.available') }}</span>
  </div>
</template>
