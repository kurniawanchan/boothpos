import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import PurchaseOrdersView from '../../resources/js/views/PurchaseOrdersView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listPurchaseOrders, getPurchaseOrder, deletePurchaseOrder, updatePurchaseOrder } from '../../resources/js/api/purchaseOrders';
import { listVendors } from '../../resources/js/api/vendors';
import { listMaterials } from '../../resources/js/api/materials';
import { listArtists } from '../../resources/js/api/artists';

vi.mock('../../resources/js/api/purchaseOrders', () => ({
  listPurchaseOrders: vi.fn(),
  getPurchaseOrder: vi.fn(),
  createPurchaseOrder: vi.fn(),
  updatePurchaseOrder: vi.fn(),
  deletePurchaseOrder: vi.fn(),
  updatePurchaseOrderStatus: vi.fn(),
  recordPurchaseOrderPayment: vi.fn(),
  getPurchaseOrderInvoice: vi.fn(),
}));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn() }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn() }));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));

const PO = (id, number, status, extra = {}) => ({
  id, po_number: number, vendor_id: 5, vendor_name: 'Vendor X', artist_id: 1, artist_name: 'Seller A',
  status, total_amount: '5000.00', paid_amount: '0.00', notes: null, items: [], payments: [], ...extra,
});
const ROWS = [
  PO(1, 'PO-DRAFT', 'draft'),
  PO(2, 'PO-ORD', 'ordered'),
  PO(3, 'PO-RCV', 'received'),
  PO(4, 'PO-PAID', 'paid'),
  PO(5, 'PO-CANC', 'cancelled'),
];
const FULL = (row, extra = {}) => ({
  ...row,
  items: [{ id: 11, line_type: 'material', material_id: 3, material_name: 'Ball Chain', product_id: null, description: null, qty: '10', unit_price: '500.00', line_total: '5000.00', used_in_bom_count: 0 }],
  ...extra,
});

function renderView() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'purchase_orders'] };
  return render(PurchaseOrdersView, { global: { plugins: [pinia] } });
}

const rowOf = (number) => screen.getByText(number).closest('tr');

// 035-po-row-actions (US1) — Detail / Edit / Delete di setiap baris, apa pun statusnya.
describe('PurchaseOrdersView — row actions (035)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    // Daftar TIDAK membawa items (seperti API sungguhan); detail lewat getPurchaseOrder.
    listPurchaseOrders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 5, last_page: 1 } });
    getPurchaseOrder.mockImplementation((id) => Promise.resolve(FULL(ROWS.find((r) => r.id === id))));
    listVendors.mockResolvedValue({ data: [{ id: 5, name: 'Vendor X' }] });
    listMaterials.mockResolvedValue({ data: [{ id: 3, name: 'Ball Chain' }] });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Seller A' }] });
  });

  it('shows Detail, Edit and Delete on the row of EVERY status', async () => {
    renderView();
    await screen.findByText('PO-DRAFT');

    for (const number of ['PO-DRAFT', 'PO-ORD', 'PO-RCV', 'PO-PAID', 'PO-CANC']) {
      const row = within(rowOf(number));
      expect(row.getByRole('button', { name: 'Detail' }), `${number} Detail`).toBeInTheDocument();
      expect(row.getByRole('button', { name: 'Edit' }), `${number} Edit`).toBeInTheDocument();
      expect(row.getByRole('button', { name: 'Hapus' }), `${number} Delete`).toBeInTheDocument();
    }
  });

  it('keeps Delete enabled only for a draft; every other status shows it disabled with the "cancel instead" reason', async () => {
    renderView();
    await screen.findByText('PO-DRAFT');

    expect(within(rowOf('PO-DRAFT')).getByRole('button', { name: 'Hapus' })).toBeEnabled();
    for (const number of ['PO-ORD', 'PO-RCV', 'PO-PAID', 'PO-CANC']) {
      const del = within(rowOf(number)).getByRole('button', { name: 'Hapus' });
      expect(del, number).toBeDisabled();
      expect(del, number).toHaveAttribute('title', expect.stringMatching(/batalkan/i));
    }
  });

  it('keeps Edit enabled for every status', async () => {
    renderView();
    await screen.findByText('PO-DRAFT');

    for (const number of ['PO-DRAFT', 'PO-ORD', 'PO-RCV', 'PO-PAID', 'PO-CANC']) {
      expect(within(rowOf(number)).getByRole('button', { name: 'Edit' }), number).toBeEnabled();
    }
  });

  it('Detail opens the same detail as clicking the PO number', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText('PO-PAID');

    await user.click(within(rowOf('PO-PAID')).getByRole('button', { name: 'Detail' }));

    await waitFor(() => expect(getPurchaseOrder).toHaveBeenCalledWith(4));
    expect(await screen.findByRole('dialog', { name: 'PO-PAID' })).toBeInTheDocument();
  });

  it('still shows the status actions next to the new ones', async () => {
    renderView();
    await screen.findByText('PO-ORD');

    expect(within(rowOf('PO-DRAFT')).getByRole('button', { name: 'Tandai Dipesan' })).toBeInTheDocument();
    expect(within(rowOf('PO-ORD')).getByRole('button', { name: 'Tandai Diterima' })).toBeInTheDocument();
    expect(within(rowOf('PO-RCV')).getByRole('button', { name: 'Tandai Dibayar' })).toBeInTheDocument();
    expect(within(rowOf('PO-PAID')).queryByRole('button', { name: /Tandai/ })).not.toBeInTheDocument();
  });

  it('Delete on a draft asks for confirmation naming the PO', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText('PO-DRAFT');

    await user.click(within(rowOf('PO-DRAFT')).getByRole('button', { name: 'Hapus' }));

    const dialog = await screen.findByRole('dialog', { name: /hapus purchase order/i });
    expect(within(dialog).getByText(/PO-DRAFT/)).toBeInTheDocument();
    expect(deletePurchaseOrder).not.toHaveBeenCalled();
  });
});

