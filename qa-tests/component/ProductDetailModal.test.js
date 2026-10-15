import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ProductDetailModal from '../../resources/js/components/product/ProductDetailModal.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { getProduct } from '../../resources/js/api/products';
import { listMovements } from '../../resources/js/api/stock';
import { listBomLines } from '../../resources/js/api/materials';

vi.mock('../../resources/js/api/products', () => ({ getProduct: vi.fn() }));
vi.mock('../../resources/js/api/stock', () => ({ listMovements: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({
  listBomLines: vi.fn(), updateBomLine: vi.fn(), saveBomQuantities: vi.fn(), deleteBomLine: vi.fn(), eligibleBomLines: vi.fn(),
  addBomItems: vi.fn(), completeBom: vi.fn(), reopenBom: vi.fn(), replaceBomSource: vi.fn(), copyBomFrom: vi.fn(), copyBomOut: vi.fn(),
}));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn().mockResolvedValue({ data: [] }) }));

const PRODUCT = {
  id: 2, name: 'MCYT', code_prefix: 'SPF-KC-MCY', artist_name: 'sapphirefiless', category_name: 'KEYCHAIN', is_active: true, is_preorder: false, description: null,
  variants: [
    { id: 10, sku: 'SPF-KC-MCY-001', variant_name: 'Red', sell_price: '20000.00', current_stock: 5, is_active: true, image_url: null },
    { id: 11, sku: 'SPF-KC-MCY-002', variant_name: 'Blue', sell_price: '20000.00', current_stock: 9, is_active: true, image_url: null },
  ],
};

function renderModal(menuKeys = ['dashboard', 'products', 'stock'], props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menuKeys };
  return render(ProductDetailModal, { props: { open: true, productId: 2, ...props }, global: { plugins: [pinia], stubs: { 'router-link': true } } });
}

// 036-bom-variant-stock-ux (US3/US5) — riwayat per varian dan sorotan varian dari layar Stok.
describe('ProductDetailModal (036)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getProduct.mockResolvedValue(PRODUCT);
    listMovements.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } });
    listBomLines.mockResolvedValue({ data: [], summary: { material_cost: '0.00', service_cost: '0.00', bom_cost: '0.00', has_legacy: false, bom_complete: false, cost_price: '0.00', current_stock: 5, reopened: false } });
  });

  it('opens the history of THAT variant from its row', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('Blue');

    const row = screen.getByText('SPF-KC-MCY-002').closest('tr');
    await user.click(within(row).getByRole('button', { name: 'Riwayat' }));

    await waitFor(() => expect(listMovements).toHaveBeenCalledWith(expect.objectContaining({ variant_id: 11 })));
    expect(await screen.findByRole('dialog', { name: /riwayat transaksi — SPF-KC-MCY-002/i })).toBeInTheDocument();
  });

  it('keeps the BOM button working beside History', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('Red');

    const row = screen.getByText('SPF-KC-MCY-001').closest('tr');
    await user.click(within(row).getByRole('button', { name: 'BOM' }));

    await waitFor(() => expect(listBomLines).toHaveBeenCalledWith(10));
  });

  it('hides History from a user with neither stock nor products access', async () => {
    renderModal(['dashboard', 'sales']);
    await screen.findByText('Blue');

    expect(screen.queryByRole('button', { name: 'Riwayat' })).not.toBeInTheDocument();
  });

  it('highlights and scrolls to the variant named by highlightVariantId', async () => {
    const scrollIntoView = vi.fn();
    window.HTMLElement.prototype.scrollIntoView = scrollIntoView;
    renderModal(['dashboard', 'products', 'stock'], { highlightVariantId: 11 });
    await screen.findByText('Blue');

    const row = screen.getByText('SPF-KC-MCY-002').closest('tr');
    expect(row).toHaveAttribute('aria-current', 'true');
    expect(screen.getByText('SPF-KC-MCY-001').closest('tr')).not.toHaveAttribute('aria-current');
    await waitFor(() => expect(scrollIntoView).toHaveBeenCalled());
  });
});
