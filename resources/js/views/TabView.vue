<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { useFloorStore } from '../stores/floor';
import { useAuthStore } from '../stores/auth';
import { usePolling } from '../composables/usePolling';
import { useToastStore } from '../stores/toast';
import { listProducts } from '../api/products';
import { listCategories } from '../api/categories';
import { addTabLine, updateTabLine, removeTabLine, payTab, closeEmptyTab, sendTab, getTab, moveTab, mergeTab, splitTab, voidTab } from '../api/tabs';
import { setTicketItemStatus } from '../api/kitchen';
import { buildProductCards } from '../utils/posProductCards';
import { formatIDR } from '../utils/money';
import { uuid } from '../utils/uuid';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import TabLinesList from '../components/restaurant/TabLinesList.vue';
import TabPaymentModal from '../components/restaurant/TabPaymentModal.vue';
import ProductVariantPickerModal from '../components/pos/ProductVariantPickerModal.vue';
import ModifierPickerModal from '../components/restaurant/ModifierPickerModal.vue';
import MoveTabModal from '../components/restaurant/MoveTabModal.vue';
import MergeTabModal from '../components/restaurant/MergeTabModal.vue';
import SplitBillModal from '../components/restaurant/SplitBillModal.vue';
import ReasonDialog from '../components/restaurant/ReasonDialog.vue';
import ReceiptModal from '../components/receipt/ReceiptModal.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import BaseSelect from '../components/ui/BaseSelect.vue';
import EmptyState from '../components/ui/EmptyState.vue';

/**
 * 028 US2 — layar sebuah tab: pilih menu di kiri, baris tab + total + bayar di
 * kanan. Server adalah satu-satunya sumber angka (harga, total, saldo): setiap
 * aksi memanggil API lalu mengganti seluruh tab di store dengan respons terbaru.
 * Galat 409 (stok kurang, tab tidak terbuka, …) sudah ditoast oleh penangan
 * galat pusat, jadi blok catch di sini sengaja hanya menjaga keadaan layar.
 */
const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const toast = useToastStore();
const floor = useFloorStore();
const auth = useAuthStore();
const canServe = computed(() => auth.canAccessMenu('floor') || auth.canAccessMenu('kitchen'));
// owner/admin (kunci `settings`, seperti void penjualan): boleh membatalkan item yang sudah dimasak dan membatalkan tab.
const isManager = computed(() => auth.canAccessMenu('settings'));

const tabId = computed(() => Number(route.params.id));
const tab = computed(() => floor.tab);
const isOpen = computed(() => tab.value?.status === 'open');
const placeLabel = computed(() => (tab.value?.table ? t('floor.tab.table_label', { name: tab.value.table.name }) : tab.value?.tab_label || t('floor.takeaway')));

const loadFailed = ref(false);
const busy = ref(false);

// Setiap aksi pengguna menaikkan penghitung ini. Hasil polling yang DIMULAI sebelum aksi terakhir
// dibuang: tab dari server yang lebih tua tidak boleh menimpa respons aksi yang lebih baru.
let mutationSeq = 0;

// Status persiapan (siap saji!) berubah dari layar lain, jadi tab ikut disegarkan berkala.
const { start: startPolling } = usePolling(async () => {
  if (!tab.value || busy.value) return;
  const seq = mutationSeq;
  const latest = await getTab(tabId.value);
  if (seq === mutationSeq && !busy.value) floor.applyTab(latest);
}, { interval: 4000, immediate: false });

onMounted(async () => {
  floor.clearTab();
  try {
    await Promise.all([floor.loadTab(tabId.value), loadCategories(), loadMenu()]);
    startPolling();
  } catch {
    loadFailed.value = true;
  }
});
onBeforeUnmount(() => floor.clearTab());

// --- menu ------------------------------------------------------------------
const categories = ref([]);
const categoryId = ref('');
const search = ref('');
const products = ref([]);
const loadingMenu = ref(false);

async function loadCategories() {
  categories.value = (await listCategories({ per_page: 100 })).data;
}

async function loadMenu() {
  loadingMenu.value = true;
  try {
    const res = await listProducts({
      with_variants: 1,
      with_modifiers: 1,
      per_page: 100,
      search: search.value || undefined,
      category_id: categoryId.value ? [categoryId.value] : undefined,
    });
    products.value = res.data;
  } finally {
    loadingMenu.value = false;
  }
}
const debouncedLoadMenu = useDebouncedFn(loadMenu, 300);

