<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { getProduct, priceHistory, undoSoldOut, reactivateProduct } from '../api/products';
import { listOutlets } from '../api/outlets';
import { listCategories } from '../api/categories';
import { registerPageHeader } from '../composables/usePageHeader';
import { useToastStore } from '../stores/toast';
import StatusPill from '../components/ui/StatusPill.vue';
import BaseButton from '../components/ui/BaseButton.vue';
import ConfirmDialog from '../components/ui/ConfirmDialog.vue';
import ErrorState from '../components/ui/ErrorState.vue';
import LoadingState from '../components/ui/LoadingState.vue';
import ItemFacts from '../components/product/ItemFacts.vue';
import VariantsSection from '../components/product/VariantsSection.vue';
import ModifierSection from '../components/product/ModifierSection.vue';
import AvailabilitySection from '../components/product/AvailabilitySection.vue';
import RecipeSection from '../components/product/RecipeSection.vue';
import PriceHistoryTimeline from '../components/product/PriceHistoryTimeline.vue';
import SoldOutDialog from '../components/product/SoldOutDialog.vue';
import DiscontinueDialog from '../components/product/DiscontinueDialog.vue';
import VariantsDrawer from '../components/product/VariantsDrawer.vue';
import ModifierLinksDrawer from '../components/product/ModifierLinksDrawer.vue';
import ProductFormDrawer from './products/ProductFormDrawer.vue';

/**
 * 043 (US2–US4) — halaman satu item `/products/:id`. Alamat langsung dan muat ulang bekerja karena SELURUH keadaan
 * halaman berasal dari id di URL (tidak ada yang dititipkan lewat navigasi).
 *
 * Dua permintaan independen: inti (GET /products/{id}: kepala, fakta, varian, modifier, ketersediaan, resep, `can`) dan
 * riwayat harga (GET /products/{id}/price-history, berpaginasi). Riwayat yang gagal hanya mengganti BAGIANNYA dengan
 * galat + Coba lagi; inti yang gagal mengganti seluruh halaman karena tanpa inti judul/izin pun tak diketahui.
 *
 * Tombol tindakan dirender HANYA bila objek `can` dari server mengizinkan (hidden, bukan disabled) — halaman tak
 * menurunkan izin dari peran. Biaya modal tidak dibaca di sini: server menghilangkannya untuk peran tertentu.
 *
 * "Kembali ke Produk": bila halaman sebelumnya di riwayat navigasi adalah DAFTAR produk (`/products` + query-nya),
 * tombol memakai router.back() sehingga daftar kembali persis dengan tab/filter/halaman/urutannya (semua ada di
 * URL-nya); selain itu (alamat dibuka langsung, dari layar lain) menuju `/products` polos.
 */
const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const toast = useToastStore();
registerPageHeader(); // kepala halaman ini = kartu kepala sendiri; judul bawaan kerangka disembunyikan

const productId = computed(() => Number(route.params.id));

// --- inti ----------------------------------------------------------------------------------------------------
const product = ref(null);
const coreState = ref('loading'); // loading | ready | error
let coreSeq = 0;

async function loadCore() {
  const mine = ++coreSeq;
  // Muat ulang setelah tindakan: data lama tetap tampil (tanpa kedipan kerangka); kerangka hanya saat belum ada data.
  if (!product.value) coreState.value = 'loading';
  try {
    const res = await getProduct(productId.value);
    if (mine !== coreSeq) return;
    product.value = res;
    coreState.value = 'ready';
  } catch {
    if (mine !== coreSeq) return;
    if (!product.value) coreState.value = 'error';
    else toast.error(t('products.item_load_failed'));
  }
}

// --- riwayat harga ---------------------------------------------------------------------------------------------
const history = ref([]);
const historyMeta = ref({ current_page: 1, last_page: 1 });
const historyState = ref('loading');
const loadingMore = ref(false);
let historySeq = 0;

