import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { getProduct } from '../../resources/js/api/products';
import { getReceipt } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn() }));
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
    { id: 101, order_number: 'ORD-001', customer_name: 'Budi Santoso', created_at: '2026-09-01T10:00:00Z', cashier_name: 'Kasir A', item_count: 2, total_amount: '60000.00', artist_names: ['Nekoyama Studio'] },
    { id: 102, order_number: 'ORD-002', customer_name: null, created_at: '2026-09-01T11:00:00Z', cashier_name: 'Kasir A', item_count: 1, total_amount: '30000.00', artist_names: ['Yukishiro Works'] },
    { id: 103, order_number: 'ORD-003', customer_name: 'Siti Aminah', created_at: '2026-09-01T12:00:00Z', cashier_name: 'Kasir B', item_count: 2, total_amount: '60000.00', artist_names: ['Nekoyama Studio', 'Hoshizora Craft'] },
  ],
};

function renderSales() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(SalesView, { global: { plugins: [pinia] } });
}

// Penjualan dikeluarkan dari ReportsView.vue menjadi menu tersendiri — layar
// ini terbuka untuk semua peran (kasir termasuk), berbeda dari Rekap Artist/
// Modal & Untung/Modal Artist yang tetap owner/admin-only di ReportsView.vue.
describe('SalesView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(SALES_RESPONSE);
  });

  it('uses the server-supplied group_label instead of a hardcoded "Label" header', async () => {
    renderSales();
    expect(await screen.findByText('Produk')).toBeInTheDocument();
    expect(screen.queryByText('Label')).not.toBeInTheDocument();
  });

  it('renders all real transactions even when the per-product breakdown has fewer rows', async () => {
    renderSales();
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
    renderSales();
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
      items: [{ name: 'Stiker Holografik', qty: 2, price: '30000.00', line_total: '60000.00', artist_name: 'Nekoyama Studio' }],
      subtotal: '60000.00',
      discount_amount: '0.00',
      total_amount: '60000.00',
      payment_summary: [],
      change_amount: '0.00',
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    const btn = await screen.findAllByRole('button', { name: /lihat struk/i });
    await user.click(btn[0]);
    await waitFor(() => expect(getReceipt).toHaveBeenCalledWith(101));
    expect((await screen.findAllByText('ORD-001')).length).toBeGreaterThan(0);
    expect(screen.getByText('Toko A')).toBeInTheDocument();
    expect(screen.getAllByText(/Nekoyama Studio/).length).toBeGreaterThan(0);
  });
});

// F10.6 — client-side search over the already-loaded transactions[] array.
describe('SalesView — transaction search (F10.6)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(SALES_RESPONSE);
  });

  it('filters by order number, case-insensitively', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    const input = screen.getByLabelText('Cari transaksi');
    await user.type(input, 'ord-002');
    expect(screen.queryByText('ORD-001')).not.toBeInTheDocument();
    expect(screen.getByText('ORD-002')).toBeInTheDocument();
    expect(screen.queryByText('ORD-003')).not.toBeInTheDocument();
  });

  it('filters by customer name and gracefully skips walk-in (null customer_name) rows instead of erroring', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    const input = screen.getByLabelText('Cari transaksi');
    await user.type(input, 'budi');
    expect(screen.getByText('ORD-001')).toBeInTheDocument();
    expect(screen.queryByText('ORD-002')).not.toBeInTheDocument();
    expect(screen.queryByText('ORD-003')).not.toBeInTheDocument();
  });

  it('filters by cashier name', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    const input = screen.getByLabelText('Cari transaksi');
    await user.type(input, 'kasir b');
    expect(screen.queryByText('ORD-001')).not.toBeInTheDocument();
    expect(screen.getByText('ORD-003')).toBeInTheDocument();
  });

  it('filters by artist name, matching a transaction that has multiple artists', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    const input = screen.getByLabelText('Cari transaksi');
    await user.type(input, 'hoshizora');
    expect(screen.queryByText('ORD-001')).not.toBeInTheDocument();
    expect(screen.queryByText('ORD-002')).not.toBeInTheDocument();
    expect(screen.getByText('ORD-003')).toBeInTheDocument();
  });

  it('opens the receipt when clicking the transaction number itself', async () => {
    getReceipt.mockResolvedValue({ order_number: 'ORD-001', store_name: 'Toko A', event_name: 'Event A', created_at: '2026-09-01T10:00:00Z', cashier_name: 'Kasir A', items: [], subtotal: '0', discount_amount: '0', total_amount: '0', payment_summary: [], change_amount: '0' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    await user.click(screen.getByRole('button', { name: 'ORD-001' }));
    await waitFor(() => expect(getReceipt).toHaveBeenCalledWith(101));
  });

  it('shows a customer detail popover when clicking the customer name', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    await user.click(screen.getByRole('button', { name: 'Budi Santoso' }));
    expect(await screen.findByText('Detail pelanggan')).toBeInTheDocument();
  });

  it('fills the search box when clicking an artist name', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    await user.click(screen.getAllByRole('button', { name: 'Nekoyama Studio' })[0]);
    expect(screen.getByLabelText('Cari transaksi')).toHaveValue('Nekoyama Studio');
  });

  it('shows a "no match" empty message and does not throw for a null-customer row when searching', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    const input = screen.getByLabelText('Cari transaksi');
    await user.type(input, 'nomatch-xyz');
    expect(screen.getByText(/tidak ada transaksi yang cocok/i)).toBeInTheDocument();
  });
});
