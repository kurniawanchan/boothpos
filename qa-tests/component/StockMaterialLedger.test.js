import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import StockView from '../../resources/js/views/StockView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { listMovements, lowStock } from '../../resources/js/api/stock';
import { listAllMaterialMovements } from '../../resources/js/api/materials';

// 2026-10-31 (permintaan pemilik) — koreksi stok bahan tampil di log Inventori → Stok, tab Bahan, dengan alasannya.
vi.mock('../../resources/js/api/stock', () => ({ listMovements: vi.fn(), createAdjustment: vi.fn(), lowStock: vi.fn() }));
vi.mock('../../resources/js/api/products', () => ({ lookupVariants: vi.fn(), getProduct: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({ listBomLines: vi.fn(), listAllMaterialMovements: vi.fn() }));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn() }));

const META = { current_page: 1, per_page: 25, total: 1, last_page: 1 };
const ROW = {
  id: 9, material_id: 3, material_code: 'INDM-ST', material_name: 'Indomie Soto', unit: 'pcs', type: 'adjustment',
  outlet_id: 14, outlet_code: 'WDM', qty_change: '20.000', stock_before: '-1.000', stock_after: '19.000',
  reference: { type: 'manual_adjustment', id: null, order_id: null, order_number: null }, reason: 'Opname pagi',
  user: { id: 1, name: 'Owner' }, created_at: '2026-10-31T06:47:34+00:00',
};

function renderStock(menuKeys = ['stock', 'materials']) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menuKeys };
  Object.assign(useOutletStore(), { outlets: [{ id: 14, code: 'WDM', name: 'Warkop WJM Demo' }, { id: 4, code: 'WJ3', name: 'Warkop WJM 3' }], multi: true, selected: null });
  return render(StockView, { global: { plugins: [pinia], mocks: { $route: { query: {} } }, stubs: { 'router-link': true } } });
}

describe('StockView — material ledger tab', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listMovements.mockResolvedValue({ data: [], meta: { ...META, total: 0 } });
    lowStock.mockResolvedValue({ data: [] });
    listAllMaterialMovements.mockResolvedValue({ data: [ROW], meta: META });
  });

  it('lists material movements with the adjustment reason and signed quantity', async () => {
    const user = userEvent.setup();
    renderStock();
    await user.click(screen.getByTestId('stock-ledger-materials'));

    expect(await screen.findByText('Indomie Soto')).toBeInTheDocument();
    expect(screen.getByText('Opname pagi')).toBeInTheDocument();
    expect(screen.getByText('+20 pcs')).toBeInTheDocument();
    expect(listAllMaterialMovements).toHaveBeenCalled();
  });

  it('sends the type filter as an array', async () => {
    const user = userEvent.setup();
    renderStock();
    await user.click(screen.getByTestId('stock-ledger-materials'));
    await screen.findByText('Indomie Soto');

    await user.click(screen.getByTestId('material-ledger-type').querySelector('button'));
    await user.click(await screen.findByRole('option', { name: 'Koreksi' }));
    await waitFor(() => expect(listAllMaterialMovements).toHaveBeenLastCalledWith(expect.objectContaining({ type: ['adjustment'] })));
  });

  it('hides the tab from users who can see neither materials nor stock', () => {
    renderStock(['products']);
    expect(screen.queryByTestId('stock-ledger-materials')).toBeNull();
  });
});
