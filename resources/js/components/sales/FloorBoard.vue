<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useFloorStore } from '../../stores/floor';
import { useAuthStore } from '../../stores/auth';
import { usePolling } from '../../composables/usePolling';
import { useNow } from '../../composables/useNow';
import { buildBoard } from '../../utils/floorBoard';
import { formatIDR } from '../../utils/money';
import { formatCount, formatTimeShort } from '../../utils/formatters';
import KpiStrip from '../page/KpiStrip.vue';
import EmptyState from '../ui/EmptyState.vue';
import ErrorState from '../ui/ErrorState.vue';
import FloorBoardTile from './FloorBoardTile.vue';

/**
 * 044-sales-table-view (US2) — papan "Status meja" di halaman Sales: empat angka, kelompok area, dan grup takeaway.
 *
 * Sumber data = layar Lantai yang sudah ada (`useFloorStore` + `usePolling`, research D1): sudah diizinkan menurut
 * menu `floor`, murah saat tak ada perubahan (token versi), dan urutannya sudah sesuai. HANYA-BACA: ubin tidak
 * membuka/mengubah apa pun, cukup memancarkan `select-order`.
 *
 * Semua angka dan keadaan ubin berasal dari SATU fungsi, `utils/floorBoard.js::buildBoard` — komponen ini tidak
 * menghitung ulang apa pun (SC-004: ringkasan tak boleh menyimpang dari ubin). Jam `useNow` hanya mengganti
 * argumen `nowMs`, jadi lama terbuka & "> 45 menit" bergerak tanpa mengambil data lagi.
 *
 * Polling dimulai saat dipasang dan berhenti saat dilepas (pindah ke "Daftar order"); usePolling sendiri berhenti
 * selama dokumen tersembunyi dan mengambil penuh begitu tampil lagi.
 */
const emit = defineEmits(['select-order']);
const { t } = useI18n();
const floor = useFloorStore();
const auth = useAuthStore();
const now = useNow(15000);

// Waktu pengambilan BERHASIL terakhir (termasuk jawaban `unchanged`). Penentu "siap tampil": store lantai dipakai
// bersama layar Lantai, jadi `floor.loaded` bisa true dari kunjungan lama — data semacam itu tak boleh tampil
// sebagai kondisi saat ini sebelum pengambilan pertama papan ini sukses.
const lastOkAt = ref(null);

const { start, refresh, lastError } = usePolling(async ({ full }) => {
  await floor.fetch({ full });
  lastOkAt.value = Date.now();
});
start();

const board = computed(() => buildBoard({ areas: floor.areas, takeaway_tabs: floor.takeawayTabs }, now.value));

const outletSections = computed(() => board.value.outletSections);
const ready = computed(() => lastOkAt.value !== null);
const failedFirstLoad = computed(() => !ready.value && !!lastError.value);
const isEmpty = computed(() => ready.value && board.value.groups.length === 0 && board.value.takeaway.length === 0);
// Gagal memperbarui SETELAH pernah sukses: data terakhir tetap tampil, hanya diberi tanda (bukan lantai kosong).
const stale = computed(() => ready.value && !!lastError.value);
const updatedAt = computed(() => (lastOkAt.value === null ? '' : formatTimeShort(lastOkAt.value)));

const figures = computed(() => {
  const f = board.value.figures;
  return [
    { key: 'open', label: t('sales.fig_open_orders'), tone: 'info', value: formatCount(f.openOrders), note: t('sales.fig_open_orders_note', { tables: f.occupiedTables, takeaway: board.value.takeaway.length }) },
    { key: 'occupied', label: t('sales.fig_occupied'), tone: 'accent', value: `${formatCount(f.occupiedTables)} / ${formatCount(f.totalTables)}`, note: t('sales.fig_occupied_note', { pct: f.occupancyPct }) },
    { key: 'over', label: t('sales.fig_over'), tone: 'warning', value: formatCount(f.overLimit), note: t(f.overLimit > 0 ? 'sales.fig_over_note_some' : 'sales.fig_over_note_none') },
    { key: 'value', label: t('sales.fig_value'), tone: 'success', value: formatIDR(f.openValue), note: t('sales.fig_value_note') },
  ];
});

// Legenda hanya memuat keadaan yang benar-benar dipakai papan saat ini — legenda yang menjanjikan warna yang
// tak pernah muncul hanya membingungkan.
const legend = computed(() => {
  const all = [...board.value.groups.flatMap((g) => g.tiles), ...board.value.takeaway];
  const items = [
    { key: 'empty', label: t('sales.legend_empty'), swatch: 'border-line bg-white', used: all.some((x) => x.state === 'empty') },
    { key: 'occupied', label: t('sales.legend_occupied'), swatch: 'border-line bg-sky-bg', used: all.some((x) => x.state === 'occupied') },
    { key: 'partial', label: t('sales.legend_partial'), swatch: 'border-warn-border bg-warn-bg', used: all.some((x) => x.partial) },
    { key: 'over', label: t('sales.legend_over'), swatch: 'border-warn-border-strong bg-white', used: all.some((x) => x.overLimit) },
    { key: 'inactive', label: t('sales.legend_inactive'), swatch: 'border-line bg-line-7', used: all.some((x) => x.inactive) },
  ];
  return items.filter((i) => i.used);
});

