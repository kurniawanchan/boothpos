import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import SalesView from '../../resources/js/views/SalesView.vue';
import { listEvents } from '../../resources/js/api/events';
import { salesReport } from '../../resources/js/api/reports';
import { getProduct } from '../../resources/js/api/products';
import { getOrder, getReceipt } from '../../resources/js/api/orders';

vi.mock('../../resources/js/api/events', () => ({ listEvents: vi.fn() }));
vi.mock('../../resources/js/api/reports', () => ({ salesReport: vi.fn(), exportReport: vi.fn(), exportSalesTransactions: vi.fn() }));
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
  // SalesView menyimpan filter di URL (vue-router); tiap render memakai router memori baru.
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
  return render(SalesView, { global: { plugins: [pinia, router] } });
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
    // Baris ORD-001 ditunjuk eksplisit — urutan tabel kini "terbaru dulu" (sama seperti dari server).
    await user.click(within(screen.getByText('ORD-001').closest('tr')).getByRole('button', { name: 'Lihat struk' }));
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
    await user.click(within(screen.getByText('ORD-001').closest('tr')).getByRole('button', { name: 'Lihat detail' }));
    await waitFor(() => expect(getOrder).toHaveBeenCalledWith(101));
    expect(await screen.findByText('Detail transaksi')).toBeInTheDocument();
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
    expect(await screen.findByText('Detail transaksi')).toBeInTheDocument();
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

/**
 * Filter lengkap + urutan kolom. Semua dikerjakan di frontend atas transactions[]
 * yang sudah dimuat (sama seperti pencarian F10.6): tanpa memuat ulang laporan.
 * Antar-filter di-AND-kan; beberapa nilai dalam satu filter yang sama di-OR-kan.
 */
const FILTER_RESPONSE = {
  event: { id: 1, name: 'Event A' },
  totals: { order_count: 4, unit_count: 12, gross_sales: '225000.00', net_sales: '225000.00' },
  transactions: [
    { id: 201, order_number: 'ORD-201', customer_name: 'Budi', created_at: '2026-09-01T09:00:00Z', cashier_id: 1, cashier_name: 'Kasir A', item_count: 2, unit_count: 4, discount_amount: '0.00', payment_methods: ['cash'], total_amount: '60000.00', artist_names: ['Nekoyama Studio'] },
    { id: 202, order_number: 'ORD-202', customer_name: null, created_at: '2026-09-01T11:00:00Z', cashier_id: 1, cashier_name: 'Kasir A', item_count: 1, unit_count: 1, discount_amount: '0.00', payment_methods: ['qr_ewallet'], total_amount: '30000.00', artist_names: ['Yukishiro Works'] },
    { id: 203, order_number: 'ORD-203', customer_name: 'Siti', created_at: '2026-09-02T10:00:00Z', cashier_id: 2, cashier_name: 'Kasir B', item_count: 3, unit_count: 6, discount_amount: '5000.00', payment_methods: ['cash', 'bank_transfer'], total_amount: '120000.00', artist_names: ['Nekoyama Studio', 'Hoshizora Craft'] },
    { id: 204, order_number: 'ORD-204', customer_name: null, created_at: '2026-09-02T14:00:00Z', cashier_id: 2, cashier_name: 'Kasir B', item_count: 1, unit_count: 1, discount_amount: '0.00', payment_methods: ['cash'], total_amount: '15000.00', artist_names: ['Hoshizora Craft'] },
  ],
};

/** Nomor transaksi sesuai urutan baris di tabel. */
const rowOrder = () => screen.getAllByRole('button', { name: /^ORD-2\d\d$/ }).map((b) => b.textContent.trim());

async function setupFilters() {
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  renderSales();
  await screen.findByText('ORD-201');

  return user;
}

async function pickOption(user, triggerText, optionName) {
  await user.click(screen.getByText(triggerText));
  await user.click(await screen.findByRole('option', { name: optionName }));
}

