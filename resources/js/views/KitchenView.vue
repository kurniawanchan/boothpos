<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useAuthStore } from '../stores/auth';
import { useKitchenStore } from '../stores/kitchen';
import { usePolling } from '../composables/usePolling';
import { useNow } from '../composables/useNow';
import StationBoard from '../components/restaurant/StationBoard.vue';
import PrintableTicket from '../components/restaurant/PrintableTicket.vue';
import EmptyState from '../components/ui/EmptyState.vue';

/**
 * 028 US4 — layar dapur/bar. Satu stasiun per tab; papan tiket tertua-dulu, diperbarui
 * lewat polling pendek (usePolling) dengan token versi. Tombol aksi hanya muncul bila peran
 * boleh melakukannya (`kitchen`: mulai/siap; `floor` atau `kitchen`: sudah diantar) —
 * server tetap memeriksa ulang setiap perubahan status.
 */
const { t } = useI18n();
const auth = useAuthStore();
const kitchen = useKitchenStore();
const now = useNow();

const canCook = computed(() => auth.canAccessMenu('kitchen'));
const canServe = computed(() => auth.canAccessMenu('kitchen') || auth.canAccessMenu('floor'));

const { start, refresh, lastError } = usePolling(({ full }) => kitchen.fetch({ full }));
const ready = ref(false);

onMounted(async () => {
  try {
    await kitchen.loadStations();
  } finally {
    ready.value = true;
  }
  start();
});

const busy = ref(false);
async function changeStatus(item, next) {
  busy.value = true;
  try {
    await kitchen.setStatus(item, next);
  } catch {
    // 403/409 sudah ditoast global; ambil keadaan terbaru agar layar tak menampilkan status basi.
    refresh();
  } finally {
    busy.value = false;
  }
}

function pickStation(id) {
  kitchen.selectStation(id);
  refresh();
}

const printId = ref(null);
</script>

<template>
  <div class="flex flex-col gap-5 px-[26px] pb-10 pt-5">
    <p v-if="lastError" role="alert" class="rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5 text-[13px] font-semibold text-warn-text">
      {{ t('kitchen.load_failed') }}
    </p>

    <EmptyState v-if="ready && !kitchen.stations.length" icon="ph-cooking-pot" :message="t('kitchen.no_stations')" />

    <template v-else-if="ready">
      <div role="tablist" :aria-label="t('kitchen.stations')" class="flex flex-wrap gap-1 border-b border-line-2">
        <button
          v-for="station in kitchen.stations"
          :key="station.id"
          role="tab"
          type="button"
          :aria-selected="kitchen.stationId === station.id"
          class="-mb-px border-b-2 px-5 py-2.5 text-[14px] font-bold"
          :class="kitchen.stationId === station.id ? 'border-brand text-brand-active' : 'border-transparent text-muted-3 hover:text-ink'"
          @click="pickStation(station.id)"
        >
          {{ station.name }}
        </button>
      </div>

      <StationBoard
        :active="kitchen.activeTickets"
        :recent="kitchen.recentTickets"
        :now="now"
        :can-cook="canCook"
        :can-serve="canServe"
        :busy="busy"
        :loaded="kitchen.loaded"
        @status="changeStatus"
        @print="(ticket) => (printId = ticket.id)"
      />
    </template>

    <PrintableTicket :open="printId !== null" :ticket-id="printId" @close="printId = null" />
  </div>
</template>
