<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import BaseModal from '../ui/BaseModal.vue';
import PaymentPanel from './PaymentPanel.vue';
import { useToastStore } from '../../stores/toast';
import { formatIDR } from '../../utils/money';

/**
 * 028-partial-split-payment — dialog "Tambah pembayaran" untuk pre-order DAN
 * penjualan POS. Menggantikan RecordPaymentModal, yang menahan entri di klien
 * sampai jumlahnya menutup tagihan lalu mengirimnya berurutan: sekarang SATU
 * klik menyimpan SATU pembayaran, langsung, berapa pun jumlahnya (≤ sisa).
 *
 * Komponen ini tak tahu soal target transaksi — pemanggil menyuplai `submitFn`
 * (mis. createPreorderPayment / addOrderPayment) yang menerima payload
 * pembayaran dan mengembalikan transaksi terbaru. `remaining` datang dari
 * ringkasan server; jumlah bawaan = sisa tagihan, jadi melunasi hanya satu klik.
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  remaining: { type: String, required: true }, // Money string
  title: { type: String, default: '' },
  purpose: { type: String, default: 'full' }, // full | down_payment | settlement
  submitFn: { type: Function, required: true },
});
const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const toast = useToastStore();
const saving = ref(false);

// Kunci idempotensi satu percobaan simpan (research Decision 4). Dibuat saat
// dialog dibuka dan DIPAKAI ULANG untuk coba-ulang selama dialog yang sama
// terbuka — jadi koneksi yang putus tepat setelah server mencatat pembayaran
// tak pernah menggandakannya. Dialog yang dibuka lagi (mis. setelah sukses)
// mendapat kunci baru. Server tetap yang menjamin (unique index + replay).
const clientRef = ref(crypto.randomUUID());

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      saving.value = false;
      clientRef.value = crypto.randomUUID();
    }
  },
);

async function handleSubmit(entries) {
  if (saving.value) return;
  const [entry] = entries;
  saving.value = true;
  try {
    const result = await props.submitFn({ ...entry, purpose: props.purpose, client_ref: clientRef.value });
    // Konfirmasi: jumlah yang baru dicatat + sisa tagihan terbaru, atau "lunas".
    const amount = formatIDR(entry.amount);
    const remaining = result?.payment_summary?.remaining;
    toast.success(
      result?.payment_summary?.status === 'fully_paid'
        ? t('payment_ledger.saved_fully_paid', { amount })
        : remaining != null
          ? t('payment_ledger.saved_remaining', { amount, remaining: formatIDR(remaining) })
          : t('preorders.payment_saved'),
    );
    emit('saved', result);
    emit('close');
  } catch (err) {
    if (err?.status == null || err.status >= 500) {
      // Tak ada respons / galat server: pembayaran MUNGKIN sudah tercatat. Data
      // di dialog dipertahankan; pengguna diarahkan memeriksa riwayat dulu.
      toast.error(t('payment_ledger.outcome_unknown'));
    } else if (!err?.isConflict) {
      // 409 sudah di-toast interceptor global; selain itu tampilkan pesan pertama.
      toast.error((err?.errors && Object.values(err.errors)[0]?.[0]) || err?.message || t('payment_ledger.save_failed'));
    }
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <BaseModal :open="open" :title="title || t('payment_ledger.add_payment')" max-width-class="max-w-[480px]" @close="emit('close')">
    <div class="px-[26px] py-6">
      <PaymentPanel
        mode="record"
        :due-amount="remaining"
        :submitting="saving"
        :submit-label="t('pos.save_payment')"
        @submit="handleSubmit"
      />
    </div>
  </BaseModal>
</template>