const cards = computed(() =>
  buildProductCards(
    products.value,
    Object.fromEntries(categories.value.map((c) => [c.id, c.code])),
    Object.fromEntries(categories.value.map((c) => [c.id, c.name])),
  ).filter((c) => c.variant_count > 0),
);
const categoryOptions = computed(() => categories.value.map((c) => ({ value: c.id, label: c.name })));

// Hidangan made-to-order (track_stock = false) tidak punya stok fisik: tidak boleh tampil "habis".
const isUnavailable = (card) => card.variants.every((v) => v.track_stock !== false ? v.current_stock <= 0 : false);

// Grup modifier efektif per produk (dari GET /products?with_modifiers=1).
const groupsByProduct = computed(() => Object.fromEntries(products.value.map((p) => [p.id, p.modifier_groups ?? []])));

const pickerCard = ref(null);
// Item yang sedang dipilihkan modifier-nya: { card, variant, groups }.
const modifierTarget = ref(null);

async function run(action) {
  mutationSeq += 1;
  busy.value = true;
  try {
    floor.applyTab(await action());
    return true;
  } catch {
    return false;
  } finally {
    busy.value = false;
  }
}

function onPickCard(card) {
  if (!isOpen.value || busy.value || isUnavailable(card)) return;
  if (card.variants.length === 1) startAdd(card, card.variants[0]);
  else pickerCard.value = card;
}
function onPickVariant(variant) {
  const card = pickerCard.value;
  pickerCard.value = null;
  startAdd(card, variant);
}
// Menu yang punya modifier membuka pemilih dulu; selain itu langsung ditambahkan.
function startAdd(card, variant) {
  const groups = groupsByProduct.value[card.product_id] ?? [];
  if (groups.length) modifierTarget.value = { card, variant, groups };
  else addVariant(variant, {});
}
async function addVariant(variant, extra = {}) {
  await run(() => addTabLine(tabId.value, { variant_id: variant.id, qty: 1, ...extra }));
}
async function confirmModifiers({ modifier_option_ids, note }) {
  const { variant } = modifierTarget.value;
  // 422 (aturan dilanggar) ditampilkan sebagai toast oleh penangan pusat; dialog tetap terbuka.
  const ok = await run(() => addTabLine(tabId.value, { variant_id: variant.id, qty: 1, modifier_option_ids, ...(note ? { note } : {}) }));
  if (ok) modifierTarget.value = null;
}

// --- baris -------------------------------------------------------------------
async function changeQty(line, qty) {
  if (qty < 1) return removeLine(line);
  await run(() => updateTabLine(tabId.value, line.id, { qty }));
}
async function removeLine(line) {
  await run(() => removeTabLine(tabId.value, line.id));
}
async function saveNote(line, note) {
  await run(() => updateTabLine(tabId.value, line.id, { note: note === '' ? null : note }));
}

// --- batalkan item yang sudah dikirim / batalkan tab --------------------------------
const cancelTarget = ref(null);
const voidOpen = ref(false);
const voiding = ref(false);
const canVoid = computed(() => isOpen.value && isManager.value && Number(tab.value?.paid_amount ?? 0) === 0);

async function confirmCancel(reason) {
  // 403 (item sudah dimasak, bukan owner/admin) dan 409 sudah ditoast global; dialog tetap terbuka agar bisa dikoreksi.
  const ok = await run(() => removeTabLine(tabId.value, cancelTarget.value.id, reason));
  if (ok) {
    toast.success(t('floor.tab.cancelled'));
    cancelTarget.value = null;
  }
}

async function confirmVoid(reason) {
  mutationSeq += 1;
  voiding.value = true;
  try {
    await voidTab(tabId.value, reason);
    voidOpen.value = false;
    toast.success(t('floor.tab.voided'));
    router.push({ name: 'floor' });
  } catch {
    // 409 (sudah ada pembayaran) / 403 sudah ditoast global.
  } finally {
    voiding.value = false;
  }
}

// --- kirim ke dapur / antar ---------------------------------------------------
const unsentCount = computed(() => (tab.value?.lines ?? []).filter((l) => !l.sent).length);
const sending = ref(false);

async function sendToKitchen() {
  mutationSeq += 1;
  sending.value = true;
  try {
    const res = await sendTab(tabId.value);
    floor.applyTab(res.tab);
    toast.success(t('floor.tab.sent_ok', { n: res.tickets.length }));
  } catch {
    // 409 (tidak ada yang baru / tab tidak terbuka) sudah ditoast global.
  } finally {
    sending.value = false;
  }
}