async function loadHistory({ more = false } = {}) {
  const mine = ++historySeq;
  const page = more ? historyMeta.value.current_page + 1 : 1;
  if (more) loadingMore.value = true;
  else historyState.value = 'loading';
  try {
    const res = await priceHistory(productId.value, { page });
    if (mine !== historySeq) return;
    history.value = more ? [...history.value, ...(res.data ?? [])] : res.data ?? [];
    historyMeta.value = res.meta ?? { current_page: page, last_page: page };
    historyState.value = 'ready';
  } catch {
    if (mine !== historySeq) return;
    // Gagal "muat lebih banyak": entri yang sudah ada tetap tampil dan tombolnya tetap bisa dicoba lagi.
    if (more) toast.error(t('products.history_more_failed'));
    else historyState.value = 'error';
  } finally {
    if (mine === historySeq) loadingMore.value = false;
  }
}

function reloadAll() {
  return Promise.all([loadCore(), loadHistory()]);
}

onMounted(reloadAll);
watch(productId, () => {
  product.value = null;
  history.value = [];
  pendingNotice.value = false;
  reloadAll();
});

// --- turunan tampilan --------------------------------------------------------------------------------------------
const can = computed(() => product.value?.can ?? {});
const status = computed(() => product.value?.status ?? 'active');
const STATUS = {
  active: { tone: 'success', key: 'products.status_active' },
  draft: { tone: 'warning', key: 'products.status_draft' },
  discontinued: { tone: 'neutral', key: 'products.status_discontinued' },
};
const statusView = computed(() => STATUS[status.value] ?? STATUS.active);
const availabilityMark = computed(() => (product.value?.availability ?? []).find((a) => a.state === 'sold_out') ?? null);
const soldOut = computed(() => !!availabilityMark.value);
const isDiscontinued = computed(() => status.value === 'discontinued');
const code = computed(() => product.value?.code ?? product.value?.code_prefix ?? '');
const multiVariant = computed(() => (product.value?.variants?.length ?? 0) > 1);

// --- kembali ---------------------------------------------------------------------------------------------------------
function previousIsList() {
  const back = router.options.history.state?.back;
  return typeof back === 'string' && /^\/products(\?|$)/.test(back);
}
const backHref = computed(() => router.resolve({ name: 'products' }).href);
function goBack() {
  if (previousIsList()) router.back();
  else router.push({ name: 'products' });
}

// --- ubah item (drawer yang sudah ada) ---------------------------------------------------------------------------
const drawer = ref(null);
const outlets = ref([]);
const categories = ref([]);
let referencesLoaded = false;

async function openEdit() {
  if (!referencesLoaded) {
    referencesLoaded = true;
    try {
      outlets.value = (await listOutlets({ per_page: 100 })).data;
      categories.value = (await listCategories({ per_page: 100 })).data;
    } catch {
      referencesLoaded = false; // coba lagi pada pembukaan berikutnya; drawer tetap terbuka dengan daftar yang ada
    }
  }
  await drawer.value?.openEdit({ id: productId.value });
}

// --- laci Varian & harga dan Grup modifier (043 US6/US7) ---------------------------------------------------------
// "Kelola varian" membuka VariantsDrawer (batch varian + harga lewat PriceService), BUKAN lagi formulir item panjang;
// "Edit item" tetap membuka ProductFormDrawer. Perubahan yang diantrekan rilis (pending / applied:false) tidak
// mengubah halaman, jadi catatan "menunggu rilis" ditampilkan seperti pada diskontinu.
const showVariants = ref(false);
const showModifiers = ref(false);

async function afterVariantsSaved(result) {
  showVariants.value = false;
  if (result?.pending?.length) pendingNotice.value = true;
  await Promise.all([loadCore(), loadHistory()]);
}

async function afterModifiersSaved(result) {
  showModifiers.value = false;
  if (result?.applied === false) pendingNotice.value = true;
  await loadCore();
}

