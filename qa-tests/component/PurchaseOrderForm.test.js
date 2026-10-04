import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PurchaseOrdersView from '../../resources/js/views/PurchaseOrdersView.vue';
import PurchaseOrderDetailModal from '../../resources/js/components/purchaseOrders/PurchaseOrderDetailModal.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listPurchaseOrders, createPurchaseOrder, updatePurchaseOrder, getPurchaseOrder } from '../../resources/js/api/purchaseOrders';
import { listVendors } from '../../resources/js/api/vendors';
import { listMaterials } from '../../resources/js/api/materials';
import { listArtists } from '../../resources/js/api/artists';

vi.mock('../../resources/js/api/purchaseOrders', () => ({
  listPurchaseOrders: vi.fn(),
  createPurchaseOrder: vi.fn(),
  updatePurchaseOrder: vi.fn(),
  deletePurchaseOrder: vi.fn(),
  updatePurchaseOrderStatus: vi.fn(),
  getPurchaseOrder: vi.fn(),
  recordPurchaseOrderPayment: vi.fn(),
  getPurchaseOrderInvoice: vi.fn(),
}));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));

const ARTISTS = [{ id: 1, name: 'Seller A' }, { id: 2, name: 'Seller B' }];

const DRAFT_WITH_LINK = {
  id: 10, po_number: 'PO-10', vendor_id: 5, vendor_name: 'Vendor X', artist_id: 1, artist_name: 'Seller A',
  status: 'draft', total_amount: '5000.00', notes: null,
  items: [{ id: 1, line_type: 'material', material_id: 3, product_id: 7, description: null, qty: '10', unit_price: '500.00', line_total: '5000.00' }],
};
const LEGACY = { id: 11, po_number: 'PO-11', vendor_id: 5, vendor_name: 'Vendor X', artist_id: null, artist_name: null, status: 'ordered', total_amount: '0.00', items: [], payments: [] };

function renderView() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'purchase_orders'] };
  return render(PurchaseOrdersView, { global: { plugins: [pinia] } });
}

// 034-seller-po-bom (US2) — seller wajib pada PO, "Linked Product" tidak
// lagi ditampilkan, PO lama tanpa seller ditandai dan bisa ditetapkan.
describe('PurchaseOrdersView — seller (034)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // Daftar TIDAK membawa items (seperti API sungguhan); detail lewat getPurchaseOrder.
    listPurchaseOrders.mockResolvedValue({ data: [{ ...DRAFT_WITH_LINK, items: [] }, LEGACY], meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
    getPurchaseOrder.mockResolvedValue(DRAFT_WITH_LINK);
    listVendors.mockResolvedValue({ data: [{ id: 5, name: 'Vendor X' }] });
    listMaterials.mockResolvedValue({ data: [{ id: 3, name: 'Ball Chain' }] });
    listArtists.mockResolvedValue({ data: ARTISTS });
  });

  it('shows a Seller column, the seller name, and a muted "no seller" label for legacy purchase orders', async () => {
    renderView();
    await screen.findByText('PO-10');
    expect(screen.getByRole('columnheader', { name: 'Penjual' })).toBeInTheDocument();
    expect(screen.getByText('Seller A')).toBeInTheDocument();
    expect(screen.getByText('Belum ada penjual')).toBeInTheDocument();
  });

  it('the create form has a required Seller field and no "Linked Product" control', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText('PO-10');
    await user.click(screen.getByRole('button', { name: /PO Baru/i }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Penjual')).toBeInTheDocument();
    expect(within(dialog).queryByText('Produk Terkait')).not.toBeInTheDocument();
  });

  it('shows the server-side seller error under the field when no seller was chosen', async () => {
    createPurchaseOrder.mockRejectedValue({ isValidation: true, errors: { artist_id: ['Penjual wajib dipilih.'] }, message: 'invalid' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText('PO-10');
    await user.click(screen.getByRole('button', { name: /PO Baru/i }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByText('Penjual wajib dipilih.')).toBeInTheDocument();
  });

  it('keeps sending an existing line\'s product_id when editing a draft, even though the field is hidden', async () => {
    updatePurchaseOrder.mockResolvedValue({ ...DRAFT_WITH_LINK });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText('PO-10');
    // 035 — setiap baris kini punya Edit; ambil yang milik draft PO-10.
    await user.click(within(screen.getByText('PO-10').closest('tr')).getByRole('button', { name: 'Edit' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updatePurchaseOrder).toHaveBeenCalled());
    expect(getPurchaseOrder).toHaveBeenCalledWith(10);
    const [id, payload] = updatePurchaseOrder.mock.calls[0];
    expect(id).toBe(10);
    expect(payload.artist_id).toBe(1);
    expect(payload.items).toHaveLength(1);
    expect(payload.items[0].product_id).toBe(7);
  });
});

describe('PurchaseOrderDetailModal — assign seller to a legacy purchase order (034)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listArtists.mockResolvedValue({ data: ARTISTS });
  });

  function renderModal() {
    const pinia = createPinia();
    setActivePinia(pinia);
    return render(PurchaseOrderDetailModal, { props: { open: true, purchaseOrderId: 11 }, global: { plugins: [pinia] } });
  }

  it('offers "Assign seller" for a legacy purchase order and sends the chosen seller', async () => {
    getPurchaseOrder.mockResolvedValue(LEGACY);
    updatePurchaseOrder.mockResolvedValue({ ...LEGACY, artist_id: 2, artist_name: 'Seller B' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();

    expect(await screen.findByText('Belum ada penjual')).toBeInTheDocument();
    const assign = screen.getByRole('button', { name: 'Tetapkan penjual' });
    expect(assign).toBeDisabled();

    await user.click(screen.getByRole('combobox'));
    await user.click(await screen.findByRole('option', { name: 'Seller B' }));
    await user.click(assign);

    await waitFor(() => expect(updatePurchaseOrder).toHaveBeenCalledWith(11, { artist_id: 2 }));
    expect(await screen.findByText('Seller B')).toBeInTheDocument();
  });

  it('does not offer assignment when the purchase order already has a seller', async () => {
    getPurchaseOrder.mockResolvedValue({ ...DRAFT_WITH_LINK, payments: [] });
    renderModal();

    expect(await screen.findByText('Seller A')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Tetapkan penjual' })).not.toBeInTheDocument();
  });
});
