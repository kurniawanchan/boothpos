import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import { listProducts, productSummary } from '../../resources/js/api/products';
import { ROW, COUNTS, SUMMARY, META, renderProducts } from './productsViewHarness';

// 045 Stage 3 (T059) — daftar Produk mengikuti outlet terpilih dan menampilkan chip per outlet.
vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(), productSummary: vi.fn(), getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(),
  deleteProduct: vi.fn(), addVariant: vi.fn(), updateVariant: vi.fn(), uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 15, total: 0, last_page: 1 } }), createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }), createModifierGroup: vi.fn(), updateModifierGroup: vi.fn(), deleteModifierGroup: vi.fn(), setModifierGroupAttachments: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

const OUTLETS = [{ id: 1, code: 'WJ1', name: 'Warkop WJM 1' }, { id: 2, code: 'WJ2', name: 'Warkop WJM 2' }];

describe('ProductsView outlets (045 Stage 3)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    productSummary.mockResolvedValue(SUMMARY);
    listProducts.mockResolvedValue({ data: [ROW(1, {
      availability: [
        { outlet_id: 1, outlet_code: 'WJ1', state: 'available', reason: null },
        { outlet_id: 2, outlet_code: 'WJ2', state: 'not_carried', reason: null },
      ],
    })], meta: META(1), counts: COUNTS });
  });

  it('filters the list to the selected outlet', async () => {
    await renderProducts({ scope: { outlets: OUTLETS, selected: 2 } });

    await waitFor(() => expect(listProducts).toHaveBeenCalled());
    expect(listProducts.mock.calls[0][0].outlet_id).toEqual([2]);
  });

  it('sends no outlet filter when no outlet is selected', async () => {
    await renderProducts({ scope: { outlets: OUTLETS, selected: null } });

    await waitFor(() => expect(listProducts).toHaveBeenCalled());
    expect(listProducts.mock.calls[0][0].outlet_id).toBeUndefined();
  });

  it('shows one chip per outlet including the not-sold state', async () => {
    await renderProducts({ scope: { outlets: OUTLETS } });

    const chips = await screen.findAllByTestId('availability-chip');
    expect(chips.map((c) => c.textContent.replace(/\s+/g, ' ').trim())).toEqual(expect.arrayContaining(['WJ1 Ada', 'WJ2 Tidak dijual']));
  });
});
