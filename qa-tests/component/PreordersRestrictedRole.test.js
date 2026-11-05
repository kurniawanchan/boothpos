import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { useAuthStore } from '../../resources/js/stores/auth';
import PaymentHistoryList from '../../resources/js/components/payment/PaymentHistoryList.vue';
import { createI18n } from 'vue-i18n';
import id from '../../resources/js/locales/id.json';

const PAYMENT = { id: 5, method: 'qr_ewallet', amount: '5000.00', paid_at: '2026-09-01T10:00:00Z', status: 'paid', verification: 'pending', can_verify: true, can_edit_confirmation: true, can_change_method: true, has_proof: false };

/**
 * 057 (US3) — komponen riwayat pembayaran dipakai bersama Sales dan Pre-orders; pemanggil memetakan izin aksinya
 * lewat prop `allow`. Di sini pemetaan Pre-orders (verify/payment) — bukan sales.* — menentukan tombolnya.
 */
describe('PaymentHistoryList allow mapping (057)', () => {
  const i18n = createI18n({ legacy: false, locale: 'id', messages: { id } });
  const mountList = (allow, extra = {}) => mount(PaymentHistoryList, { props: { payments: [PAYMENT], allow, ...extra }, global: { plugins: [i18n] } });

  it('shows verify / edit confirmation / change method by default (legacy callers pass nothing)', () => {
    const w = mountList({});
    expect(w.find('[data-testid="verify-payment"]').exists()).toBe(true);
    expect(w.find('[data-testid="edit-confirmation"]').exists()).toBe(true);
    expect(w.find('[data-testid="change-method"]').exists()).toBe(true);
  });

  it('hides each action when its permission is off, independently', () => {
    const w = mountList({ verify: false, confirm: true, method: false });
    expect(w.find('[data-testid="verify-payment"]').exists()).toBe(false);
    expect(w.find('[data-testid="edit-confirmation"]').exists()).toBe(true);
    expect(w.find('[data-testid="change-method"]').exists()).toBe(false);
  });

  it('the auth store maps preorders.* permissions the way PreordersView reads them', () => {
    setActivePinia(createPinia());
    const auth = useAuthStore();
    auth.user = { menu_keys: ['preorders'], permissions: { preorders: ['view', 'verify'] } };
    expect(auth.canDo('preorders', 'verify')).toBe(true);
    expect(auth.canDo('preorders', 'payment')).toBe(false);
    expect(auth.canDo('preorders', 'split')).toBe(false);
  });
});
