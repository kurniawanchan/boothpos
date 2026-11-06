import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn(), getReference: vi.fn(), getReferenceImageUrl: vi.fn() }));
vi.mock('../../resources/js/api/payments', () => ({ verifyOrderPayments: vi.fn(), getPaymentProofBlobUrl: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));

const row = (id, total) => ({
  key: `order:${id}`, id, order_number: `ORD-00${id}`, status: 'completed', created_at: `2026-09-0${id}T10:00:00Z`, cashier_name: 'K', item_count: 1,
  unit_count: 1, total_amount: total, artist_names: [], payment_methods: [], has_reference: false,
});

/** Sales: salin nomor transaksi + filter nominal baru berlaku saat selesai diketik (blur/Enter), bukan tiap karakter. */
describe('SalesView — copy number and amount filter commit', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({ event: null, totals: {}, sessions: [], transactions: [row(1, '45000.00'), row(2, '4000.00')] });
  });

  async function open() {
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
    render(SalesView, { global: { plugins: [pinia, router] } });
    await screen.findByText('ORD-001');
  }

  it('copies the transaction number from the table', async () => {
    const writeText = vi.fn().mockResolvedValue();
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
    await open();

    await fireEvent.click(screen.getAllByTestId('copy-text')[0]);

    expect(writeText).toHaveBeenCalledWith(expect.stringMatching(/^ORD-00\d$/));
  });

  it('does not filter while typing the minimum, only once the field is committed', async () => {
    await open();
    const min = screen.getByLabelText(/min/i);

    await fireEvent.update(min, '4'); // input event only
    expect(screen.getByText('ORD-002')).toBeTruthy();

    min.value = '45000';
    await fireEvent.change(min); // selesai (blur/Enter)
    expect(screen.queryByText('ORD-002')).toBeNull();
    expect(screen.getByText('ORD-001')).toBeTruthy();
  });
});
