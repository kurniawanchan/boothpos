import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
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

const row = (id, has_reference) => ({
  key: `order:${id}`, id, order_number: `ORD-00${id}`, status: 'completed', created_at: '2026-09-01T10:00:00Z', cashier_name: 'K', item_count: 1,
  unit_count: 1, total_amount: '10000.00', artist_names: [], payment_methods: [], has_reference,
});

/**
 * 056 (US6) — kontrol filter Sale Reference di halaman Sales: mempersempit baris, ikut dalam ringkasan
 * "Menampilkan X dari Y", tersimpan di alamat (`?ref=`) dan dipulihkan saat dimuat ulang.
 */
describe('SalesView — Sale Reference filter (056 US6)', () => {
  let router;

  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'E', status: 'active' }] });
    salesReport.mockResolvedValue({
      event: null, totals: {}, sessions: [],
      transactions: [row(1, true), row(2, false), row(3, true), row(4, false)],
    });
  });

  async function open(url = '/sales') {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const pinia = createPinia();
    setActivePinia(pinia);
    router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/sales', name: 'sales', component: { template: '<div />' } }] });
    router.push(url);
    await router.isReady();
    render(SalesView, { global: { plugins: [pinia, router] } });
    await screen.findAllByRole('button', { name: /^ORD-\d+$/ });
    return user;
  }

  const shown = () => screen.getAllByRole('button', { name: /^ORD-\d+$/ }).map((b) => b.textContent.trim()).sort();

  async function pick(user, option) {
    await user.click(screen.getByText('Semua referensi penjualan'));
    await user.click(await screen.findByRole('option', { name: option }));
  }

  it('narrows the list to sales that have / lack a Sale Reference', async () => {
    const user = await open();
    expect(shown()).toEqual(['ORD-001', 'ORD-002', 'ORD-003', 'ORD-004']);

    await pick(user, 'Punya Referensi Penjualan');
    expect(shown()).toEqual(['ORD-001', 'ORD-003']);
    expect(screen.getAllByTestId('reference-link')).toHaveLength(2);

    await user.click(screen.getByText('Punya Referensi Penjualan'));
    await user.click(await screen.findByRole('option', { name: 'Tanpa Referensi Penjualan' }));
    expect(shown()).toEqual(['ORD-002', 'ORD-004']);
    expect(screen.queryByTestId('reference-link')).not.toBeInTheDocument();
  });

  it('counts only the filtered rows in the "Menampilkan X dari Y" line', async () => {
    const user = await open();

    await pick(user, 'Punya Referensi Penjualan');

    expect(screen.getByText(/Menampilkan 2 dari 4/)).toBeInTheDocument();
  });

  it('keeps the choice in the address', async () => {
    const user = await open();

    await pick(user, 'Tanpa Referensi Penjualan');

    await waitFor(() => expect(router.currentRoute.value.query.ref).toBe('none'));
  });

  it('restores the filter from the address on load, and ignores a bad value', async () => {
    await open('/sales?ref=has');
    expect(shown()).toEqual(['ORD-001', 'ORD-003']);
  });

  it('ignores an unknown ?ref= value and shows everything', async () => {
    await open('/sales?ref=bogus');
    expect(shown()).toEqual(['ORD-001', 'ORD-002', 'ORD-003', 'ORD-004']);
  });
});