// 035-po-row-actions (US2) — Edit di setiap status.
describe('PurchaseOrdersView — Edit at any status (035)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listPurchaseOrders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 5, last_page: 1 } });
    getPurchaseOrder.mockImplementation((id) => Promise.resolve(FULL(ROWS.find((r) => r.id === id))));
    updatePurchaseOrder.mockResolvedValue({});
    listVendors.mockResolvedValue({ data: [{ id: 5, name: 'Vendor X' }] });
    listMaterials.mockResolvedValue({ data: [{ id: 3, name: 'Ball Chain' }] });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Seller A' }] });
  });

  async function openEditFor(number) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText(number);
    await user.click(within(rowOf(number)).getByRole('button', { name: 'Edit' }));
    const dialog = await screen.findByRole('dialog', { name: /edit purchase order/i });
    return { user, dialog };
  }

  it('Edit on a PAID order shows the existing lines read-only with the explanation and saves only vendor/seller/notes', async () => {
    const { user, dialog } = await openEditFor('PO-PAID');

    expect(getPurchaseOrder).toHaveBeenCalledWith(4);
    expect(within(dialog).getByText(/item hanya bisa diubah selama status masih draft/i)).toBeInTheDocument();
    expect(within(dialog).getByText('Ball Chain')).toBeInTheDocument();
    expect(within(dialog).queryByRole('button', { name: /tambah baris/i })).not.toBeInTheDocument();

    await user.click(within(dialog).getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updatePurchaseOrder).toHaveBeenCalled());
    const [id, payload] = updatePurchaseOrder.mock.calls[0];
    expect(id).toBe(4);
    expect(payload).toMatchObject({ vendor_id: 5, artist_id: 1 });
    expect(payload).not.toHaveProperty('items');
  });

  it('Edit works the same way for ordered, received and cancelled orders (lines locked, no items sent)', async () => {
    for (const [number, id] of [['PO-ORD', 2], ['PO-RCV', 3], ['PO-CANC', 5]]) {
      vi.clearAllMocks();
      listPurchaseOrders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 5, last_page: 1 } });
      getPurchaseOrder.mockImplementation((i) => Promise.resolve(FULL(ROWS.find((r) => r.id === i))));
      updatePurchaseOrder.mockResolvedValue({});
      const { user, dialog } = await openEditFor(number);

      expect(within(dialog).getByText(/item hanya bisa diubah/i), number).toBeInTheDocument();
      await user.click(within(dialog).getByRole('button', { name: 'Simpan' }));
      await waitFor(() => expect(updatePurchaseOrder).toHaveBeenCalled());
      expect(updatePurchaseOrder.mock.calls[0][0]).toBe(id);
      expect(updatePurchaseOrder.mock.calls[0][1], number).not.toHaveProperty('items');
      document.body.innerHTML = '';
    }
  });

  it('Edit on a DRAFT keeps the lines editable and sends them', async () => {
    const { user, dialog } = await openEditFor('PO-DRAFT');

    expect(within(dialog).queryByText(/item hanya bisa diubah selama status masih draft/i)).not.toBeInTheDocument();
    expect(within(dialog).getByRole('button', { name: /tambah baris/i })).toBeInTheDocument();

    await user.click(within(dialog).getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updatePurchaseOrder).toHaveBeenCalled());
    expect(updatePurchaseOrder.mock.calls[0][1].items).toHaveLength(1);
  });

  it('shows the server\'s seller-lock message under the Seller field and keeps the drawer open', async () => {
    updatePurchaseOrder.mockRejectedValue({ isConflict: true, isValidation: false, message: 'konflik', errors: { artist_id: ['Seller tidak bisa diubah karena baris sudah dipakai BOM.'] } });
    const { user, dialog } = await openEditFor('PO-ORD');

    await user.click(within(dialog).getByRole('button', { name: 'Simpan' }));

    expect(await within(dialog).findByText(/seller tidak bisa diubah karena baris sudah dipakai bom/i)).toBeInTheDocument();
    expect(screen.getByRole('dialog', { name: /edit purchase order/i })).toBeInTheDocument();
  });
});

