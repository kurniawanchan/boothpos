import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/vue';
import { nextTick } from 'vue';
import { listProducts, productSummary } from '../../resources/js/api/products';
import { listOutlets } from '../../resources/js/api/outlets';
import { listCategories } from '../../resources/js/api/categories';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { ROW, COUNTS, SUMMARY, META, renderProducts, userEv } from './productsViewHarness';

vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(), productSummary: vi.fn(), getProduct: vi.fn(), createProduct: vi.fn(), updateProduct: vi.fn(), deleteProduct: vi.fn(),
  addVariant: vi.fn(), updateVariant: vi.fn(), uploadProductImage: vi.fn(), uploadVariantImage: vi.fn(),
}));
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } }), createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn() }));
vi.mock('../../resources/js/api/modifierGroups', () => ({ listModifierGroups: vi.fn().mockResolvedValue({ data: [] }), createModifierGroup: vi.fn(), updateModifierGroup: vi.fn(), deleteModifierGroup: vi.fn(), setModifierGroupAttachments: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));
vi.mock('../../resources/js/api/menu', () => ({
  previewPriceChange: vi.fn().mockResolvedValue({ items: [], count: 0, warnings: [] }), applyPriceChange: vi.fn(),
  listMenuChanges: vi.fn().mockResolvedValue({ data: [] }), createMenuRelease: vi.fn(), cancelMenuChange: vi.fn(),
  listApprovals: vi.fn().mockResolvedValue({ threshold_pct: 15, data: [] }), approveApproval: vi.fn(), rejectApproval: vi.fn(),
}));
vi.mock('../../resources/js/api/reasons', () => ({ listReasonCodes: vi.fn().mockResolvedValue({ data: [] }) }));

async function renderWith({ permissions = [], release = false, summary = SUMMARY } = {}) {
  productSummary.mockResolvedValue(summary);
  const view = await renderProducts({ url: '/products?view=table' });
  useAuthStore().user = { ...useAuthStore().user, permissions };
  useSettingsStore().menuReleaseEnabled = release;
  await nextTick();
  return view;
}

// 043 (T057/T068) — pintu masuk ubah harga massal dan banner rilis di daftar Produk.
describe('ProductsView — bulk price and release entry points (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    listProducts.mockResolvedValue({ data: [ROW(1), ROW(2, { category: { id: 4, name: 'Teh' } })], meta: META(2), counts: COUNTS });
    listOutlets.mockResolvedValue({ data: [] });
    listCategories.mockResolvedValue({ data: [{ id: 3, name: 'Kopi' }, { id: 4, name: 'Teh' }] });
  });

  it('renders no "Ubah harga massal" control (header or selection bar) without menu.price.bulk', async () => {
    const user = await userEv();
    await renderWith({ permissions: [] });
    await screen.findByText('Item 1');
    expect(screen.queryByRole('button', { name: 'Ubah harga massal' })).not.toBeInTheDocument();
    await user.click(screen.getAllByRole('checkbox')[1]);
    const bar = await screen.findByRole('toolbar');
    expect(within(bar).queryByRole('button', { name: 'Ubah harga massal' })).not.toBeInTheDocument();
  }, 30000);

  it('offers it in the header and the selection bar with the permission; from a selection the drawer starts with the items\' categories', async () => {
    const user = await userEv();
    await renderWith({ permissions: ['menu.price.bulk'] });
    await screen.findByText('Item 1');
    expect(screen.getByRole('button', { name: 'Ubah harga massal' })).toBeInTheDocument();

    await user.click(screen.getAllByRole('checkbox')[1]); // Item 1 (Kopi, id 3)
    await user.click(screen.getAllByRole('checkbox')[2]); // Item 2 (Teh, id 4)
    const bar = await screen.findByRole('toolbar');
    await user.click(within(bar).getByRole('button', { name: 'Ubah harga massal' }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByTestId('bulk-selection-note')).toBeInTheDocument();
    const boxes = within(within(dialog).getByTestId('bulk-categories')).getAllByRole('checkbox');
    expect(boxes[0]).toBeChecked();
    expect(boxes[1]).toBeChecked();
  }, 30000);

  it('pre-fills the drawer from the active category filter when opened from the header (no selection note)', async () => {
    const user = await userEv();
    productSummary.mockResolvedValue(SUMMARY);
    await renderProducts({ url: '/products?category=4' });
    useAuthStore().user = { ...useAuthStore().user, permissions: ['menu.price.bulk'] };
    await screen.findByText('Item 1');
    await user.click(screen.getByRole('button', { name: 'Ubah harga massal' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).queryByTestId('bulk-selection-note')).not.toBeInTheDocument();
    const boxes = within(within(dialog).getByTestId('bulk-categories')).getAllByRole('checkbox');
    expect(boxes[0]).not.toBeChecked();
    expect(boxes[1]).toBeChecked();
  }, 30000);

  it('shows the release banner only with release on AND menu.release.publish', async () => {
    const summary = { ...SUMMARY, release_enabled: true, unreleased: { count: 2, summary: ['Harga Es Kopi diubah'], active_version: 4 } };
    await renderWith({ permissions: ['menu.release.publish'], release: true, summary });
    await waitFor(() => expect(screen.getByTestId('release-banner')).toHaveTextContent('2 perubahan belum dirilis'));
  }, 30000);

  it('shows no release or approval control at all when release is off, even for a role holding the permissions', async () => {
    await renderWith({ permissions: ['menu.release.publish', 'menu.price.approve'], release: false });
    await screen.findByText('Item 1');
    expect(screen.queryByTestId('release-banner')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Persetujuan harga' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Rilis perubahan' })).not.toBeInTheDocument();
  }, 30000);
});
