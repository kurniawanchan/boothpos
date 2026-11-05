import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { getOrder, getReference } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), getReference: vi.fn(), getReferenceImageUrl: vi.fn(), voidOrder: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ verifyOrderPayments: vi.fn(), getPaymentProofBlobUrl: vi.fn(), verifyPayment: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const ROW = { key: 'order:1', id: 1, order_number: 'ORD-001', status: 'completed', created_at: '2026-09-01T10:00:00Z', cashier_name: 'K', item_count: 1, unit_count: 1, total_amount: '10000.00', artist_names: [], payment_methods: ['qr_ewallet'], has_reference: true, payment_proof_count: 0 };
const ORDER = {
  id: 1, order_number: 'ORD-001', status: 'completed', items: [], total_amount: '10000.00',
  payment_summary: { grand_total: '10000.00', total_paid: '5000.00', remaining: '5000.00', status: 'partially_paid', count: 1 },
  payments: [{ id: 5, method: 'qr_ewallet', amount: '5000.00', paid_at: '2026-09-01T10:00:00Z', status: 'paid', verification: 'pending', can_verify: true, can_edit_confirmation: true, can_change_method: true, has_proof: false }],
  reference: { note: 'catatan', has_image: false, can_edit: true, images: [] },
};

/** 057 (US1) — peran dengan Sales = View saja tak melihat tombol ubah apa pun; peran penuh melihat semuanya. */
describe('Sales screens — permission-aware controls (057)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({ event: null, totals: {}, sessions: [], transactions: [ROW] });
    getOrder.mockResolvedValue(ORDER);
    getReference.mockResolvedValue({ note: 'catatan', images: [], can_edit: true });
  });

  async function open(permissions) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const pinia = createPinia();
    setActivePinia(pinia);
    useAuthStore().user = { id: 1, role: 'Owner', menu_keys: ['sales', 'settings'], permissions };
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    render(SalesView, { global: { plugins: [pinia, router] } });
    await screen.findByText('ORD-001');
    return user;
  }

  const FULL = { sales: ['view', 'void', 'payment', 'payment_method', 'verify', 'reference', 'export', 'receipts', 'approve'], settings: ['view', 'edit'] };
  const VIEW_ONLY = { sales: ['view'], settings: ['view'] };

  it('view-only: no export, receipts or bulk verify, and the detail has no void/add payment/verify/edit actions', async () => {
    const user = await open(VIEW_ONLY);
    expect(screen.queryByTestId('bulk-receipts')).toBeNull();
    expect(screen.queryByRole('button', { name: /^Ekspor/ })).toBeNull();

    await user.click(screen.getByRole('button', { name: 'ORD-001' }));
    await screen.findByTestId('payment-history');

    expect(screen.queryByTestId('add-payment')).toBeNull();
    expect(screen.queryByTestId('verify-payment')).toBeNull();
    expect(screen.queryByTestId('edit-confirmation')).toBeNull();
    expect(screen.queryByTestId('change-method')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Batalkan transaksi' })).toBeNull();
    // melihat tetap bisa
    expect(screen.getByTestId('order-reference')).toBeInTheDocument();
  });

  it('full access: every one of those controls is present', async () => {
    const user = await open(FULL);
    expect(screen.getByTestId('bulk-receipts')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Ekspor/ })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'ORD-001' }));
    await screen.findByTestId('payment-history');

    expect(screen.getByTestId('add-payment')).toBeInTheDocument();
    expect(screen.getByTestId('verify-payment')).toBeInTheDocument();
    expect(screen.getByTestId('edit-confirmation')).toBeInTheDocument();
    expect(screen.getByTestId('change-method')).toBeInTheDocument();
  });

  it('a legacy payload without permissions (older server) behaves as full access', async () => {
    await open(undefined);
    expect(screen.getByTestId('bulk-receipts')).toBeInTheDocument();
  });

  it('the reference dialog is read-only without the reference action', async () => {
    const user = await open(VIEW_ONLY);
    await user.click(screen.getByTestId('reference-link'));

    await waitFor(() => expect(getReference).toHaveBeenCalled());
    await screen.findByDisplayValue('catatan').catch(() => screen.findByText('catatan'));
    expect(screen.queryByRole('button', { name: /Simpan/ })).toBeNull();
  });

  it('the same dialog shows Save with the reference action (control for the test above)', async () => {
    const user = await open(FULL);
    await user.click(screen.getByTestId('reference-link'));

    expect(await screen.findByRole('button', { name: /Simpan/ })).toBeInTheDocument();
  });
});
