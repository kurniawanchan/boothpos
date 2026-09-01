import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReportsView from '../../resources/js/views/ReportsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { getProduct } from '../../resources/js/api/products';
import { getReceipt } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({
  salesReport: vi.fn(),
  artistSettlements: vi.fn(),
  profitReport: vi.fn(),
  recordSettlementPayment: vi.fn(),
  exportReport: vi.fn(),
}));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getReceipt: vi.fn() }));

// This is the actual regression case from the bug report: "Transaksi: 3
// tapi tabel cuma ada 2 baris" — two of three orders bought the same
// product, so the per-product aggregate legitimately has 2 rows while
// there really are 3 transactions. The fix surfaces both, side by side.
const SALES_RESPONSE = {
  event: { id: 1, name: 'Event A' },
  group_by: 'product',
  group_label: 'Produk',
  totals: { order_count: 3, unit_count: 5, gross_sales: '150000.00', net_sales: '150000.00' },
  rows: [
    { entity_id: 10, label: 'Stiker Holografik', unit_count: 3, amount: '90000.00' },
    { entity_id: 11, label: 'Pin Akrilik', unit_count: 2, amount: '60000.00' },
  ],
  transactions: [
    { id: 101, order_number: 'ORD-001', created_at: '2026-09-01T10:00:00Z', cashier_name: 'Kasir A', item_count: 2, total_amount: '60000.00' },
    { id: 102, order_number: 'ORD-002', created_at: '2026-09-01T11:00:00Z', cashier_name: 'Kasir A', item_count: 1, total_amount: '30000.00' },
    { id: 103, order_number: 'ORD-003', created_at: '2026-09-01T12:00:00Z', cashier_name: 'Kasir B', item_count: 2, total_amount: '60000.00' },
  ],
};

function renderReports() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'owner', name: 'Owner' };
  return render(ReportsView, { global: { plugins: [pinia] } });
}

describe('ReportsView — sales tab', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(SALES_RESPONSE);
  });

  it('uses the server-supplied group_label instead of a hardcoded "Label" header', async () => {
    renderReports();
    expect(await screen.findByText('Produk')).toBeInTheDocument();
    expect(screen.queryByText('Label')).not.toBeInTheDocument();
  });

  it('renders all real transactions even when the per-product breakdown has fewer rows', async () => {
    renderReports();
    await screen.findByText('Stiker Holografik');
    expect(screen.getByText(/daftar transaksi \(3\)/i)).toBeInTheDocument();
    expect(screen.getByText('ORD-001')).toBeInTheDocument();
    expect(screen.getByText('ORD-002')).toBeInTheDocument();
    expect(screen.getByText('ORD-003')).toBeInTheDocument();
  });

  it('opens the product detail view when a product-grouped row label is clicked', async () => {
    getProduct.mockResolvedValue({
      id: 10,
      name: 'Stiker Holografik',
      code_prefix: 'ABCST',
      artist_name: 'Artist A',
      category_name: 'Stiker',
      is_preorder: false,
      is_active: true,
      description: '',
      variants: [{ id: 1, sku: 'ABCST0001', variant_name: 'Standard', sell_price: '30000.00', current_stock: 12, is_active: true }],
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    const link = await screen.findByRole('button', { name: 'Stiker Holografik' });
    await user.click(link);
    await waitFor(() => expect(getProduct).toHaveBeenCalledWith(10));
    expect(await screen.findByText('Total stok tersedia (semua varian)')).toBeInTheDocument();
    expect((await screen.findAllByText('12')).length).toBeGreaterThan(0);
  });

  it('opens the receipt for a transaction row via "Lihat struk"', async () => {
    getReceipt.mockResolvedValue({
      order_number: 'ORD-001',
      store_name: 'Toko A',
      event_name: 'Event A',
      created_at: '2026-09-01T10:00:00Z',
      cashier_name: 'Kasir A',
      items: [],
      subtotal: '60000.00',
      discount_amount: '0.00',
      total_amount: '60000.00',
      payment_summary: [],
      change_amount: '0.00',
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderReports();
    const btn = await screen.findAllByRole('button', { name: /lihat struk/i });
    await user.click(btn[0]);
    await waitFor(() => expect(getReceipt).toHaveBeenCalledWith(101));
    expect((await screen.findAllByText('ORD-001')).length).toBeGreaterThan(0);
    expect(screen.getByText('Toko A')).toBeInTheDocument();
  });
});
