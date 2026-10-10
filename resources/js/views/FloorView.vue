<script setup>
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useFloorStore } from '../stores/floor';
import { useToastStore } from '../stores/toast';
import { usePolling } from '../composables/usePolling';
import { currentSession } from '../api/sessions';
import { openTab } from '../api/tabs';
import { uuid } from '../utils/uuid';
import TableCard from '../components/restaurant/TableCard.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseModal from '../components/ui/BaseModal.vue';
import BaseInput from '../components/ui/BaseInput.vue';

/**
 * 028 US1/US2 — layar Lantai: semua meja aktif per area, kosong/terisi, dan tab
 * takeaway. Diperbarui lewat polling pendek (usePolling); jalur "tidak ada
 * perubahan" murah di server berkat token versi. Meja kosong → buka tab (butuh
 * shift kasir yang terbuka); meja terisi → masuk ke tab-nya.
 */
const { t } = useI18n();
const router = useRouter();
const toast = useToastStore();
const floor = useFloorStore();

const { start, lastError } = usePolling(({ full }) => floor.fetch({ full }));

const session = ref(null);
const sessionChecked = ref(false);

onMounted(async () => {
  start();
  session.value = await currentSession();
  sessionChecked.value = true;
});

const areaLabel = (area) => area || t('floor.no_area');

// Ubin takeaway memakai kartu yang sama dengan meja: nama = label tab.
const takeawayAsTable = (tab) => ({ name: tab.tab_label || tab.order_number, seats: null, state: 'occupied', tab });

function goToTab(id) {
  router.push({ name: 'floor-tab', params: { id } });
}

// --- buka tab di meja kosong ---------------------------------------------------
const pendingTable = ref(null);
const opening = ref(false);
// uuid dibuat SEKALI per percobaan buka: klik ganda / kirim ulang mengembalikan tab pertama.
let openRef = null;

function selectTable(table) {
  if (table.state === 'occupied') return goToTab(table.tab.id);
  if (!session.value) return toast.warning(t('floor.tab.need_shift'));
  openRef = uuid();
  pendingTable.value = table;
}

async function confirmOpenTable() {
  opening.value = true;
  try {
    const tab = await openTab({ session_id: session.value.id, table_id: pendingTable.value.id, local_ref: openRef });
    pendingTable.value = null;
    goToTab(tab.id);
  } catch (err) {
    // 409 (meja sudah dipakai tab lain) sudah ditoast global; muat ulang lantai agar tampil terbaru.
    pendingTable.value = null;
    if (err.isConflict && err.data?.existing_tab_id) goToTab(err.data.existing_tab_id);
    else floor.fetch({ full: true }).catch(() => {});
  } finally {
    opening.value = false;
  }
}

// --- tab takeaway --------------------------------------------------------------
const showTakeaway = ref(false);
const takeawayLabel = ref('');

function startTakeaway() {
  if (!session.value) return toast.warning(t('floor.tab.need_shift'));
  openRef = uuid();
  takeawayLabel.value = '';
  showTakeaway.value = true;
}

async function confirmTakeaway() {
  opening.value = true;
  try {
    const tab = await openTab({ session_id: session.value.id, tab_label: takeawayLabel.value.trim() || null, local_ref: openRef });
    showTakeaway.value = false;
    goToTab(tab.id);
  } catch {
    showTakeaway.value = false;
  } finally {
    opening.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-5 px-[26px] pb-10 pt-5">
    <p v-if="lastError" role="alert" class="rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5 text-[13px] font-semibold text-warn-text">
      {{ t('floor.load_failed') }}
    </p>

    <div v-if="sessionChecked && !session" role="status" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warn-border bg-warn-bg px-3.5 py-2.5 text-[13px] font-semibold text-warn-text">
      <span>{{ t('floor.tab.need_shift') }}</span>
      <BaseButton size="sm" variant="secondary" @click="router.push({ name: 'session' })">{{ t('floor.tab.go_session') }}</BaseButton>
    </div>

    <div class="flex justify-end">
      <BaseButton variant="secondary" @click="startTakeaway">
        <i class="ph-duotone ph-bag text-[16px]" aria-hidden="true"></i>
        {{ t('floor.tab.new_takeaway') }}
      </BaseButton>
    </div>

    <EmptyState v-if="floor.isEmpty" :message="t('floor.empty')" />

    <section v-for="group in floor.areas" :key="group.area" :aria-label="areaLabel(group.area)" class="flex flex-col gap-2.5">
      <h2 class="text-[13px] font-extrabold uppercase tracking-wide text-muted-3">{{ areaLabel(group.area) }}</h2>
      <div class="grid grid-cols-[repeat(auto-fill,minmax(180px,1fr))] gap-3">
        <TableCard v-for="table in group.tables" :key="table.id" :table="table" selectable @select="selectTable" />
      </div>
    </section>

    <section v-if="floor.loaded" :aria-label="t('floor.takeaway')" class="flex flex-col gap-2.5">
      <h2 class="text-[13px] font-extrabold uppercase tracking-wide text-muted-3">{{ t('floor.takeaway') }}</h2>
      <p v-if="!floor.takeawayTabs.length" class="text-[13px] text-muted-3">{{ t('floor.takeaway_empty') }}</p>
      <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(180px,1fr))] gap-3">
        <TableCard v-for="tab in floor.takeawayTabs" :key="tab.id" :table="takeawayAsTable(tab)" selectable @select="goToTab(tab.id)" />
      </div>
    </section>

    <BaseModal :open="!!pendingTable" :title="t('floor.tab.open_tab')" max-width-class="max-w-[420px]" @close="pendingTable = null">
      <p class="px-6 py-5 text-[13.5px] text-muted-4">{{ t('floor.tab.open_tab_for', { name: pendingTable?.name }) }}</p>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="pendingTable = null">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="opening" @click="confirmOpenTable">{{ t('floor.tab.open_tab') }}</BaseButton>
        </div>
      </template>
    </BaseModal>

    <BaseModal :open="showTakeaway" :title="t('floor.tab.new_takeaway')" max-width-class="max-w-[420px]" @close="showTakeaway = false">
      <form class="flex flex-col gap-3.5 px-6 py-5" @submit.prevent="confirmTakeaway">
        <BaseInput v-model="takeawayLabel" :label="t('floor.tab.takeaway_label')" :hint="t('floor.tab.takeaway_label_hint')" maxlength="100" />
      </form>
      <template #footer>
        <div class="flex justify-end gap-2.5">
          <BaseButton variant="secondary" @click="showTakeaway = false">{{ t('common.cancel') }}</BaseButton>
          <BaseButton :loading="opening" @click="confirmTakeaway">{{ t('floor.tab.open_tab') }}</BaseButton>
        </div>
      </template>
    </BaseModal>
  </div>
</template>
