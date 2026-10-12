import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import StockView from '../../resources/js/views/StockView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { listMovements, lowStock, createAdjustment } from '../../resources/js/api/stock';
import { lookupVariants } from '../../resources/js/api/products';

// 045 Stage 5 (T081) — layar Stok per outlet: kolom/filter hanya dengan beberapa outlet; koreksi minta outlet; menipis menyebut outlet.
vi.mock('../../resources/js/api/stock', () => ({ listMovements: vi.fn(), createAdjustment: vi.fn(), lowStock: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ lookupVariants: vi.fn(), getProduct: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({ listBomLines: vi.fn() }));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn() }));

const OUTLETS = [{ id: 1, code: 'WJ1', name: 'Warkop 1' }, { id: 2, code: 'WJ2', name: 'Warkop 2' }];
const MOVE = { id: 1, variant_id: 11, sku: 'SKU-1', type: 'sale', qty_change: -1, stock_before: 3, stock_after: 2, reason: null, created_at: '2026-10-12T01:00:00+00:00', user_name: 'Kasir', variant_name: 'R', product_id: 2, product_name: 'P', reference: null, outlet_id: 2, outlet_code: 'WJ2', outlet_name: 'Warkop 2' };

function renderStock({ multi = true, selected = null, query = '' } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'stock', 'products'] };
  Object.assign(useOutletStore(), { outlets: OUTLETS, multi, selected });
  const route = { query: query ? { low: '1' } : {} };
  return render(StockView, { global: { plugins: [pinia], mocks: { $route: route }, stubs: { 'router-link': true } } });
}

describe('StockView per outlet (045 Stage 5)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listMovements.mockResolvedValue({ data: [MOVE], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
    lowStock.mockResolvedValue({ data: [] });
    lookupVariants.mockResolvedValue({ data: [{ variant_id: 11, sku: 'SKU-1', label: 'P — R', current_stock: 9, outlet_stock: 3 }] });
    createAdjustment.mockResolvedValue({});
  });

  it('shows the outlet column and filter only with several outlets', async () => {
    renderStock();
    expect(await screen.findByText('Warkop 2')).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Outlet' })).toBeInTheDocument();
    expect(screen.getByTestId('stock-outlet-filter')).toBeInTheDocument();
  });

  it('hides them at one outlet', async () => {
    renderStock({ multi: false });
    await screen.findByText('SKU-1');
    expect(screen.queryByRole('columnheader', { name: 'Outlet' })).toBeNull();
    expect(screen.queryByTestId('stock-outlet-filter')).toBeNull();
  });

  it('asks for the outlet in the adjustment, shows the balance AT that outlet and sends outlet_id', async () => {
    const user = userEvent.setup();
    renderStock({ selected: 2 });
    await screen.findByText('SKU-1');
    await user.click(screen.getByRole('button', { name: /penyesuaian stok/i }));

    await user.type(await screen.findByLabelText(/cari varian/i), 'SKU');
    await waitFor(() => expect(lookupVariants).toHaveBeenLastCalledWith('SKU', 8, { stockOutlet: 2 }));
    await user.click(await screen.findByRole('button', { name: /P — R/ }));
    expect(screen.getByText(/SKU-1 · 3 → 3/)).toBeInTheDocument(); // saldo outlet (3), bukan total (9)

    await user.click(screen.getByLabelText(/tambah|increase|naik/i));
    await user.type(screen.getAllByRole('textbox').at(-1), 'opname');
    await user.click(screen.getByRole('button', { name: /simpan penyesuaian/i }));
    await waitFor(() => expect(createAdjustment).toHaveBeenCalled());
    expect(createAdjustment.mock.calls[0][0].outlet_id).toBe(2);
  });
});
