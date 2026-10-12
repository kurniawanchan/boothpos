import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import StockView from '../../resources/js/views/StockView.vue';
import MaterialsView from '../../resources/js/views/MaterialsView.vue';
import VendorsView from '../../resources/js/views/VendorsView.vue';
import PurchaseOrdersView from '../../resources/js/views/PurchaseOrdersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listMovements } from '../../resources/js/api/stock';
import { listVendors } from '../../resources/js/api/vendors';
import { listMaterials } from '../../resources/js/api/materials';
import { listOutlets } from '../../resources/js/api/outlets';
import { listPurchaseOrders } from '../../resources/js/api/purchaseOrders';

// 041 (US2, kelompok stok & pembelian) — struktur halaman baru: PageHeader, FilterBar/penghitung "N data",
// TabBar status PO, dan keadaan galat. Perilaku (parameter ke server, izin) dijaga oleh tes lama tiap view.
vi.mock('../../resources/js/api/stock', () => ({ listMovements: vi.fn(), createAdjustment: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ lookupVariants: vi.fn(), getProduct: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn(), createVendor: vi.fn(), updateVendor: vi.fn(), deleteVendor: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({
  listMaterials: vi.fn(), createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn(), listBomLines: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));
vi.mock('../../resources/js/api/purchaseOrders', () => ({
  listPurchaseOrders: vi.fn(), getPurchaseOrder: vi.fn(), createPurchaseOrder: vi.fn(), updatePurchaseOrder: vi.fn(),
  deletePurchaseOrder: vi.fn(), updatePurchaseOrderStatus: vi.fn(), recordPurchaseOrderPayment: vi.fn(), getPurchaseOrderInvoice: vi.fn(),
}));

const meta = (total) => ({ current_page: 1, per_page: 25, total, last_page: 1 });

function mount(View, menuKeys) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menuKeys };
  return render(View, { global: { plugins: [pinia], stubs: { 'router-link': true } } });
}

async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

beforeEach(() => {
  vi.clearAllMocks();
  listOutlets.mockResolvedValue({ data: [{ id: 1, name: 'Outlet A' }] });
  listVendors.mockResolvedValue({ data: [], meta: meta(0) });
  listMaterials.mockResolvedValue({ data: [], meta: meta(0) });
});

describe('StockView — struktur halaman (041)', () => {
  const ROW = { id: 1, variant_id: 1, sku: 'SKU-1', type: 'sale', qty_change: -1, stock_before: 2, stock_after: 1, user_name: 'Rina', created_at: '2026-10-04T06:29:00+00:00', product_id: 1 };

  it('memakai PageHeader (satu judul) dan menampilkan "N data" sama dengan total server', async () => {
    listMovements.mockResolvedValue({ data: [ROW], meta: meta(1284) });
    mount(StockView, ['stock']);
    await screen.findByText('SKU-1');
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Pergerakan Stok');
    expect(screen.getByTestId('data-count')).toHaveTextContent('1.284 data');
  });

  it('menyembunyikan penyesuaian/ekspor/impor bagi peran tanpa menu stok (bukan dinonaktifkan)', async () => {
    listMovements.mockResolvedValue({ data: [ROW], meta: meta(1) });
    mount(StockView, ['dashboard']);
    await screen.findByText('SKU-1');
    expect(screen.queryByRole('button', { name: /penyesuaian stok/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /impor/i })).not.toBeInTheDocument();
  });

  it('"Atur ulang filter" hanya muncul saat filter aktif dan mengosongkan tipe + tanggal sekaligus', async () => {
    listMovements.mockResolvedValue({ data: [ROW], meta: meta(1) });
    const user = await userEv();
    mount(StockView, ['stock']);
    await screen.findByText('SKU-1');
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();

    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: 'Penjualan' }));
    await waitFor(() => expect(listMovements).toHaveBeenLastCalledWith(expect.objectContaining({ type: 'sale' })));

    await user.click(screen.getByRole('button', { name: /atur ulang filter/i }));
    await waitFor(() => expect(listMovements.mock.lastCall[0].type).toBeUndefined());
    expect(screen.getByRole('combobox')).toHaveTextContent('Semua tipe');
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();
  });

  it('menampilkan keadaan galat dengan tombol coba lagi yang memuat ulang', async () => {
    listMovements.mockRejectedValueOnce(new Error('network'));
    listMovements.mockResolvedValue({ data: [ROW], meta: meta(1) });
    const user = await userEv();
    mount(StockView, ['stock']);
    expect(await screen.findByRole('alert')).toHaveTextContent('Gagal memuat data');
    expect(screen.queryByTestId('data-count')).not.toBeInTheDocument(); // tidak ada angka palsu saat galat

    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('SKU-1')).toBeInTheDocument();
    expect(listMovements).toHaveBeenCalledTimes(2);
  });
});

