import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * 041 — konstanta & label pre-order yang dipakai bersama oleh daftar, panel detail dan form. Dipisah dari
 * PreordersView.vue supaya tiap subkomponen tidak menyalin tabel yang sama (satu sumber per konsep).
 */

export const STATUS_VARIANT = { ordered: 'neutral', dp_paid: 'warn', arrived: 'mint', settled: 'mint', handed_over: 'dark', cancelled: 'danger' };
export const DISPATCH_VARIANT = { pending: 'neutral', invoice_sent: 'warn', shipping: 'mint' };

// 021-preorder-form-updates (US3, research.md Decision 5) — HARUS tetap
// sinkron dengan App\Support\Couriers::OPTIONS/DEFAULT di backend (satu
// sumber definisi konseptual, disalin ke sini karena tidak ada mekanisme
// berbagi konstanta PHP<->JS di codebase ini). Backend tetap sumber
// kebenaran untuk validasi; daftar ini hanya untuk render dropdown.
export const COURIER_OPTIONS = ['JNE', 'J&T', 'SiCepat', 'Pos Indonesia', 'Other'];
export const COURIER_DEFAULT = 'JNE';
export const courierSelectOptions = COURIER_OPTIONS.map((c) => ({ value: c, label: c }));

/** Status yang sudah "tertutup": tak bisa diedit/dibayar/dipisah lagi (server menegakkan 409). */
export const CLOSED_STATUSES = ['handed_over', 'cancelled'];

export function usePreorderLabels() {
  const { t } = useI18n();

  const STATUS_LABEL = computed(() => ({
    ordered: t('preorders.step_ordered'),
    dp_paid: t('preorders.step_dp_paid'),
    arrived: t('preorders.step_arrived'),
    settled: t('preorders.step_settled'),
    handed_over: t('preorders.step_handed_over'),
    cancelled: t('events_sessions.status_cancelled'),
  }));
  // Penanda manual invoice-terkirim / pengiriman-berjalan. Urutan objek =
  // urutan tombol di panel detail. HARUS sinkron dengan
  // Preorder::DISPATCH_STATUSES di backend (validasi tetap di sana).
  const DISPATCH_LABEL = computed(() => ({
    pending: t('preorders.dispatch_status_pending'),
    invoice_sent: t('preorders.dispatch_status_invoice_sent'),
    shipping: t('preorders.dispatch_status_shipping'),
  }));
  const FULFILLMENT_LABEL = computed(() => ({
    pickup: t('preorders.fulfillment_pickup'),
    // 021-preorder-form-updates (US3, FR-007) — label saja yang berubah;
    // nilai enum tersimpan tetap 'courier' (research.md Decision 4).
    courier: t('preorders.fulfillment_mail_order'),
  }));

  return { STATUS_LABEL, DISPATCH_LABEL, FULFILLMENT_LABEL };
}