// 035-po-row-actions (US3) — Delete.
describe('PurchaseOrdersView — Delete (035)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listPurchaseOrders.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 5, last_page: 1 } });
    deletePurchaseOrder.mockResolvedValue(undefined);
    listVendors.mockResolvedValue({ data: [{ id: 5, name: 'Vendor X' }] });
    listMaterials.mockResolvedValue({ data: [] });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Seller A' }] });
  });

  async function openDelete() {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText('PO-DRAFT');
    await user.click(within(rowOf('PO-DRAFT')).getByRole('button', { name: 'Hapus' }));
    const dialog = await screen.findByRole('dialog', { name: /hapus purchase order/i });
    return { user, dialog };
  }

  it('confirming deletes the draft and reloads the list', async () => {
    const { user, dialog } = await openDelete();
    listPurchaseOrders.mockClear();

    await user.click(within(dialog).getByRole('button', { name: 'Ya, hapus' }));

    await waitFor(() => expect(deletePurchaseOrder).toHaveBeenCalledWith(1));
    await waitFor(() => expect(listPurchaseOrders).toHaveBeenCalled());
  });

  it('cancelling the confirmation deletes nothing', async () => {
    const { user, dialog } = await openDelete();

    await user.click(within(dialog).getByRole('button', { name: 'Batal' }));

    expect(deletePurchaseOrder).not.toHaveBeenCalled();
    await waitFor(() => expect(screen.queryByRole('dialog', { name: /hapus purchase order/i })).not.toBeInTheDocument());
  });

  it('a non-draft Delete is disabled and clicking it never calls the API', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderView();
    await screen.findByText('PO-PAID');

    await user.click(within(rowOf('PO-PAID')).getByRole('button', { name: 'Hapus' }));

    expect(deletePurchaseOrder).not.toHaveBeenCalled();
    expect(screen.queryByRole('dialog', { name: /hapus purchase order/i })).not.toBeInTheDocument();
  });

  it('when the server refuses (stale list), the dialog closes and the list is reloaded', async () => {
    deletePurchaseOrder.mockRejectedValue({ isConflict: true, message: 'Hanya purchase order draft yang bisa dihapus.' });
    const { user, dialog } = await openDelete();
    listPurchaseOrders.mockClear();

    await user.click(within(dialog).getByRole('button', { name: 'Ya, hapus' }));

    await waitFor(() => expect(listPurchaseOrders).toHaveBeenCalled());
    await waitFor(() => expect(screen.queryByRole('dialog', { name: /hapus purchase order/i })).not.toBeInTheDocument());
  });
});

