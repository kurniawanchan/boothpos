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

const cart = usePosCartStore();
const toast = useToastStore();
const { t } = useI18n();

const session = ref(null);
const sessionChecked = ref(false);
const categories = ref([]);
const artists = ref([]);
const selectedCategoryId = ref(null);
const selectedArtistId = ref(null);
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
      ...(selectedCategoryId.value ? { category_id: selectedCategoryId.value } : {}),
      ...(selectedArtistId.value ? { artist_id: selectedArtistId.value } : {}),
      is_active: true,
      with_variants: 1,
      per_page: 100,
    });
    browsedProducts.value = res.data;
  } finally {
    loadingGrid.value = false;
  }
}
watch(selectedCategoryId, loadBrowse);
watch(selectedArtistId, loadBrowse);

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

async function handlePaymentSubmit(payment) {
  submittingOrder.value = true;
  try {
    const order = await createOrder({
      session_id: session.value.id,
      customer_id: selectedCustomer.value?.id ?? null,
      local_ref: localRef.value,
      discount_amount: toMoneyString(discount.value),
      items: cart.items.map((i) => ({ variant_id: i.variant_id, qty: i.qty })),
      payments: [payment],
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
      </div>

      <!-- 004-sidebar-menu-reorg (US5, FR-007) — new artist chip row,
           identical pattern to the pre-existing category row below. -->
      <div class="flex flex-wrap gap-1.5">
        <button
          type="button"
          class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
          :class="!selectedArtistId ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
          @click="selectedArtistId = null"
        >
          {{ t('pos.all') }}
        </button>
        <button
          v-for="a in artists"
          :key="a.id"
          type="button"
          class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
          :class="selectedArtistId === a.id ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
          @click="selectedArtistId = a.id"
        >
          {{ a.name }}
        </button>
      </div>
      <div class="flex flex-wrap gap-1.5">
        <button
          type="button"
          class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
          :class="!selectedCategoryId ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
          @click="selectedCategoryId = null"
        >
          {{ t('pos.all') }}
        </button>
        <button
          v-for="c in categories"
          :key="c.id"
          type="button"
          class="rounded-full border px-3.5 py-1.5 text-[12.5px] font-semibold transition-colors"
          :class="selectedCategoryId === c.id ? 'border-brand bg-mint-100 text-brand-active' : 'border-line bg-white text-muted-4 hover:border-brand'"
          @click="selectedCategoryId = c.id"
        >
          {{ c.name }}
        </button>
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
  </div>
</template>