async function serveLine(line) {
  await run(async () => {
    await setTicketItemStatus(line.ticket_item_id, 'served');
    return getTab(tabId.value);
  });
}

// --- pindah / gabung / pisah tagihan --------------------------------------------
const showMove = ref(false);
const showMerge = ref(false);
const showSplit = ref(false);
const arranging = ref(false); // satu aksi susun-ulang sedang berjalan
const splitRef = ref(null);
const splitReceiptId = ref(null);

async function applyArrange(action, done) {
  mutationSeq += 1;
  arranging.value = true;
  try {
    floor.applyTab(await action());
    done?.();
  } catch {
    // 409 (meja baru terisi, shift berbeda, sumber sudah ada pembayaran, …) sudah ditoast global.
  } finally {
    arranging.value = false;
  }
}

const doMove = (tableId) => applyArrange(() => moveTab(tabId.value, tableId), () => { showMove.value = false; toast.success(t('floor.tab.moved')); });
const doMerge = (sourceId) => applyArrange(() => mergeTab(tabId.value, sourceId), () => { showMerge.value = false; toast.success(t('floor.tab.merged')); });

function openSplit() {
  // client_ref baru per percobaan: kirim ulang (jaringan putus) dengan referensi SAMA tidak memisah dua kali.
  splitRef.value = uuid();
  showSplit.value = true;
}

async function doSplit({ items, payments }) {
  mutationSeq += 1;
  arranging.value = true;
  try {
    const res = await splitTab(tabId.value, { client_ref: splitRef.value, items, payments });
    floor.applyTab(res.tab);
    showSplit.value = false;
    splitReceiptId.value = res.paid_order.id; // struk penjualan terpisah
    toast.success(t('floor.tab.split_done'));
  } catch {
    // 409 sudah ditoast global; dialog tetap terbuka agar bisa dikoreksi / dikirim ulang dengan referensi yang sama.
  } finally {
    arranging.value = false;
  }
}

function afterSplitReceipt() {
  splitReceiptId.value = null;
  // Semua baris habis dipisah → tab ditutup server; tidak ada lagi yang bisa dilakukan di sini.
  if (tab.value?.status === 'voided') router.push({ name: 'floor' });
}

// --- bayar -------------------------------------------------------------------
const showPayment = ref(false);
const paying = ref(false);
const payRef = ref(null);
const showReceipt = ref(false);

function openPayment() {
  // client_ref baru untuk setiap percobaan pembayaran: pengiriman ulang (jaringan putus)
  // dengan referensi yang SAMA tidak akan mencatat pembayaran dua kali.
  payRef.value = uuid();
  showPayment.value = true;
}

async function submitPayment(payments) {
  paying.value = true;
  try {
    const updated = await payTab(tabId.value, { client_ref: payRef.value, payments });
    floor.applyTab(updated);
    showPayment.value = false;
    if (updated.status === 'completed') showReceipt.value = true;
    else toast.success(t('floor.tab.paid_partial', { amount: formatIDR(updated.balance) }));
  } catch {
    // 409 (shift tutup, tab tidak terbuka, kembalian tak valid) sudah ditoast global.
  } finally {
    paying.value = false;
  }
}

function afterReceipt() {
  showReceipt.value = false;
  router.push({ name: 'floor' });
}

// --- tutup tab kosong ----------------------------------------------------------
const showCloseEmpty = ref(false);
const closing = ref(false);
async function performCloseEmpty() {
  closing.value = true;
  try {
    await closeEmptyTab(tabId.value);
    toast.success(t('floor.tab.closed_empty'));
    router.push({ name: 'floor' });
  } catch {
    showCloseEmpty.value = false;
  } finally {
    closing.value = false;
  }
}
</script>

