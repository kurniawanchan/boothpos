<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import ReferenceThumbnails from './ReferenceThumbnails.vue';
import PaymentProofThumbnail from '../payment/PaymentProofThumbnail.vue';
import ImageLightbox from '../ui/ImageLightbox.vue';
import { getOrder, getReference } from '../../api/orders';
import { createLimitedRunner } from '../../utils/limitedRunner';
import { formatIDR } from '../../utils/money';
import { paymentMethodLabel } from '../../utils/paymentMethods';

/**
 * 056 (US7) — isi sakelar "tampilkan semua" di daftar Sales: catatan + thumbnail Sale Reference dan thumbnail
 * Payment Proof untuk SATU baris, langsung di bawah nomor transaksinya.
 *
 * Tak ada data gambar di payload daftar (daftar tetap ramping dan tak membocorkan id bukti): komponen ini hanya
 * dipasang untuk baris yang MEMANG punya referensi / bukti (`has_reference`, `payment_proof_count`), dan memuat
 * lewat endpoint yang sudah ada — `GET /orders/{id}/reference` dan `GET /orders/{id}` (di mana `can_view_proof`
 * dihitung SERVER; bukti yang tak boleh dilihat tak pernah dirender). Pemuatan JSON antre lewat satu pembatas
 * bersama (maks. 4 sekaligus) dan baru berjalan saat baris terlihat (IntersectionObserver bila ada); gambarnya
 * diunduh komponen thumbnail yang sudah ada (referensi ≤ 3 sekaligus per baris).
 */
const props = defineProps({
  orderId: { type: [Number, String], required: true },
  hasReference: { type: Boolean, default: false },
  proofCount: { type: Number, default: 0 },
});
const { t } = useI18n();

// Satu pembatas untuk seluruh daftar (modul), bukan per baris.
const run = sharedRunner();
function sharedRunner() {
  if (!globalThis.__salesAttachmentsRunner) globalThis.__salesAttachmentsRunner = createLimitedRunner(4);
  return globalThis.__salesAttachmentsRunner;
}

const root = ref(null);
const visible = ref(false);
const reference = ref(null);
const order = ref(null);
const urls = ref({}); // proof_id → blob URL dari thumbnail
const lightboxOpen = ref(false);
const lightboxIndex = ref(0);
let observer = null;
let gone = false;

const proofs = computed(() =>
  (order.value?.payments ?? [])
    .filter((p) => p.method !== 'cash' && p.proof_id && p.can_view_proof)
    .map((p) => ({
      proofId: p.proof_id,
      alt: t('payment_proof.thumb_alt', { method: paymentMethodLabel(t, p.method), amount: formatIDR(p.amount) }),
      label: `${paymentMethodLabel(t, p.method)} ${formatIDR(p.amount)}`,
    })),
);
const gallery = computed(() => proofs.value.filter((p) => urls.value[p.proofId]).map((p) => ({ proofId: p.proofId, src: urls.value[p.proofId], alt: p.alt })));

function openProof(proofId) {
  const i = gallery.value.findIndex((g) => g.proofId === proofId);
  if (i < 0) return;
  lightboxIndex.value = i;
  lightboxOpen.value = true;
}

async function load() {
  const jobs = [];
  if (props.hasReference) {
    jobs.push(run(() => getReference(props.orderId)).then((r) => { if (!gone) reference.value = r; }).catch(() => {}));
  }
  if (props.proofCount > 0) {
    jobs.push(run(() => getOrder(props.orderId)).then((o) => { if (!gone) order.value = o; }).catch(() => {}));
  }
  await Promise.all(jobs);
}

watch(visible, (v) => { if (v) load(); });

onMounted(() => {
  if (typeof IntersectionObserver === 'undefined' || !root.value) {
    visible.value = true;
    return;
  }
  observer = new IntersectionObserver((entries) => {
    if (entries.some((e) => e.isIntersecting)) {
      visible.value = true;
      observer?.disconnect();
    }
  });
  observer.observe(root.value);
});
onBeforeUnmount(() => {
  gone = true;
  observer?.disconnect();
});
</script>

<template>
  <div ref="root" class="mt-1 flex max-w-[260px] flex-col gap-1.5 whitespace-normal" data-testid="row-attachments">
    <div v-if="hasReference" class="flex flex-col gap-1">
      <p v-if="reference?.note" class="line-clamp-3 whitespace-pre-line break-words text-[11.5px] text-muted-4">{{ reference.note }}</p>
      <ReferenceThumbnails v-if="reference?.images?.length" :order-id="orderId" :images="reference.images" />
    </div>
    <div v-if="proofs.length" class="flex flex-wrap gap-2">
      <PaymentProofThumbnail
        v-for="p in proofs"
        :key="p.proofId"
        :proof-id="p.proofId"
        :alt="p.alt"
        :size="48"
        @loaded="(url) => (urls = { ...urls, [p.proofId]: url })"
        @open="openProof(p.proofId)"
      />
    </div>
    <ImageLightbox :open="lightboxOpen" :images="gallery" v-model:index="lightboxIndex" @close="lightboxOpen = false" />
  </div>
</template>