describe.each([
  ['VendorsView', VendorsView, listVendors, 'vendors', 'Vendor', /tambah vendor/i],
  ['MaterialsView', MaterialsView, listMaterials, 'materials', 'Bahan Baku', /tambah bahan/i],
])('%s — struktur halaman (041)', (_name, View, fetcher, menu, title, newBtn) => {
  const row = (extra = {}) => ({ id: 1, code: 'C1', name: 'Satu', unit: 'pcs', contact_phone: null, contact_email: null, is_active: true, material_price_count: 0, vendor_price_count: 0, ...extra });

  it('memakai PageHeader dengan tombol utama dan "N data" = total server', async () => {
    fetcher.mockResolvedValue({ data: [row()], meta: meta(37) });
    mount(View, [menu]);
    await screen.findByText('Satu');
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(title);
    expect(screen.getByRole('button', { name: newBtn })).toBeInTheDocument();
    expect(screen.getByTestId('data-count')).toHaveTextContent('37 data');
  });

  it('pencarian dikirim ke server sebagai `search` (debounce) dan "Atur ulang" membersihkannya', async () => {
    fetcher.mockResolvedValue({ data: [row()], meta: meta(1) });
    const user = await userEv();
    mount(View, [menu]);
    await screen.findByText('Satu');
    expect(screen.queryByRole('button', { name: /atur ulang filter/i })).not.toBeInTheDocument();

    await user.type(screen.getByRole('searchbox'), 'abc');
    await waitFor(() => expect(fetcher).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'abc', page: 1 })));

    await user.click(screen.getByRole('button', { name: /atur ulang filter/i }));
    await waitFor(() => expect(fetcher.mock.lastCall[0].search).toBeUndefined());
    expect(screen.getByRole('searchbox')).toHaveValue('');
  });

  it('menampilkan galat dengan coba lagi, tanpa penghitung', async () => {
    fetcher.mockRejectedValueOnce(new Error('network'));
    fetcher.mockResolvedValue({ data: [row()], meta: meta(1) });
    const user = await userEv();
    mount(View, [menu]);
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(screen.queryByTestId('data-count')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Satu')).toBeInTheDocument();
  });

  it('menyembunyikan ekspor/impor bagi peran tanpa menu itu', async () => {
    fetcher.mockResolvedValue({ data: [row()], meta: meta(1) });
    mount(View, ['dashboard']);
    await screen.findByText('Satu');
    expect(screen.queryByRole('button', { name: /ekspor/i })).not.toBeInTheDocument();
  });
});

describe('PurchaseOrdersView — struktur halaman (041)', () => {
  const PO = (id, status, extra = {}) => ({ id, po_number: `PO-${id}`, vendor_name: 'Vendor X', outlet_name: 'Outlet A', status, total_amount: '1000.00', ...extra });

  beforeEach(() => {
    listPurchaseOrders.mockResolvedValue({ data: [PO(1, 'draft'), PO(2, 'ordered')], meta: meta(9) });
  });

  it('memakai PageHeader dan status berupa TabBar; chip jumlah hanya pada tab aktif = total server', async () => {
    mount(PurchaseOrdersView, ['purchase_orders']);
    await screen.findByText('PO-1');
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Purchase Order');

    const tabs = screen.getAllByRole('tab');
    expect(tabs.map((tb) => tb.textContent.replace(/\d+$/, '').trim())).toEqual(['Semua', 'Draft', 'Dipesan', 'Diterima', 'Dibayar', 'Dibatalkan']);
    expect(within(screen.getByRole('tab', { name: /^Semua/ })).getByText('9')).toBeInTheDocument();
    // tab lain tidak mengarang jumlah
    expect(screen.getByRole('tab', { name: 'Draft' }).textContent.trim()).toBe('Draft');
    expect(screen.getByTestId('data-count')).toHaveTextContent('9 data');
  });

  it('memilih tab mengirim filter status (kosong untuk Semua) dan menggeser chip jumlah ke tab itu', async () => {
    const user = await userEv();
    mount(PurchaseOrdersView, ['purchase_orders']);
    await screen.findByText('PO-1');

    listPurchaseOrders.mockResolvedValue({ data: [PO(2, 'ordered')], meta: meta(3) });
    await user.click(screen.getByRole('tab', { name: 'Dipesan' }));
    await waitFor(() => expect(listPurchaseOrders).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'ordered', page: 1 })));
    await waitFor(() => expect(within(screen.getByRole('tab', { name: /^Dipesan/ })).getByText('3')).toBeInTheDocument());
    expect(screen.getByRole('tab', { name: /^Dipesan/ })).toHaveAttribute('aria-selected', 'true');

    await user.click(screen.getByRole('tab', { name: /^Semua/ }));
    await waitFor(() => expect(listPurchaseOrders.mock.lastCall[0].status).toBeUndefined());
  });

  it('filter outlet mengirim outlet_id, menyembunyikan chip jumlah tab, dan "Atur ulang" melepasnya (status tetap)', async () => {
    const user = await userEv();
    mount(PurchaseOrdersView, ['purchase_orders']);
    await screen.findByText('PO-1');
    await waitFor(() => expect(listOutlets).toHaveBeenCalled());
    await user.click(screen.getByRole('tab', { name: 'Draft' }));
    await waitFor(() => expect(listPurchaseOrders).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'draft' })));

    await user.click(screen.getByRole('combobox'));
    await user.click(await screen.findByRole('option', { name: 'Outlet A' }));
    await waitFor(() => expect(listPurchaseOrders).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'draft', outlet_id: 1 })));
    // jumlah tab menggambarkan seluruh bagian, jadi tak ditampilkan saat daftar difilter outlet
    expect(screen.getByRole('tab', { name: 'Draft' }).textContent.trim()).toBe('Draft');

    await user.click(screen.getByRole('button', { name: /atur ulang filter/i }));
    await waitFor(() => expect(listPurchaseOrders.mock.lastCall[0].outlet_id).toBeUndefined());
    expect(listPurchaseOrders.mock.lastCall[0].status).toBe('draft');
  });

  it('menampilkan keadaan galat dengan coba lagi', async () => {
    listPurchaseOrders.mockRejectedValueOnce(new Error('network'));
    const user = await userEv();
    mount(PurchaseOrdersView, ['purchase_orders']);
    expect(await screen.findByRole('alert')).toHaveTextContent('Gagal memuat data');
    expect(screen.queryByTestId('data-count')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('PO-1')).toBeInTheDocument();
  });
});
