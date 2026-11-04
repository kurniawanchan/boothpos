<script setup>
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import PaymentProofThumbnail from '../payment/PaymentProofThumbnail.vue';
import { getOrder } from '../../api/orders';
import { formatIDR } from '../../utils/money';
import { paymentMethodLabel } from '../../utils/paymentMethods';

/**
 * 056 (US2) — dialog "Payment Proof" yang dibuka dari daftar Sales: menampilkan bukti pembayaran satu
 * penjualan TANPA membuka detail lengkapnya.
 *
 * Tak ada endpoint baru: penjualan dimuat lewat GET /orders/{id} yang sudah ada (pembayarannya sudah membawa
 * `proof_id` dan `can_view_proof` hasil hitungan SERVER), lalu hanya bukti non-tunai yang boleh dilihat yang
 * ditampilkan — komponen ini tak menebak izin dari peran. Gambar baru diunduh saat dialog dibuka (daftar tak
 * memuat satu pun). Berkas yang sudah diunduh thumbnail dipakai lagi oleh galeri ukuran penuh (tanpa unduhan
 * kedua); blob URL dilepas thumbnail saat dialog ditutup. Jawaban untuk penjualan yang sudah berganti dibuang.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  orderId: { type: [Number, String, null], default: null },
});
const emit = defineEmits(['close']);
const { t } = useI18n();

const order = ref(null);
const loading = ref(false);
const loadError = ref(false);
const urls = ref({}); // proof_id → blob URL yang sudah dimuat thumbnail
const lightboxOpen = ref(false);
const lightboxIndex = ref(0);
let seq = 0;

const proofs = computed(() =>
  (order.value?.payments ?? [])
    .filter((p) => p.method !== 'cash' && p.proof_id && p.can_view_proof)
    .map((p) => ({
      proofId: p.proof_id,
      paymentId: p.id,
      label: `${paymentMethodLabel(t, p.method)}${p.provider ? ` · ${p.provider}` : ''}`,
      amount: formatIDR(p.amount),
      alt: t('payment_proof.thumb_alt', { method: paymentMethodLabel(t, p.method), amount: formatIDR(p.amount) }),
    })),
);

const gallery = computed(() => proofs.value.filter((p) => urls.value[p.proofId]).map((p) => ({ proofId: p.proofId, src: urls.value[p.proofId], alt: p.alt })));

async function load() {
  if (!props.open || props.orderId === null) return;
  seq += 1;
  const mine = seq;
  order.value = null;
  urls.value = {};
  lightboxOpen.value = false;
  loading.value = true;
  loadError.value = false;
  try {
    const result = await getOrder(props.orderId);
    if (mine !== seq) return;
    order.value = result;
  } catch {
    if (mine === seq) loadError.value = true;
  } finally {
    if (mine === seq) loading.value = false;
  }
}

function openProof(proofId) {
  const i = gallery.value.findIndex((g) => g.proofId === proofId);
  if (i < 0) return;
  lightboxIndex.value = i;
  lightboxOpen.value = true;
}

watch(() => [props.open, props.orderId], load, { immediate: true });
</script>

<template>
  <BaseModal :open="open" :title="t('payment_proof.title')" max-width-class="max-w-[520px]" @close="emit('close')">
    <div class="flex flex-col gap-4 px-6 py-5">
      <p v-if="loading" class="text-[13px] text-muted-3">{{ t('payment_proof.loading') }}</p>

      <div v-else-if="loadError" class="flex flex-col items-start gap-2 text-[13px]">
        <span class="text-danger-text">{{ t('payment_proof.load_failed') }}</span>
        <BaseButton variant="secondary" size="sm" @click="load()">{{ t('common.retry') }}</BaseButton>
      </div>

      <template v-else-if="order">
        <p v-if="!proofs.length" class="text-[13px] text-muted-3">{{ t('payment_proof.none') }}</p>
        <ul v-else class="flex flex-col gap-3">
          <li v-for="p in proofs" :key="p.proofId" data-testid="payment-proof-item" class="flex items-center gap-3 rounded-lg border border-line-2 px-3 py-2.5">
            <PaymentProofThumbnail
              :proof-id="p.proofId"
              :alt="p.alt"
              :size="72"
              @loaded="(url) => (urls = { ...urls, [p.proofId]: url })"
              @open="openProof(p.proofId)"
            />
            <div class="flex min-w-0 flex-col gap-0.5">
              <span class="text-[12.5px] font-semibold">{{ p.label }}</span>
              <span class="whitespace-nowrap text-[13.5px] font-bold">{{ p.amount }}</span>
            </div>
          </li>
        </ul>
      </template>
    </div>

    <ImageLightbox :open="lightboxOpen" :images="gallery" v-model:index="lightboxIndex" @close="lightboxOpen = false" />
  </BaseModal>
</template>
