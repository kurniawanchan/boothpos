import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PurchaseOrdersView from '../../resources/js/views/PurchaseOrdersView.vue';
import PurchaseOrderDetailModal from '../../resources/js/components/purchaseOrders/PurchaseOrderDetailModal.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listPurchaseOrders, createPurchaseOrder, updatePurchaseOrder, getPurchaseOrder } from '../../resources/js/api/purchaseOrders';
import { listVendors } from '../../resources/js/api/vendors';
import { listMaterials } from '../../resources/js/api/materials';
import { listOutlets } from '../../resources/js/api/outlets';

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
vi.mock('../../resources/js/api/outlets', () => ({ listOutlets: vi.fn() }));

const OUTLETS = [{ id: 1, name: 'Outlet A' }, { id: 2, name: 'Outlet B' }];

const DRAFT_WITH_LINK = {
  id: 10, po_number: 'PO-10', vendor_id: 5, vendor_name: 'Vendor X', outlet_id: 1, outlet_name: 'Outlet A',
  status: 'draft', total_amount: '5000.00', notes: null,
  items: [{ id: 1, line_type: 'material', material_id: 3, product_id: 7, description: null, qty: '10', unit_price: '500.00', line_total: '5000.00' }],
};
const LEGACY = { id: 11, po_number: 'PO-11', vendor_id: 5, vendor_name: 'Vendor X', outlet_id: null, outlet_name: null, status: 'ordered', total_amount: '0.00', items: [], payments: [] };

function renderView() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'purchase_orders'] };
  return render(PurchaseOrdersView, { global: { plugins: [pinia] } });
}

// 034-seller-po-bom (US2) — outlet wajib pada PO, "Linked Product" tidak
// lagi ditampilkan, PO lama tanpa outlet ditandai dan bisa ditetapkan.
describe('PurchaseOrdersView — outlet (034)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // Daftar TIDAK membawa items (seperti API sungguhan); detail lewat getPurchaseOrder.
    listPurchaseOrders.mockResolvedValue({ data: [{ ...DRAFT_WITH_LINK, items: [] }, LEGACY], meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
    getPurchaseOrder.mockResolvedValue(DRAFT_WITH_LINK);
    listVendors.mockResolvedValue({ data: [{ id: 5, name: 'Vendor X' }] });
    listMaterials.mockResolvedValue({ data: [{ id: 3, name: 'Ball Chain' }] });
    listOutlets.mockResolvedValue({ data: OUTLETS });
  });

  it('shows an Outlet column, the outlet name, and a muted "no outlet" label for legacy purchase orders', async () => {
    renderView();
    await screen.findByText('PO-10');
    expect(screen.getByRole('columnheader', { name: 'Outlet' })).toBeInTheDocument();
    expect(screen.getByText('Outlet A')).toBeInTheDocument();
    expect(screen.getByText('Belum ada outlet')).toBeInTheDocument();
  });

  it('the create form has a required Outlet field and no "Linked Product" control', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText('PO-10');
    await user.click(screen.getByRole('button', { name: /PO Baru/i }));

    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText('Outlet')).toBeInTheDocument();
    expect(within(dialog).queryByText('Produk Terkait')).not.toBeInTheDocument();
  });

  it('shows the server-side outlet error under the field when no outlet was chosen', async () => {
    createPurchaseOrder.mockRejectedValue({ isValidation: true, errors: { outlet_id: ['Outlet wajib dipilih.'] }, message: 'invalid' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText('PO-10');
    await user.click(screen.getByRole('button', { name: /PO Baru/i }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByText('Outlet wajib dipilih.')).toBeInTheDocument();
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
    expect(payload.outlet_id).toBe(1);
    expect(payload.items).toHaveLength(1);
    expect(payload.items[0].product_id).toBe(7);
  });
});

describe('PurchaseOrderDetailModal — assign outlet to a legacy purchase order (034)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listOutlets.mockResolvedValue({ data: OUTLETS });
  });

  function renderModal() {
    const pinia = createPinia();
    setActivePinia(pinia);
    return render(PurchaseOrderDetailModal, { props: { open: true, purchaseOrderId: 11 }, global: { plugins: [pinia] } });
  }

  it('offers "Assign outlet" for a legacy purchase order and sends the chosen outlet', async () => {
    getPurchaseOrder.mockResolvedValue(LEGACY);
    updatePurchaseOrder.mockResolvedValue({ ...LEGACY, outlet_id: 2, outlet_name: 'Outlet B' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();

    expect(await screen.findByText('Belum ada outlet')).toBeInTheDocument();
    const assign = screen.getByRole('button', { name: 'Tetapkan outlet' });
    expect(assign).toBeDisabled();

    await user.click(screen.getByRole('combobox'));
    await user.click(await screen.findByRole('option', { name: 'Outlet B' }));
    await user.click(assign);

    await waitFor(() => expect(updatePurchaseOrder).toHaveBeenCalledWith(11, { outlet_id: 2 }));
    expect(await screen.findByText('Outlet B')).toBeInTheDocument();
  });

  it('does not offer assignment when the purchase order already has an outlet', async () => {
    getPurchaseOrder.mockResolvedValue({ ...DRAFT_WITH_LINK, payments: [] });
    renderModal();

    expect(await screen.findByText('Outlet A')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Tetapkan outlet' })).not.toBeInTheDocument();
  });
});
