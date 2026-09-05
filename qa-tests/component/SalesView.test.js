import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { getProduct } from '../../resources/js/api/products';
import { getOrder, getReceipt } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
vi.mock('../../resources/js/api/orders', () => ({ getOrder: vi.fn(), getReceipt: vi.fn() }));

// This is the actual regression case from the bug report: "Transaksi: 3
// tapi tabel cuma ada 2 baris" — two of three orders bought the same
// product, so the per-product aggregate legitimately has 2 rows while
// there really are 3 transactions. 009-ui-ux-refinements US2 removed the
// per-product aggregate table entirely, so `rows`/`group_label` are no
// longer rendered — kept in the fixture only because salesReport() still
// returns them (frontend simply ignores them now).
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

  // 009-ui-ux-refinements US2 — the per-product/category/artist/day summary
  // table and its group_by selector are gone; the transaction list is the
  // page's only/primary table now.
  it('renders no product-summary table, only the transaction list', async () => {
    renderSales();
    await screen.findByText('ORD-001');
    expect(screen.queryByText('Stiker Holografik')).not.toBeInTheDocument();
    expect(screen.queryByText('Per produk')).not.toBeInTheDocument();
  });

  it('renders all real transactions', async () => {
    renderSales();
    await screen.findByText('ORD-001');
    expect(screen.getByText(/daftar transaksi \(3\)/i)).toBeInTheDocument();
    expect(screen.getByText('ORD-001')).toBeInTheDocument();
    expect(screen.getByText('ORD-002')).toBeInTheDocument();
    expect(screen.getByText('ORD-003')).toBeInTheDocument();
  });

  // 014-sales-receipt-event-footer US1 — "View receipt" is a new, separate
  // action button alongside "View items", not a replacement.
  it('clicking "View receipt" calls getReceipt with the row id and opens the receipt modal', async () => {
    getReceipt.mockResolvedValue({
      order_number: 'ORD-001',
      store_name: 'Sakana Fridge',
      event_name: 'Event A',
      created_at: '2026-09-01T10:00:00Z',
      cashier_name: 'Kasir A',
      items: [],
      subtotal: '60000.00',
      total: '60000.00',
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    await user.click(screen.getAllByRole('button', { name: 'Lihat struk' })[0]);
    await waitFor(() => expect(getReceipt).toHaveBeenCalledWith(101));
    expect(await screen.findByText('ORD-001', { selector: 'span.font-mono' })).toBeInTheDocument();
    expect(screen.getByText('Sakana Fridge')).toBeInTheDocument();
  });

  // Regression (FR-003): the pre-existing "View items" action must still
  // open the products-sold popup exactly as before, unaffected by the new
  // receipt button sitting next to it.
  it('clicking "View items" still opens the products-sold popup, unaffected by the new receipt button', async () => {
    getOrder.mockResolvedValue({
      id: 101,
      order_number: 'ORD-001',
      items: [{ id: 1, variant_id: 1, artist_id: 1, product_id: 10, sku_snapshot: 'ABCST0001', name_snapshot: 'Stiker Holografik', qty: 2, sell_price: '30000.00', line_total: '60000.00' }],
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    await user.click(screen.getAllByRole('button', { name: 'Lihat produk' })[0]);
    await waitFor(() => expect(getOrder).toHaveBeenCalledWith(101));
    expect(await screen.findByText('Produk Terjual')).toBeInTheDocument();
    expect(getReceipt).not.toHaveBeenCalled();
  });

  it('opens the "products sold" popup (not the receipt) when clicking a transaction number', async () => {
    getOrder.mockResolvedValue({
      id: 101,
      order_number: 'ORD-001',
      items: [{ id: 1, variant_id: 1, artist_id: 1, product_id: 10, sku_snapshot: 'ABCST0001', name_snapshot: 'Stiker Holografik', qty: 2, sell_price: '30000.00', line_total: '60000.00' }],
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    await user.click(screen.getByRole('button', { name: 'ORD-001' }));
    await waitFor(() => expect(getOrder).toHaveBeenCalledWith(101));
    expect(await screen.findByText('Produk Terjual')).toBeInTheDocument();
    expect(screen.getAllByText('Stiker Holografik').length).toBeGreaterThan(0);
  });

  it('opens the product detail view when a product name is clicked inside the products-sold popup', async () => {
    getOrder.mockResolvedValue({
      id: 101,
      order_number: 'ORD-001',
      items: [{ id: 1, variant_id: 1, artist_id: 1, product_id: 10, sku_snapshot: 'ABCST0001', name_snapshot: 'Stiker Holografik', qty: 2, sell_price: '30000.00', line_total: '60000.00' }],
    });
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
    await screen.findByText('ORD-001');
    await user.click(screen.getByRole('button', { name: 'ORD-001' }));
    const productLink = await screen.findByRole('button', { name: 'Stiker Holografik' });
    await user.click(productLink);
    await waitFor(() => expect(getProduct).toHaveBeenCalledWith(10));
    expect(await screen.findByText('Total stok tersedia (semua varian)')).toBeInTheDocument();
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

  it('opens the products-sold popup when clicking the transaction number itself', async () => {
    getOrder.mockResolvedValue({ id: 101, order_number: 'ORD-001', items: [] });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderSales();
    await screen.findByText('ORD-001');
    await user.click(screen.getByRole('button', { name: 'ORD-001' }));
    await waitFor(() => expect(getOrder).toHaveBeenCalledWith(101));
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
