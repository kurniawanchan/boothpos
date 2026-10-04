import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import AddBomItemModal from '../../resources/js/components/product/AddBomItemModal.vue';
import { eligibleBomLines, addBomItems } from '../../resources/js/api/materials';
import { listVendors } from '../../resources/js/api/vendors';

vi.mock('../../resources/js/api/materials', () => ({ eligibleBomLines: vi.fn(), addBomItems: vi.fn() }));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn() }));

const LINE = (id, name, extra = {}) => ({
  purchase_order_item_id: id, purchase_order_id: id, po_number: `PO-00${id}`, po_status: 'ordered', po_date: '2026-09-01T10:00:00+00:00',
  vendor_id: 5, vendor_name: 'Vendor X', line_type: 'material', item_name: name, material_id: id, unit_price: '500.00', po_qty: 1000, in_bom: false, ...extra,
});
const META = { current_page: 1, per_page: 10, total: 3, last_page: 1 };

function renderModal() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(AddBomItemModal, {
    props: { open: true, variantId: 42 },
    global: { plugins: [pinia], stubs: { 'router-link': true } },
  });
}

describe('AddBomItemModal — eligible purchase order lines (034)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listVendors.mockResolvedValue({ data: [{ id: 5, name: 'Vendor X' }] });
    eligibleBomLines.mockResolvedValue({ data: [LINE(1, 'Ball Chain'), LINE(2, 'Ring'), LINE(3, 'Already', { in_bom: true })], meta: META });
  });

  it('lists the lines and disables the ones already in this BOM', async () => {
    renderModal();

    expect(await screen.findByText('Ball Chain')).toBeInTheDocument();
    expect(screen.getByLabelText('Already')).toBeDisabled();
    expect(screen.getByText('Sudah di BOM')).toBeInTheDocument();
    expect(screen.getByLabelText('Ball Chain')).not.toBeDisabled();
  });

  it('adds several selected lines in ONE call and reports them to the parent', async () => {
    const payload = { data: [], summary: { bom_cost: '800.00' } };
    addBomItems.mockResolvedValue(payload);
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { emitted } = renderModal();
    await screen.findByText('Ball Chain');

    const add = screen.getByRole('button', { name: /tambah yang dipilih \(0\)/i });
    expect(add).toBeDisabled();

    await user.click(screen.getByLabelText('Ball Chain'));
    await user.click(screen.getByLabelText('Ring'));
    await user.click(screen.getByRole('button', { name: /tambah yang dipilih \(2\)/i }));

    await waitFor(() => expect(addBomItems).toHaveBeenCalledWith(42, [{ purchase_order_item_id: 1 }, { purchase_order_item_id: 2 }]));
    expect(emitted().added[0]).toEqual([payload]);
    expect(emitted().close).toBeTruthy();
  });

  it('sends the chosen filters to the server and returns to page 1', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('Ball Chain');
    eligibleBomLines.mockClear();

    await user.type(screen.getByLabelText('Cari item'), 'chain');

    await waitFor(() => expect(eligibleBomLines).toHaveBeenCalledWith(42, expect.objectContaining({ q: 'chain', page: 1 })), { timeout: 2000 });
  });

  it('explains an empty result for a seller with no eligible purchase orders', async () => {
    eligibleBomLines.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } });
    renderModal();

    expect(await screen.findByText(/tidak ada baris purchase order yang memenuhi syarat/i)).toBeInTheDocument();
    expect(screen.getByText(/hanya purchase order seller ini/i)).toBeInTheDocument();
  });

  it('keeps the list and reloads when adding fails (e.g. a line was just used elsewhere)', async () => {
    addBomItems.mockRejectedValue({ message: 'Baris sudah ada' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { emitted } = renderModal();
    await screen.findByText('Ball Chain');
    eligibleBomLines.mockClear();

    await user.click(screen.getByLabelText('Ball Chain'));
    await user.click(screen.getByRole('button', { name: /tambah yang dipilih \(1\)/i }));

    await waitFor(() => expect(eligibleBomLines).toHaveBeenCalled());
    expect(emitted().added).toBeUndefined();
  });
});

// 034-seller-po-bom (US6) — mode ganti: pilih SATU baris pengganti untuk baris legacy.
describe('AddBomItemModal — replace mode (034)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listVendors.mockResolvedValue({ data: [] });
    eligibleBomLines.mockResolvedValue({ data: [LINE(1, 'Ball Chain'), LINE(2, 'Ring')], meta: { current_page: 1, per_page: 10, total: 2, last_page: 1 } });
  });

  function renderReplace() {
    const pinia = createPinia();
    setActivePinia(pinia);
    return render(AddBomItemModal, { props: { open: true, variantId: 42, mode: 'replace' }, global: { plugins: [pinia], stubs: { 'router-link': true } } });
  }

  it('allows only ONE selection and emits the chosen line instead of adding to the BOM', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { emitted } = renderReplace();
    await screen.findByText('Ball Chain');

    expect(screen.getByRole('dialog', { name: /pilih baris purchase order yang dipakai/i })).toBeInTheDocument();
    await user.click(screen.getByLabelText('Ball Chain'));
    await user.click(screen.getByLabelText('Ring'));
    expect(screen.getByLabelText('Ball Chain')).not.toBeChecked();
    expect(screen.getByLabelText('Ring')).toBeChecked();

    await user.click(screen.getByRole('button', { name: 'Pakai baris ini' }));

    expect(emitted().picked[0]).toEqual([2]);
    expect(addBomItems).not.toHaveBeenCalled();
  });
});