// --- 86 / diskontinu / aktifkan lagi ------------------------------------------------------------------------------
const showSoldOut = ref(false);
const showUndo = ref(false);
const showDiscontinue = ref(false);
const showReactivate = ref(false);
const busy = ref(false);
// Dengan rilis menu aktif, diskontinu/aktifkan menjadi perubahan TERTUNDA: status item belum berubah, jadi lencana tidak
// dibalik; sebagai gantinya satu catatan tetap tampil sampai halaman dibuka untuk item lain.
const pendingNotice = ref(false);

async function afterSoldOut() {
  showSoldOut.value = false;
  toast.success(t('products.sold_out_done', { name: product.value.name }));
  await loadCore();
}

async function performUndo() {
  busy.value = true;
  try {
    // 045: per outlet — hanya tanda 86 outlet-outlet yang sedang habis yang dibatalkan (tanda global: tanpa outlet_ids).
    const marked = (product.value?.availability ?? []).filter((a) => a.state === 'sold_out' && a.outlet_id != null).map((a) => a.outlet_id);
    if (marked.length) await undoSoldOut(productId.value, marked);
    else await undoSoldOut(productId.value);
    showUndo.value = false;
    toast.success(t('products.undo_done', { name: product.value.name }));
    await loadCore();
  } catch {
    // 409 sudah di-toast handler pusat; tampilan dimuat ulang supaya sesuai kenyataan.
    showUndo.value = false;
    await loadCore();
  } finally {
    busy.value = false;
  }
}

async function afterLifecycle(result, doneKey) {
  showDiscontinue.value = false;
  showReactivate.value = false;
  if (result?.change) {
    pendingNotice.value = true;
    toast.success(t('products.change_pending'));
  } else {
    toast.success(t(doneKey, { name: product.value.name }));
  }
  await Promise.all([loadCore(), loadHistory()]);
}

async function performReactivate() {
  busy.value = true;
  try {
    await afterLifecycle(await reactivateProduct(productId.value), 'products.reactivate_done');
  } catch {
    showReactivate.value = false;
    await loadCore();
  } finally {
    busy.value = false;
  }
}

// 409 dari dialog = tampilan halaman basi (sudah 86 / sudah diskontinu): tutup dan ambil kebenaran terbaru.
async function onConflict() {
  showSoldOut.value = false;
  showDiscontinue.value = false;
  await loadCore();
}
</script>