<template>
  <div class="px-[26px] pb-10 pt-5">
    <EmptyState v-if="loadFailed" icon="ph-warning" :message="t('floor.tab.load_failed')" />

    <div v-else-if="tab" class="grid grid-cols-1 gap-5 lg:grid-cols-[1.4fr_1fr]">
      <!-- Menu -->
      <section class="flex flex-col gap-3.5" :aria-label="t('floor.tab.menu')">
        <div class="flex flex-wrap items-end gap-2.5">
          <div class="relative flex min-w-[220px] flex-1 items-center">
            <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[16px] text-muted-3" aria-hidden="true"></i>
            <label class="sr-only" for="tab-menu-search">{{ t('floor.tab.search_menu') }}</label>
            <input
              id="tab-menu-search"
              v-model="search"
              :placeholder="t('floor.tab.search_menu')"
              :disabled="!isOpen"
              class="h-[42px] w-full rounded-lg border border-line bg-white pl-[38px] pr-3.5 text-[13.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100 disabled:opacity-60"
              @input="debouncedLoadMenu"
            />
          </div>
          <div class="w-[200px]">
            <BaseSelect v-model="categoryId" :placeholder="t('floor.tab.all_categories')" :options="categoryOptions" :disabled="!isOpen" @update:model-value="loadMenu" />
          </div>
        </div>

        <p v-if="!loadingMenu && !cards.length" class="text-[13px] text-muted-3">{{ t('floor.tab.no_menu') }}</p>
        <div class="grid grid-cols-[repeat(auto-fill,minmax(160px,1fr))] gap-3">
          <button
            v-for="card in cards"
            :key="card.product_id"
            type="button"
            class="flex flex-col gap-1.5 rounded-card border border-line bg-white p-3 text-left transition-colors hover:border-brand disabled:cursor-not-allowed disabled:opacity-50"
            :disabled="!isOpen || busy || isUnavailable(card)"
            :data-testid="`menu-${card.product_id}`"
            @click="onPickCard(card)"
          >
            <img v-if="card.image_url" :src="card.image_url" alt="" class="h-20 w-full rounded-md object-cover" />
            <span class="text-[13.5px] font-bold leading-snug text-ink">{{ card.name }}</span>
            <span class="text-[12.5px] font-semibold text-brand-active">
              {{ card.min_price === card.max_price ? formatIDR(card.min_price) : `${formatIDR(card.min_price)} – ${formatIDR(card.max_price)}` }}
            </span>
            <StatusPill v-if="isUnavailable(card)" variant="danger">{{ t('floor.tab.sold_out') }}</StatusPill>
          </button>
        </div>
      </section>

      <!-- Tab -->
      <aside class="flex flex-col gap-3.5 rounded-card border border-line-2 bg-white p-[18px]" :aria-label="t('floor.tab.title', { number: tab.order_number })">
        <header class="flex items-start justify-between gap-2.5">
          <div>
            <h1 class="text-[18px] font-extrabold text-ink">{{ placeLabel }}</h1>
            <div class="text-[12px] font-semibold text-muted-3">{{ tab.order_number }}</div>
          </div>
          <StatusPill :variant="isOpen ? 'warn' : 'mint'">{{ isOpen ? t('floor.occupied') : t('floor.tab.completed') }}</StatusPill>
        </header>

        <EmptyState v-if="!tab.lines.length" icon="ph-fork-knife" :message="t('floor.tab.lines_empty')" />
        <TabLinesList
          v-else
          :lines="tab.lines"
          :editable="isOpen"
          :busy="busy"
          :can-serve="canServe"
          :can-cancel-after-prep="isManager"
          @serve="serveLine"
          @cancel="(line) => (cancelTarget = line)"
          @change-qty="changeQty"
          @remove="removeLine"
          @save-note="saveNote"
        />

        <dl class="flex flex-col gap-1.5 border-t border-dashed border-line-2 pt-3 text-[13px]">
          <div class="flex justify-between"><dt class="text-muted">{{ t('floor.tab.subtotal') }}</dt><dd class="font-semibold">{{ formatIDR(tab.subtotal) }}</dd></div>
          <div v-if="Number(tab.discount_amount) > 0" class="flex justify-between"><dt class="text-muted">{{ t('floor.tab.discount') }}</dt><dd class="font-semibold text-danger-text">-{{ formatIDR(tab.discount_amount) }}</dd></div>
          <div class="flex items-baseline justify-between"><dt class="font-bold">{{ t('floor.tab.total') }}</dt><dd class="text-[22px] font-extrabold tracking-tight" data-testid="tab-total">{{ formatIDR(tab.total_amount) }}</dd></div>
          <div v-if="Number(tab.paid_amount) > 0" class="flex justify-between"><dt class="text-muted">{{ t('floor.tab.paid') }}</dt><dd class="font-semibold">{{ formatIDR(tab.paid_amount) }}</dd></div>
          <div v-if="isOpen && Number(tab.paid_amount) > 0" class="flex justify-between"><dt class="font-bold">{{ t('floor.tab.balance') }}</dt><dd class="font-extrabold text-brand-active" data-testid="tab-balance">{{ formatIDR(tab.balance) }}</dd></div>
        </dl>

        <div class="flex flex-col gap-2">
          <BaseButton v-if="isOpen && unsentCount" variant="dark" size="lg" class="w-full" :loading="sending" @click="sendToKitchen">
            <i class="ph-duotone ph-paper-plane-tilt text-[17px]" aria-hidden="true"></i>
            {{ t('floor.tab.send_to_kitchen', { n: unsentCount }) }}
          </BaseButton>
          <BaseButton v-if="isOpen" size="lg" class="w-full" :disabled="!tab.lines.length" @click="openPayment">{{ t('floor.tab.pay') }}</BaseButton>
          <div v-if="isOpen" class="grid grid-cols-3 gap-2">
            <BaseButton variant="secondary" size="sm" :disabled="arranging" @click="showMove = true">{{ t('floor.tab.move') }}</BaseButton>
            <BaseButton variant="secondary" size="sm" :disabled="arranging" @click="showMerge = true">{{ t('floor.tab.merge') }}</BaseButton>
            <BaseButton variant="secondary" size="sm" :disabled="arranging || !tab.lines.length" @click="openSplit">{{ t('floor.tab.split') }}</BaseButton>
          </div>
          <BaseButton v-if="isOpen && !tab.lines.length" variant="secondary" class="w-full" @click="showCloseEmpty = true">{{ t('floor.tab.close_empty') }}</BaseButton>
          <BaseButton v-if="canVoid && tab.lines.length" variant="danger" size="sm" class="w-full" @click="voidOpen = true">{{ t('floor.tab.void_tab') }}</BaseButton>
          <template v-if="!isOpen">
            <p class="text-[13px] font-semibold text-brand-active">{{ t('floor.tab.completed_banner') }}</p>
            <BaseButton variant="secondary" class="w-full" @click="showReceipt = true">{{ t('floor.tab.view_receipt') }}</BaseButton>
          </template>
          <BaseButton variant="plain" class="w-full" @click="router.push({ name: 'floor' })">{{ t('floor.tab.back_to_floor') }}</BaseButton>
        </div>
      </aside>
    </div>

    <ProductVariantPickerModal :open="!!pickerCard" :product="pickerCard" @close="pickerCard = null" @select="onPickVariant" />
    <ModifierPickerModal
      :open="!!modifierTarget"
      :item-name="modifierTarget ? (modifierTarget.card.variant_count > 1 ? `${modifierTarget.card.name} — ${modifierTarget.variant.variant_name}` : modifierTarget.card.name) : ''"
      :base-price="modifierTarget?.variant.sell_price ?? '0'"
      :groups="modifierTarget?.groups ?? []"
      :submitting="busy"
      @close="modifierTarget = null"
      @confirm="confirmModifiers"
    />
    <TabPaymentModal :open="showPayment" :tab="tab" :submitting="paying" @close="showPayment = false" @submit="submitPayment" />
    <MoveTabModal :open="showMove" :current-table-id="tab?.table?.id ?? null" :submitting="arranging" @close="showMove = false" @move="doMove" />
    <MergeTabModal :open="showMerge" :tab="tab" :submitting="arranging" @close="showMerge = false" @merge="doMerge" />
    <SplitBillModal :open="showSplit" :tab="tab" :submitting="arranging" @close="showSplit = false" @submit="doSplit" />
    <ReasonDialog
      :open="!!cancelTarget"
      :title="t('floor.tab.cancel_item_title')"
      :message="cancelTarget ? t('floor.tab.cancel_item_message', { name: cancelTarget.name, status: t(`kitchen.status_${cancelTarget.prep_status ?? 'new'}`) }) : ''"
      :confirm-label="t('floor.tab.cancel_item_confirm')"
      @close="cancelTarget = null"
      @confirm="confirmCancel"
    />
    <ReasonDialog
      :open="voidOpen"
      :title="t('floor.tab.void_title')"
      :message="t('floor.tab.void_message')"
      :confirm-label="t('floor.tab.void_confirm')"
      :submitting="voiding"
      @close="voidOpen = false"
      @confirm="confirmVoid"
    />
    <ReceiptModal :open="splitReceiptId !== null" :order-id="splitReceiptId" :close-label="t('floor.tab.back_to_tab')" @close="afterSplitReceipt" />
    <ReceiptModal :open="showReceipt" :order-id="tab?.id ?? null" :close-label="t('floor.tab.back_to_floor')" @close="afterReceipt" />
    <ConfirmDialog
      :open="showCloseEmpty"
      :title="t('floor.tab.close_empty')"
      :message="t('floor.tab.close_empty_confirm')"
      :confirm-label="t('floor.tab.close_empty')"
      :loading="closing"
      @close="showCloseEmpty = false"
      @confirm="performCloseEmpty"
    />
  </div>
</template>
