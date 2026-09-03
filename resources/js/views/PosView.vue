<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { currentSession } from '../api/sessions';
import { listCategories } from '../api/categories';
import { listArtists } from '../api/artists';
import { listProducts, lookupVariants } from '../api/products';
import { createOrder } from '../api/orders';
import { usePosCartStore } from '../stores/posCart';
import { useToastStore } from '../stores/toast';
import { uuid } from '../utils/uuid';
import { formatIDR, toMoneyString } from '../utils/money';
import { buildProductCards, toCartItem } from '../utils/posProductCards';
import { useDebouncedFn } from '../composables/useDebouncedFn';
import PosCartPanel from '../components/pos/PosCartPanel.vue';
import PosPaymentModal from '../components/payment/PosPaymentModal.vue';
import ProductVariantPickerModal from '../components/pos/ProductVariantPickerModal.vue';
import ReceiptModal from '../components/receipt/ReceiptModal.vue';
import CustomerPickerModal from '../components/forms/CustomerPickerModal.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import BaseMultiSelect from '../components/ui/BaseMultiSelect.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import PosDraftsPanel from '../components/pos/PosDraftsPanel.vue';
import { savePosDraft, resumePosDraft } from '../api/posDrafts';

const cart = usePosCartStore();
const toast = useToastStore();
const { t } = useI18n();

const session = ref(null);
const sessionChecked = ref(false);
const categories = ref([]);
const artists = ref([]);
// 005-ux-enhancements-dashboard (US1) — array (multi-select dropdown
// dengan pencarian), bukan lagi satu ID chip tunggal. Array kosong =
// "Semua" — lihat BaseMultiSelect.vue.
const selectedCategoryIds = ref([]);
const selectedArtistIds = ref([]);
const search = ref('');
const browsedProducts = ref([]);
const searchResults = ref(null);
const loadingGrid = ref(false);

const discount = ref(0);
const selectedCustomer = ref(null);
const showCustomerPicker = ref(false);
const showPayment = ref(false);
const showReceipt = ref(false);
const submittingOrder = ref(false);
const localRef = ref(null);
const lastOrderId = ref(null);
const paymentModalRef = ref(null);
const showVariantPicker = ref(false);
const variantPickerCard = ref(null);

onMounted(async () => {
  session.value = await currentSession();
  sessionChecked.value = true;
  const catRes = await listCategories({ per_page: 100 });
  categories.value = catRes.data;
  artists.value = (await listArtists({ per_page: 100 })).data;
  await loadBrowse();
});

const categoryCodeById = computed(() => Object.fromEntries(categories.value.map((c) => [c.id, c.code])));

async function loadBrowse() {
  loadingGrid.value = true;
  try {
    // ?with_variants=1 eager-loads every product's variants (including
    // inactive ones) in one extra query total — no more per-product N+1.
    const res = await listProducts({
      ...(selectedCategoryIds.value.length ? { category_id: selectedCategoryIds.value } : {}),
      ...(selectedArtistIds.value.length ? { artist_id: selectedArtistIds.value } : {}),
      is_active: true,
      with_variants: 1,
      per_page: 100,
    });
    browsedProducts.value = res.data;
  } finally {
    loadingGrid.value = false;
  }
}
watch(selectedCategoryIds, loadBrowse);
watch(selectedArtistIds, loadBrowse);

const artistOptions = computed(() => artists.value.map((a) => ({ value: a.id, label: a.name })));
const categoryOptions = computed(() => categories.value.map((c) => ({ value: c.id, label: c.name })));

const runSearch = useDebouncedFn(async () => {
  const term = search.value.trim();
  if (!term) {
    searchResults.value = null;
    return;
  }
  loadingGrid.value = true;
  try {
    // GET /variants/lookup — the cashier-facing search endpoint. It has no
    // category filter and returns no category info, so search results
    // fall back to a generic thumbnail (see cards computed below).
    const res = await lookupVariants(term, 40);
    searchResults.value = res.data;
  } finally {
    loadingGrid.value = false;
  }
}, 300);
watch(search, runSearch);

// One card per product (grouped) — picking a product with more than one
// active variant opens the variant picker; a single-variant product is
// added to the cart directly (see selectProductCard below).
const browseCards = computed(() => buildProductCards(browsedProducts.value, categoryCodeById.value));

const searchCards = computed(() =>
  (searchResults.value ?? []).map((v) => ({
    variant_id: v.variant_id,
    sku: v.sku,
    name: v.label,
    artist_name: v.artist_name,
    sell_price: v.sell_price,
    current_stock: v.current_stock,
    category_code: null,
  }))
);

