import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { getOrder, getReference, getReferenceImageUrl } from '../../resources/js/api/orders';
import { getPaymentProofBlobUrl } from '../../resources/js/api/payments';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), getReference: vi.fn(), getReferenceImageUrl: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ verifyOrderPayments: vi.fn(), getPaymentProofBlobUrl: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const base = { status: 'completed', created_at: '2026-09-01T10:00:00Z', cashier_name: 'K', item_count: 1, unit_count: 1, total_amount: '10000.00', artist_names: [], payment_methods: [] };
const ROWS = [
  { ...base, key: 'order:1', id: 1, order_number: 'ORD-001', has_reference: true, payment_proof_count: 1 },
  { ...base, key: 'order:2', id: 2, order_number: 'ORD-002', has_reference: false, payment_proof_count: 0 },
  { ...base, key: 'order:3', id: 3, order_number: 'ORD-003', has_reference: true, payment_proof_count: 0 },
];

/** 056 (US7) — satu sakelar menampilkan/menyembunyikan Sale Reference + Payment Proof semua baris di halaman. */
describe('SalesView — show/hide all references and proofs (056 US7)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    try { localStorage.clear(); } catch { /* abaikan */ }
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({ event: null, totals: {}, sessions: [], transactions: ROWS });
    getReference.mockImplementation(async (id) => ({ note: `Catatan ${id}`, images: [{ id: id * 10 }] }));
    getReferenceImageUrl.mockResolvedValue('blob:ref');
    getOrder.mockResolvedValue({ id: 1, payments: [{ id: 5, method: 'qr_ewallet', amount: '1000.00', proof_id: 77, can_view_proof: true }] });
    getPaymentProofBlobUrl.mockResolvedValue('blob:proof');
  });

  async function open() {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    render(SalesView, { global: { plugins: [pinia, router] } });
    await screen.findByText('ORD-001');
    return user;
  }

  it('is off by default and loads nothing', async () => {
    await open();
    expect(screen.queryAllByTestId('row-attachments')).toHaveLength(0);
    expect(getReference).not.toHaveBeenCalled();
    expect(getOrder).not.toHaveBeenCalled();
    expect(getReferenceImageUrl).not.toHaveBeenCalled();
    expect(getPaymentProofBlobUrl).not.toHaveBeenCalled();
  });

  it('on: rows with a reference or a proof show them; rows with neither show nothing and fetch nothing', async () => {
    const user = await open();
    await user.click(screen.getByTestId('toggle-attachments'));

    expect(await screen.findByText('Catatan 1')).toBeInTheDocument();
    expect(await screen.findByText('Catatan 3')).toBeInTheDocument();
    await waitFor(() => expect(screen.getAllByTestId('payment-proof-thumbnail')).toHaveLength(1));
    expect(screen.getAllByTestId('row-attachments')).toHaveLength(2);        // baris 2 tak punya apa-apa
    expect(getReference).toHaveBeenCalledTimes(2);                            // hanya baris 1 dan 3
    expect(getReference).not.toHaveBeenCalledWith(2);
    expect(getOrder).toHaveBeenCalledTimes(1);                                // hanya baris 1 punya bukti
  });

  it('off again collapses everything at once', async () => {
    const user = await open();
    await user.click(screen.getByTestId('toggle-attachments'));
    await screen.findByText('Catatan 1');

    await user.click(screen.getByTestId('toggle-attachments'));

    expect(screen.queryByText('Catatan 1')).toBeNull();
    expect(screen.queryAllByTestId('row-attachments')).toHaveLength(0);
  });

  it('remembers its position for the viewer (and still renders when storage is blocked)', async () => {
    const user = await open();
    await user.click(screen.getByTestId('toggle-attachments'));
    await screen.findByText('Catatan 1');
    expect(localStorage.getItem('sales.attachments.expanded')).toBe('1');
  });

  it('a failed proof load shows a placeholder and does not break the row', async () => {
    getPaymentProofBlobUrl.mockRejectedValue(new Error('x'));
    const user = await open();
    await user.click(screen.getByTestId('toggle-attachments'));

    expect(await screen.findByText('Catatan 1')).toBeInTheDocument();
    expect(await screen.findByTestId('payment-proof-unavailable')).toBeInTheDocument();
  });

  it('never shows a proof the server says the viewer may not see', async () => {
    getOrder.mockResolvedValue({ id: 1, payments: [{ id: 5, method: 'qr_ewallet', amount: '1000.00', proof_id: 77, can_view_proof: false }] });
    const user = await open();
    await user.click(screen.getByTestId('toggle-attachments'));
    await screen.findByText('Catatan 1');

    await waitFor(() => expect(getOrder).toHaveBeenCalled());
    expect(screen.queryByTestId('payment-proof-thumbnail')).toBeNull();
    expect(getPaymentProofBlobUrl).not.toHaveBeenCalled();
  });
});