describe('SalesView — filters', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(FILTER_RESPONSE);
  });

  it('lists newest first by default', async () => {
    await setupFilters();

    expect(rowOrder()).toEqual(['ORD-204', 'ORD-203', 'ORD-202', 'ORD-201']);
  });

  it('filters by cashier', async () => {
    const user = await setupFilters();

    await pickOption(user, 'Semua kasir', 'Kasir B');

    expect(rowOrder()).toEqual(['ORD-204', 'ORD-203']);
  });

  it('filters by seller, matching a transaction that has several sellers', async () => {
    const user = await setupFilters();

    await pickOption(user, 'Semua penjual', 'Hoshizora Craft');

    expect(rowOrder()).toEqual(['ORD-204', 'ORD-203']);
  });

  it('filters by payment method, matching a transaction paid with several', async () => {
    const user = await setupFilters();

    await pickOption(user, 'Semua pembayaran', 'Transfer bank');

    expect(rowOrder()).toEqual(['ORD-203']);
  });

  it('filters walk-in only, and named customers only', async () => {
    const user = await setupFilters();

    await pickOption(user, 'Semua pelanggan', 'Hanya walk-in');
    expect(rowOrder()).toEqual(['ORD-204', 'ORD-202']);

    await pickOption(user, 'Hanya walk-in', 'Pelanggan terdaftar');
    expect(rowOrder()).toEqual(['ORD-203', 'ORD-201']);
  });

  it('filters by a total range, inclusive at both ends', async () => {
    const user = await setupFilters();

    await user.type(screen.getByLabelText('Total minimum'), '30000');
    await user.type(screen.getByLabelText('Total maksimum'), '60000');

    expect(rowOrder()).toEqual(['ORD-202', 'ORD-201']);
  });

  it('filters by a date range, inclusive of both days', async () => {
    const user = await setupFilters();

    await user.type(screen.getByLabelText('Dari tanggal'), '2026-09-02');
    expect(rowOrder()).toEqual(['ORD-204', 'ORD-203']);

    await user.type(screen.getByLabelText('Sampai tanggal'), '2026-09-02');
    expect(rowOrder()).toEqual(['ORD-204', 'ORD-203']);

    await user.clear(screen.getByLabelText('Dari tanggal'));
    await user.clear(screen.getByLabelText('Sampai tanggal'));
    await user.type(screen.getByLabelText('Sampai tanggal'), '2026-09-01');
    expect(rowOrder()).toEqual(['ORD-202', 'ORD-201']);
  });

  it('combines filters with AND, counts them, and resets them all', async () => {
    const user = await setupFilters();
    expect(screen.queryByRole('button', { name: 'Atur ulang filter' })).not.toBeInTheDocument(); // tak ada filter aktif

    await pickOption(user, 'Semua kasir', 'Kasir B');
    await pickOption(user, 'Semua pelanggan', 'Hanya walk-in');

    expect(rowOrder()).toEqual(['ORD-204']);
    expect(screen.getByText('2 filter aktif')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Atur ulang filter' }));

    expect(rowOrder()).toEqual(['ORD-204', 'ORD-203', 'ORD-202', 'ORD-201']);
    expect(screen.queryByText(/filter aktif/)).not.toBeInTheDocument();
  });

  it('keeps the text search working together with the other filters', async () => {
    const user = await setupFilters();

    await pickOption(user, 'Semua kasir', 'Kasir B');
    await user.type(screen.getByRole('searchbox'), 'siti');

    expect(rowOrder()).toEqual(['ORD-203']);
  });

  it('summarises exactly what the current filters show', async () => {
    const user = await setupFilters();
    expect(screen.getByText(/Menampilkan 4 dari 4 transaksi · 12 unit/)).toBeInTheDocument();

    await pickOption(user, 'Semua kasir', 'Kasir B');

    const summary = screen.getByText(/Menampilkan 2 dari 4 transaksi/);
    expect(summary).toHaveTextContent('7 unit');
    expect(summary).toHaveTextContent(/135\.000/); // 120.000 + 15.000
  });

  it('shows a filter-specific empty message when nothing matches', async () => {
    const user = await setupFilters();

    await user.type(screen.getByLabelText('Total maksimum'), '1000');

    expect(screen.getByText('Tidak ada transaksi yang cocok dengan filter.')).toBeInTheDocument();
  });

  it('shows how each transaction was paid', async () => {
    await setupFilters();

    const row = screen.getByText('ORD-203').closest('tr');
    expect(row).toHaveTextContent('Tunai');
    expect(row).toHaveTextContent('Transfer bank');
    expect(screen.getByText('ORD-202').closest('tr')).toHaveTextContent('QRIS / e-wallet');
  });
});

describe('SalesView — column sort order', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listEvents.mockResolvedValue({ data: [{ id: 1, name: 'Event A', status: 'active' }] });
    salesReport.mockResolvedValue(FILTER_RESPONSE);
  });

  const header = (name) => screen.getByRole('columnheader', { name });

  it('sorts by total ascending, then descending on a second click', async () => {
    const user = await setupFilters();

    await user.click(header(/^Total/));
    expect(rowOrder()).toEqual(['ORD-204', 'ORD-202', 'ORD-201', 'ORD-203']);

    await user.click(header(/^Total/));
    expect(rowOrder()).toEqual(['ORD-203', 'ORD-201', 'ORD-202', 'ORD-204']);
  });

  it('sorts by time in both directions', async () => {
    const user = await setupFilters();

    // Urutan awal sudah "waktu terbaru dulu"; klik pertama pada kolom aktif itu → menaik.
    await user.click(header(/^Waktu/));
    expect(rowOrder()).toEqual(['ORD-201', 'ORD-202', 'ORD-203', 'ORD-204']);

    await user.click(header(/^Waktu/));
    expect(rowOrder()).toEqual(['ORD-204', 'ORD-203', 'ORD-202', 'ORD-201']);
  });

  it('sorts by customer with walk-in always last, in either direction', async () => {
    const user = await setupFilters();

    await user.click(header(/^Pelanggan/));
    expect(rowOrder()).toEqual(['ORD-201', 'ORD-203', 'ORD-204', 'ORD-202']); // Budi, Siti, lalu walk-in (terbaru dulu)

    await user.click(header(/^Pelanggan/));
    expect(rowOrder()).toEqual(['ORD-203', 'ORD-201', 'ORD-204', 'ORD-202']); // Siti, Budi, walk-in tetap terakhir
  });

  it('sorts by cashier, item count, and seller', async () => {
    const user = await setupFilters();

    await user.click(header(/^Kasir/));
    expect(rowOrder().slice(0, 2).sort()).toEqual(['ORD-201', 'ORD-202']); // Kasir A dulu

    await user.click(header(/^Item/));
    expect(rowOrder().at(-1)).toBe('ORD-203'); // 3 baris item = terbanyak, terakhir saat menaik

    await user.click(header(/^Penjual/));
    expect(rowOrder()[0]).toBe('ORD-204'); // "Hoshizora Craft" paling awal secara alfabet
  });

  it('marks the active sort column for assistive technology', async () => {
    const user = await setupFilters();

    await user.click(header(/^Total/));

    expect(header(/^Total/)).toHaveAttribute('aria-sort', 'ascending');
  });

  it('keeps the chosen sort while filters change', async () => {
    const user = await setupFilters();
    await user.click(header(/^Total/)); // menaik

    await pickOption(user, 'Semua kasir', 'Kasir B');

    expect(rowOrder()).toEqual(['ORD-204', 'ORD-203']);
  });
});