// Search hits stay variant-granular (the cashier searched for a specific
// SKU/name), so they're never re-grouped by product — only the plain
// browse grid groups. `cards` here is just used to drive the shared
// empty-state check across both modes.
const cards = computed(() => (searchResults.value !== null ? searchCards.value : browseCards.value));

function addToCart(item) {
  if (item.current_stock <= 0) return;
  cart.add(item);
}

function selectProductCard(card) {
  if (card.out_of_stock) return;
  if (card.variant_count === 1) {
    // No pointless second click for a product that only has one variant.
    addToCart(toCartItem(card, card.variants[0]));
    return;
  }
  variantPickerCard.value = card;
  showVariantPicker.value = true;
}

function handleVariantPick(variant) {
  if (!variantPickerCard.value) return;
  addToCart(toCartItem(variantPickerCard.value, variant));
  showVariantPicker.value = false;
  variantPickerCard.value = null;
}

function closeVariantPicker() {
  showVariantPicker.value = false;
  variantPickerCard.value = null;
}

const canCheckout = computed(() => !!session.value);
const checkoutBlockedReason = computed(() => (session.value ? '' : t('pos.open_session_first')));

function openPayment() {
  if (!session.value) {
    toast.warning(t('pos.open_session_first_toast'));
    return;
  }
  // Regenerated per checkout attempt (not per session) — this is what
  // makes a retried POST /orders idempotent without double-charging stock.
  localRef.value = uuid();
  paymentModalRef.value?.reset();
  showPayment.value = true;
}

async function handlePaymentSubmit(payments) {
  // 006-purchase-order-and-ops (US2) — PaymentPanel.vue's checkout mode
  // now emits an ARRAY (one or more entries, split payment), not a single
  // object — POST /orders already accepted `payments[]` before this
  // change (research.md R2), so nothing else here needs to change.
  submittingOrder.value = true;
  try {
    const order = await createOrder({
      session_id: session.value.id,
      customer_id: selectedCustomer.value?.id ?? null,
      local_ref: localRef.value,
      discount_amount: toMoneyString(discount.value),
      items: cart.items.map((i) => ({ variant_id: i.variant_id, qty: i.qty })),
      payments,
    });
    lastOrderId.value = order.id;
    showPayment.value = false;
    showReceipt.value = true;
    cart.clear();
    discount.value = 0;
    selectedCustomer.value = null;
  } catch (err) {
    if (err.isValidation) {
      toast.error(Object.values(err.errors ?? {})[0]?.[0] ?? err.message);
    }
    // 409s (insufficient stock, closed session, reused local_ref) are
    // already surfaced by the shared axios interceptor.
  } finally {
    submittingOrder.value = false;
  }
}

function closeReceipt() {
  showReceipt.value = false;
  lastOrderId.value = null;
}

// --- POS drafts (006-purchase-order-and-ops, US4) -----------------------
// Save/resume never touches stock or payments — a draft is purely a saved
// cart snapshot, per spec FR-011/FR-013. Resuming REPLACES the active
// cart (a cashier is expected to finish or clear what they're doing
// before pulling up a draft), mirroring how "Save as draft" itself
// clears the cart it just saved.
const showDrafts = ref(false);
const savingDraft = ref(false);

async function saveDraft() {
  if (cart.isEmpty) return;
  savingDraft.value = true;
  try {
    await savePosDraft({
      customer_id: selectedCustomer.value?.id ?? null,
      discount_amount: discount.value || 0,
      items: cart.items.map((i) => ({ variant_id: i.variant_id, sku: i.sku, qty: i.qty, sell_price: i.sell_price })),
    });
    cart.clear();
    discount.value = 0;
    selectedCustomer.value = null;
    toast.success(t('pos.draft_saved'));
  } catch (err) {
    toast.error(err.message);
  } finally {
    savingDraft.value = false;
  }
}

async function resumeDraft(draftId) {
  try {
    const resumed = await resumePosDraft(draftId);
    cart.clear();
    resumed.items.forEach((item) => cart.add(item));
    // cart.add() only ever increments qty by 1 per call (its POS-click
    // contract) — force the snapshot's saved qty afterward instead of
    // calling add() qty times, which would be both slower and wrong if
    // stock changed since the draft was saved.
    resumed.items.forEach((item) => {
      const line = cart.items.find((i) => i.variant_id === item.variant_id);
      if (line) line.qty = item.qty;
    });
    discount.value = Number(resumed.discount_amount) || 0;
    if (resumed.warnings.length) {
      resumed.warnings.forEach((w) => toast.error(w));
    }
    showDrafts.value = false;
  } catch (err) {
    toast.error(err.message);
  }
}
</script>

