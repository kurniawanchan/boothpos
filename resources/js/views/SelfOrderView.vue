<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import BaseButton from '../components/ui/BaseButton.vue';
import SelfCartSheet from '../components/selfservice/SelfCartSheet.vue';
import SelfMenuGrid from '../components/selfservice/SelfMenuGrid.vue';
import SelfPaymentStep from '../components/selfservice/SelfPaymentStep.vue';
import SelfVariantSheet from '../components/selfservice/SelfVariantSheet.vue';
import { i18n, loadLocale } from '../i18n';
import { useSelfServiceStore } from '../stores/selfService';
import { formatIDR } from '../utils/money';
import { makeLine, needsPicker } from '../utils/selfServiceCart';

// 090 / US1 — halaman PUBLIK pesan mandiri (mobile-first). Pelanggan memindai QR meja → menu → keranjang → bayar QRIS +
// foto bukti → kirim. Semua teks Indonesia (FR-002). Outlet dan meja datang dari token QR di path, tidak pernah dari input.
const route = useRoute();
const router = useRouter();
const store = useSelfServiceStore();
const { t } = useI18n();

const step = ref('menu'); // menu | pay
const pickCard = ref(null);
const showVariant = ref(false);
const showCart = ref(false);

const outletName = computed(() => store.context?.outlet?.name ?? '');
const tableName = computed(() => store.context?.table?.name ?? '');
const fatalText = computed(() => {
  const kind = store.fatal?.kind;
  if (kind === 'invalid') return t('self_service.error_invalid');
  if (kind === 'table') return t('self_service.error_table');
  if (kind === 'locked') return t('self_service.error_locked');
  if (kind === 'network') return t('self_service.error_network');
  return store.fatal?.message || t('self_service.error_generic');
});

onMounted(async () => {
  // Halaman pelanggan SELALU Bahasa Indonesia, apa pun preferensi staf yang mungkin tersimpan di peramban yang sama.
  await loadLocale('id');
  i18n.global.locale.value = 'id';
  await store.init(String(route.params.token));
});

watch([outletName, tableName], () => {
  if (outletName.value) document.title = `${outletName.value} – ${t('self_service.title')}`;
});

// Pencarian ditunda 300 ms agar tidak menembak server di tiap ketukan; ganti kategori langsung.
let timer = null;
watch(() => store.query, () => {
  clearTimeout(timer);
  timer = setTimeout(() => store.loadMenu().catch(() => {}), 300);
});
watch(() => store.categoryId, () => store.loadMenu().catch(() => {}));
onBeforeUnmount(() => clearTimeout(timer));

function onPick(card) {
  if (card.sold_out) return;
  if (needsPicker(card)) {
    pickCard.value = card;
    showVariant.value = true;
    return;
  }
  store.addLine(makeLine({ card, variant: card.variants[0] }));
}

function goPay() {
  showCart.value = false;
  step.value = 'pay';
  window.scrollTo({ top: 0 });
}

async function onSubmit() {
  const order = await store.submit({ proofRequired: t('self_service.proof_required') });
  if (order) {
    // FR-011 — setelah kirim, pelanggan langsung melihat struknya (replace: tombol kembali tidak membuka keranjang kosong).
    router.replace({ name: 'self-service-receipt', params: { receiptToken: order.receipt_token } });
  } else if (store.unavailable.length) {
    step.value = 'menu';
    showCart.value = true; // item bermasalah ditandai di keranjang; pelanggan memperbaikinya tanpa mengisi ulang
  }
}

const onProof = ({ file, via }) => store.attachProof(file, via, { unsupported: t('self_service.proof_unsupported') });
</script>

<template>
  <div class="min-h-screen bg-canvas pb-28">
    <div class="mx-auto flex min-h-screen w-full max-w-xl flex-col bg-surface">
      <header class="sticky top-0 z-30 flex items-center justify-between gap-3 border-b border-line bg-surface-soft px-4 py-3">
        <div class="flex min-w-0 items-center gap-3">
          <img v-if="store.context?.outlet?.logo_url" :src="store.context.outlet.logo_url" alt="" class="h-9 w-9 shrink-0 object-contain" />
          <div class="min-w-0">
            <p class="truncate text-[15px] font-extrabold text-ink" data-testid="outlet-name">{{ outletName || t('self_service.title') }}</p>
            <p class="text-[11.5px] font-semibold text-muted">{{ t('self_service.title') }}</p>
          </div>
        </div>
        <span v-if="tableName" class="shrink-0 bg-brand px-3 py-1.5 text-[13px] font-extrabold text-white" data-testid="table-chip">
          {{ t('self_service.table', { name: tableName }) }}
        </span>
      </header>

      <main class="flex-1 px-4 py-4">
        <p v-if="store.status === 'loading' || store.status === 'idle'" class="py-16 text-center text-[13px] text-muted" role="status">{{ t('self_service.loading') }}</p>

        <div v-else-if="store.status === 'error'" class="flex flex-col items-center gap-4 py-16 text-center" role="alert" data-testid="fatal">
          <i class="ph-duotone ph-warning-circle text-[40px] text-warn-text" aria-hidden="true"></i>
          <p class="max-w-xs text-[14px] font-semibold text-ink">{{ fatalText }}</p>
          <BaseButton v-if="store.fatal?.kind === 'network'" variant="secondary" @click="store.init(String(route.params.token))">{{ t('self_service.retry') }}</BaseButton>
        </div>

        <p v-else-if="!store.available" class="bg-warn-bg px-3 py-3 text-[13.5px] font-semibold text-warn-text" role="alert" data-testid="unavailable">
          {{ t('self_service.ordering_unavailable') }}
        </p>

        <SelfPaymentStep
          v-else-if="step === 'pay'"
          :payment="store.context.payment"
          :totals="store.totals"
          :proof="store.proof"
          :proof-busy="store.proofBusy"
          :proof-error="store.proofError"
          :submitting="store.submitting"
          :submit-error="store.submitError"
          :unavailable="store.unavailable"
          v-model:customer="store.customer"
          :customer-errors="store.customerErrors"
          @back="step = 'menu'"
          @pick-proof="onProof"
          @submit="onSubmit"
        />

        <SelfMenuGrid
          v-else
          v-model:query="store.query"
          v-model:category-id="store.categoryId"
          :cards="store.cards"
          :categories="store.categories"
          :loading="store.menuLoading"
          @pick="onPick"
        />
      </main>

      <div
        v-if="store.status === 'ready' && step === 'menu' && store.lines.length"
        class="fixed inset-x-0 bottom-0 z-40 mx-auto w-full max-w-xl border-t border-line bg-surface-soft px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
      >
        <BaseButton class="w-full justify-between" size="lg" data-testid="cart-bar" @click="showCart = true">
          <span>{{ t('self_service.view_cart') }} · {{ t('self_service.cart_bar_items', { n: store.totals.count }) }}</span>
          <span>{{ formatIDR(store.totals.total) }}</span>
        </BaseButton>
      </div>
    </div>

    <SelfVariantSheet :open="showVariant" :card="pickCard" @close="showVariant = false" @add="store.addLine($event)" />
    <SelfCartSheet
      :open="showCart"
      :lines="store.lines"
      :totals="store.totals"
      :service-type="store.serviceType"
      :tax="store.context?.tax"
      :unavailable="store.unavailable"
      @close="showCart = false"
      @qty="store.changeQty"
      @remove="store.dropLine"
      @service-type="store.serviceType = $event"
      @continue="goPay"
    />
  </div>
</template>