const areaLabel = (area) => area || t('floor.no_area');

function onSelect(tile, opener) {
  if (tile.orderId === null || tile.orderId === undefined) return; // ubin kosong tak pernah memilih apa pun
  emit('select-order', tile.orderId, opener, tile);
}
</script>

<template>
  <div data-testid="floor-board">
    <p v-if="stale" role="status" data-testid="board-stale" class="mx-[26px] mb-4 border-2 border-warn-border bg-warn-bg px-3.5 py-2.5 text-[13px] font-semibold text-warn-text">
      {{ t('sales.board_stale', { time: updatedAt }) }}
    </p>

    <section v-if="failedFirstLoad" class="mx-[26px] border-2 border-line bg-white">
      <ErrorState :message="t('sales.board_error')" @retry="refresh" />
    </section>

    <section v-else-if="!ready" role="status" class="mx-[26px] border-2 border-line bg-white px-5 py-14 text-center text-[13px] text-muted-3">
      {{ t('sales.board_loading') }}
    </section>

    <section v-else-if="isEmpty" class="mx-[26px] border-2 border-line bg-white">
      <EmptyState icon="ph-table" :message="t('sales.board_empty')" :hint="t('sales.board_empty_hint')">
        <RouterLink
          v-if="auth.canAccessMenu('dining_setup')"
          :to="{ name: 'dining-setup' }"
          class="text-[12.5px] font-semibold text-brand-active hover:underline"
        >{{ t('sales.board_empty_link') }}</RouterLink>
      </EmptyState>
    </section>

    <template v-else>
      <KpiStrip :items="figures" data-testid="board-figures" />

      <div class="mx-[26px] border-2 border-line bg-white">
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2 border-b-2 border-line px-5 py-3.5">
          <div class="min-w-0">
            <h2 class="text-[14px] font-bold tracking-tight">{{ t('sales.board_title') }}</h2>
            <p class="mt-0.5 text-[12px] text-muted">{{ t('sales.board_subtitle', { time: updatedAt }) }}</p>
            <p class="mt-0.5 text-[12px] text-muted-3">{{ t('sales.board_scope_note') }}</p>
          </div>
          <ul class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[12px] font-semibold text-muted-4" data-testid="board-legend">
            <li v-for="item in legend" :key="item.key" class="flex items-center gap-1.5">
              <span class="h-3 w-3 flex-none border-2" :class="item.swatch" aria-hidden="true"></span>
              {{ item.label }}
            </li>
          </ul>
        </div>

        <div class="flex flex-col gap-6 px-5 py-5">
          <!-- 045: beberapa outlet → satu bagian per outlet dengan judul "Outlet — N dari M meja terisi"; satu outlet → daftar area lama. -->
          <section
            v-for="outlet in outletSections"
            :key="`outlet-${outlet.outletId}`"
            :aria-label="outlet.outletName"
            class="flex flex-col gap-5"
            data-testid="board-outlet"
          >
            <h3 class="border-b-2 border-line pb-1.5 text-[14px] font-extrabold tracking-tight" data-testid="board-outlet-heading">
              {{ t('sales.outlet_group_count', { outlet: outlet.outletName, occupied: outlet.occupied, total: outlet.total }) }}
            </h3>
            <section v-for="group in outlet.groups" :key="group.area" :aria-label="areaLabel(group.areaLabel)" class="flex flex-col gap-2.5">
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h4 class="text-[13px] font-extrabold uppercase tracking-wide text-muted-4">{{ areaLabel(group.areaLabel) }}</h4>
                <span class="text-[12px] text-muted-3">{{ t('sales.group_count', { occupied: group.occupied, total: group.total }) }}</span>
              </div>
              <div class="grid grid-cols-[repeat(auto-fill,minmax(190px,1fr))] gap-3">
                <FloorBoardTile v-for="tile in group.tiles" :key="tile.key" :tile="tile" @select="onSelect" />
              </div>
            </section>
          </section>
          <template v-if="!outletSections.length">
          <section v-for="group in board.groups" :key="group.area" :aria-label="areaLabel(group.area)" class="flex flex-col gap-2.5">
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 class="text-[13px] font-extrabold uppercase tracking-wide text-muted-4">{{ areaLabel(group.area) }}</h3>
                <span class="text-[12px] text-muted-3">{{ t('sales.group_count', { occupied: group.occupied, total: group.total }) }}</span>
              </div>
              <div class="grid grid-cols-[repeat(auto-fill,minmax(190px,1fr))] gap-3">
                <FloorBoardTile v-for="tile in group.tiles" :key="tile.key" :tile="tile" @select="onSelect" />
              </div>
            </section>
          </template>

          <section v-if="board.takeaway.length" :aria-label="t('sales.takeaway_group')" class="flex flex-col gap-2.5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
              <h3 class="text-[13px] font-extrabold uppercase tracking-wide text-muted-4">{{ t('sales.takeaway_group') }}</h3>
              <span class="text-[12px] text-muted-3">{{ formatCount(board.takeaway.length) }}</span>
            </div>
            <div class="grid grid-cols-[repeat(auto-fill,minmax(190px,1fr))] gap-3">
              <FloorBoardTile v-for="tile in board.takeaway" :key="tile.key" :tile="tile" @select="onSelect" />
            </div>
          </section>
        </div>
      </div>
    </template>
  </div>
</template>