<template>
  <div class="flex h-full">
    <div class="flex min-w-0 flex-1 flex-col gap-3.5 overflow-auto px-[22px] py-5">
      <div v-if="sessionChecked && !session" class="flex items-center gap-3 rounded-card border border-warn-border bg-warn-bg px-4 py-3.5 text-[13px] text-warn-text">
        <i class="ph-duotone ph-lock-key text-[19px]" aria-hidden="true"></i>
        {{ t('pos.no_session_open') }}
        <RouterLink :to="{ name: 'session' }" class="font-bold underline">{{ t('pos.open_here') }}</RouterLink>.
      </div>

      <div class="flex flex-wrap items-center gap-2.5">
        <div class="relative flex min-w-[240px] flex-1 items-center">
          <i class="ph-duotone ph-magnifying-glass pointer-events-none absolute left-3.5 text-[17px] text-muted-3" aria-hidden="true"></i>
          <label class="sr-only" for="pos-search">{{ t('pos.search_product_or_sku') }}</label>
          <input
            id="pos-search"
            v-model="search"
            :placeholder="t('pos.search_product_or_sku_placeholder')"
            class="h-[46px] w-full rounded-lg border border-line bg-white pl-10 pr-3.5 text-[14.5px] outline-none focus:border-brand focus:ring-[3px] focus:ring-mint-100"
          />
        </div>
        <BaseButton variant="secondary" :disabled="cart.isEmpty" :loading="savingDraft" @click="saveDraft">
          <i class="ph-duotone ph-note-pencil text-[16px]" aria-hidden="true"></i>
          {{ t('pos.save_as_draft') }}
        </BaseButton>
        <BaseButton variant="secondary" @click="showDrafts = true">
          <i class="ph-duotone ph-tray text-[16px]" aria-hidden="true"></i>
          {{ t('pos.drafts_title') }}
        </BaseButton>
      </div>

      <!-- 005-ux-enhancements-dashboard (US1) — dropdown multi-pilih dengan
           pencarian, menggantikan baris chip artist/category dari
           004-sidebar-menu-reorg (lihat research.md R2). -->
      <div class="flex flex-wrap gap-2.5">
        <BaseMultiSelect v-model="selectedArtistIds" :options="artistOptions" :all-label="t('master_data.all_artists')" class="w-52" />
        <BaseMultiSelect v-model="selectedCategoryIds" :options="categoryOptions" :all-label="t('master_data.all_categories')" class="w-52" />
      </div>

      <EmptyState v-if="!loadingGrid && cards.length === 0" icon="ph-package" :message="t('pos.no_matching_products')" />
      <div v-else class="grid grid-cols-[repeat(auto-fill,minmax(184px,1fr))] gap-3.5 pb-5">
        <!-- Search results stay variant-granular: the cashier searched
             for a specific SKU/name, so each hit adds straight to cart. -->
        <template v-if="searchResults !== null">
          <button
            v-for="card in searchCards"
            :key="card.variant_id"
            type="button"
            :disabled="card.current_stock <= 0"
            class="flex flex-col overflow-hidden rounded-card border border-line-2 bg-white text-left transition-colors hover:border-brand disabled:cursor-not-allowed disabled:opacity-60"
            @click="addToCart(card)"
          >
            <div class="relative flex h-[104px] items-center justify-center overflow-hidden bg-line-7 text-muted-4">
              <img v-if="card.image_url" :src="card.image_url" :alt="card.name" class="h-full w-full object-cover" />
              <span v-else-if="card.category_code" class="text-[22px] font-extrabold tracking-tight opacity-55">{{ card.category_code }}</span>
              <i v-else class="ph-duotone ph-package text-[30px] opacity-40" aria-hidden="true"></i>
              <span class="absolute bottom-1.5 left-2 rounded bg-white/85 px-1.5 py-0.5 font-mono text-[9.5px] text-muted-5">{{ card.sku }}</span>
              <span v-if="card.current_stock <= 0" class="absolute right-2 top-2 rounded-full bg-danger-bg px-2 py-0.5 text-[10px] font-bold text-danger-text">{{ t('pos.out_of_stock') }}</span>
            </div>
            <div class="flex flex-col gap-1 px-3 py-2.5">
              <span class="text-[13.5px] font-semibold leading-tight">{{ card.name }}</span>
              <span class="text-[11px] text-muted-3">{{ card.artist_name }}</span>
              <div class="mt-0.5 flex items-baseline justify-between gap-1.5">
                <span class="text-[14.5px] font-bold text-brand-active">{{ formatIDR(card.sell_price) }}</span>
                <span class="text-[11px] text-muted-3">{{ t('pos.stock_count', { count: card.current_stock }) }}</span>
              </div>
            </div>
          </button>
        </template>

        <!-- Browse grid: one card per product. A single-variant product
             adds straight to cart; anything with more than one variant
             opens the picker (see selectProductCard). -->
        <template v-else>
          <button
            v-for="card in browseCards"
            :key="card.product_id"
            type="button"
            :disabled="card.out_of_stock"
            class="flex flex-col overflow-hidden rounded-card border border-line-2 bg-white text-left transition-colors hover:border-brand disabled:cursor-not-allowed disabled:opacity-60"
            @click="selectProductCard(card)"
          >
            <div class="relative flex h-[104px] items-center justify-center overflow-hidden bg-line-7 text-muted-4">
              <img v-if="card.image_url" :src="card.image_url" :alt="card.name" class="h-full w-full object-cover" />
              <span v-else-if="card.category_code" class="text-[22px] font-extrabold tracking-tight opacity-55">{{ card.category_code }}</span>
              <i v-else class="ph-duotone ph-package text-[30px] opacity-40" aria-hidden="true"></i>
              <span v-if="card.variant_count > 1" class="absolute left-2 top-2 rounded-full bg-white/85 px-2 py-0.5 text-[10px] font-bold text-muted-5">{{ t('pos.variant_count', { count: card.variant_count }) }}</span>
              <span v-if="card.out_of_stock" class="absolute right-2 top-2 rounded-full bg-danger-bg px-2 py-0.5 text-[10px] font-bold text-danger-text">{{ t('pos.out_of_stock') }}</span>
            </div>
            <div class="flex flex-col gap-1 px-3 py-2.5">
              <span class="text-[13.5px] font-semibold leading-tight">{{ card.name }}</span>
              <span class="text-[11px] text-muted-3">{{ card.artist_name }}</span>
              <div class="mt-0.5 flex items-baseline justify-between gap-1.5">
                <span class="text-[14.5px] font-bold text-brand-active">
                  <template v-if="card.variant_count === 0">{{ t('pos.no_variants') }}</template>
                  <template v-else-if="card.min_price === card.max_price">{{ formatIDR(card.min_price) }}</template>
                  <template v-else>{{ formatIDR(card.min_price) }}–{{ formatIDR(card.max_price) }}</template>
                </span>
                <span class="text-[11px] text-muted-3">{{ t('pos.stock_count', { count: card.total_stock }) }}</span>
              </div>
            </div>
          </button>
        </template>
      </div>
    </div>

    <PosCartPanel
      :discount="discount"
      :customer-name="selectedCustomer?.name ?? ''"
      :can-checkout="canCheckout"
      :checkout-blocked-reason="checkoutBlockedReason"
      @update:discount="discount = $event"
      @pick-customer="showCustomerPicker = true"
      @pay="openPayment"
    />

    <CustomerPickerModal :open="showCustomerPicker" @close="showCustomerPicker = false" @select="(c) => (selectedCustomer = c)" />

    <PosPaymentModal
      ref="paymentModalRef"
      :open="showPayment"
      :lines="cart.items.map((i) => ({ key: i.variant_id, name: i.name, qty: i.qty, lineTotal: (parseFloat(i.sell_price) * i.qty).toFixed(2) }))"
      :subtotal="cart.subtotal"
      :discount-amount="discount.toFixed(2)"
      :total="Math.max(parseFloat(cart.subtotal) - discount, 0).toFixed(2)"
      :submitting="submittingOrder"
      @close="showPayment = false"
      @submit="handlePaymentSubmit"
    />

    <ReceiptModal :open="showReceipt" :order-id="lastOrderId" @close="closeReceipt" />

    <ProductVariantPickerModal :open="showVariantPicker" :product="variantPickerCard" @close="closeVariantPicker" @select="handleVariantPick" />
    <PosDraftsPanel :open="showDrafts" @close="showDrafts = false" @resume="resumeDraft" />
  </div>
</template>