<template>
  <div class="px-[26px] pb-10 pt-6">
    <a :href="backHref" class="mb-4 inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-muted-4 hover:text-brand-active" data-testid="back-link" @click.prevent="goBack">
      <i class="ph-duotone ph-arrow-left text-[14px]" aria-hidden="true"></i>
      {{ t('products.back_to_list') }}
    </a>

    <LoadingState v-if="coreState === 'loading'" :rows="8" />
    <div v-else-if="coreState === 'error'" class="border-2 border-line bg-white">
      <ErrorState :message="t('products.item_load_failed')" @retry="loadCore" />
    </div>

    <div v-else-if="product" class="flex flex-col gap-5">
      <header class="flex flex-col gap-4 border-2 border-line bg-white px-6 py-5" data-testid="item-header">
        <div class="flex flex-col gap-1.5">
          <p class="text-[11.5px] font-bold uppercase tracking-wider text-muted" data-testid="item-kicker">{{ t('products.item_kicker', { code }) }}</p>
          <h1 id="page-heading" tabindex="-1" class="text-[26px] font-bold leading-tight tracking-tight outline-none">{{ product.name }}</h1>
          <div class="mt-1 flex flex-wrap items-center gap-2" data-testid="item-badges">
            <StatusPill :variant="statusView.tone" data-testid="status-badge">{{ t(statusView.key) }}</StatusPill>
            <StatusPill v-if="soldOut" variant="danger" :title="availabilityMark.reason || undefined" data-testid="badge-86">
              <i class="ph-duotone ph-prohibit text-[12px]" aria-hidden="true"></i>
              {{ t('products.chip_sold_out') }}
            </StatusPill>
            <StatusPill v-if="product.category_name" variant="info">{{ product.category_name }}</StatusPill>
          </div>
        </div>

        <div v-if="can.edit || can.mark_86 || can.discontinue" class="flex flex-wrap items-center gap-2.5" data-testid="item-actions">
          <BaseButton v-if="can.edit" @click="openEdit">
            <i class="ph-duotone ph-pencil-simple text-[16px]" aria-hidden="true"></i>
            {{ t('products.action_edit') }}
          </BaseButton>
          <template v-if="can.mark_86 && !isDiscontinued">
            <BaseButton v-if="!soldOut" variant="secondary" @click="showSoldOut = true">
              <i class="ph-duotone ph-prohibit text-[16px]" aria-hidden="true"></i>
              {{ t('products.mark_86') }}
            </BaseButton>
            <BaseButton v-else variant="secondary" @click="showUndo = true">
              <i class="ph-duotone ph-arrow-counter-clockwise text-[16px]" aria-hidden="true"></i>
              {{ t('products.undo_86') }}
            </BaseButton>
          </template>
          <template v-if="can.discontinue">
            <BaseButton v-if="!isDiscontinued" variant="danger" @click="showDiscontinue = true">{{ t('products.discontinue') }}</BaseButton>
            <BaseButton v-else variant="secondary" @click="showReactivate = true">{{ t('products.reactivate') }}</BaseButton>
          </template>
        </div>

        <p v-if="pendingNotice" class="flex items-center gap-2 border-2 border-warn-border bg-warn-bg px-3.5 py-2 text-[12.5px] font-semibold text-warn-text" role="status" data-testid="pending-notice">
          <i class="ph-duotone ph-hourglass-medium text-[16px]" aria-hidden="true"></i>
          {{ t('products.change_pending') }}
        </p>
      </header>

      <ItemFacts :product="product" />
      <VariantsSection :variants="product.variants ?? []" :can-edit="!!can.edit" :outlet-prices="product.outlet_prices ?? null" :release-on="!!product.release" @manage="showVariants = true" @changed="loadCore" />
      <ModifierSection :links="product.modifier_links ?? []" :can-edit="!!can.edit" @manage="showModifiers = true" />
      <AvailabilitySection :availability="product.availability ?? []" />
      <RecipeSection :product-id="product.id" :product-name="product.name" :recipe="product.recipe ?? null" :variants="product.variants ?? []" @changed="loadCore" />
      <PriceHistoryTimeline
        :entries="history"
        :state="historyState"
        :has-more="historyMeta.current_page < historyMeta.last_page"
        :loading-more="loadingMore"
        :multi-variant="multiVariant"
        @retry="loadHistory()"
        @more="loadHistory({ more: true })"
      />
    </div>

    <SoldOutDialog :open="showSoldOut" :product="product" @close="showSoldOut = false" @done="afterSoldOut" @conflict="onConflict" />
    <DiscontinueDialog :open="showDiscontinue" :product="product" @close="showDiscontinue = false" @done="afterLifecycle($event, 'products.discontinue_done')" @conflict="onConflict" />
    <ConfirmDialog
      :open="showUndo"
      :title="t('products.undo_title', { name: product?.name ?? '' })"
      :message="t('products.undo_message')"
      :confirm-label="t('products.undo_86')"
      :loading="busy"
      @close="showUndo = false"
      @confirm="performUndo"
    />
    <ConfirmDialog
      :open="showReactivate"
      :title="t('products.reactivate_title', { name: product?.name ?? '' })"
      :message="t('products.reactivate_message')"
      :confirm-label="t('products.reactivate')"
      :loading="busy"
      @close="showReactivate = false"
      @confirm="performReactivate"
    />

    <VariantsDrawer :open="showVariants" :product="product" @close="showVariants = false" @saved="afterVariantsSaved" />
    <ModifierLinksDrawer :open="showModifiers" :product="product" :links="product?.modifier_links ?? []" :excluded="product?.modifier_excluded ?? []" @close="showModifiers = false" @saved="afterModifiersSaved" />
    <ProductFormDrawer ref="drawer" :outlets="outlets" :categories="categories" @saved="reloadAll" />
  </div>
</template>
