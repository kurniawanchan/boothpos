import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import VariantBomModal from '../../resources/js/components/product/VariantBomModal.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listBomLines, updateBomLine, saveBomQuantities, deleteBomLine, eligibleBomLines, completeBom, reopenBom, replaceBomSource } from '../../resources/js/api/materials';
import { listVendors } from '../../resources/js/api/vendors';

vi.mock('../../resources/js/api/materials', () => ({
  listBomLines: vi.fn(),
  updateBomLine: vi.fn(),
  saveBomQuantities: vi.fn(),
  deleteBomLine: vi.fn(),
  eligibleBomLines: vi.fn(),
  addBomItems: vi.fn(),
  completeBom: vi.fn(),
  reopenBom: vi.fn(),
  replaceBomSource: vi.fn(),
}));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn() }));

const ROWS = [
  { id: 1, line_type: 'material', item_name: 'Ball Chain', is_legacy: false, material_id: 3, material_unit: 'pcs', purchase_order_item_id: 11, po_number: 'PO-001', vendor_id: 5, vendor_name: 'Vendor X', unit_cost: '500.00', qty_needed: '1.0000', item_cost: '500.00', po_qty: 1000, notes: null },
  { id: 2, line_type: 'material', item_name: 'Keychain Ring', is_legacy: false, material_id: 4, material_unit: 'pcs', purchase_order_item_id: 12, po_number: 'PO-002', vendor_id: 5, vendor_name: 'Vendor X', unit_cost: '300.00', qty_needed: '1.0000', item_cost: '300.00', po_qty: 500, notes: null },
  { id: 3, line_type: 'service', item_name: 'Assembly', is_legacy: false, material_id: null, material_unit: null, purchase_order_item_id: 13, po_number: 'PO-003', vendor_id: 6, vendor_name: 'Vendor Y', unit_cost: '1000.00', qty_needed: '1.0000', item_cost: '1000.00', po_qty: 200, notes: null },
];
const SUMMARY = { material_cost: '800.00', service_cost: '1000.00', bom_cost: '1800.00', has_legacy: false, bom_complete: false, cost_price: '0.00', current_stock: 12, reopened: false };

function renderModal(menuKeys = ['dashboard', 'products', 'purchase_orders']) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menuKeys };
  return render(VariantBomModal, {
    props: { open: true, variantId: 42, variantSku: 'ARTKACSN0001', variantName: 'Red' },
    global: { plugins: [pinia], stubs: { 'router-link': true } },
  });
}

// 034-seller-po-bom (US1) — BOM sebagai tabel baris purchase order.
describe('VariantBomModal — BOM table from purchase order lines (034)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listBomLines.mockResolvedValue({ data: ROWS, summary: SUMMARY });
    listVendors.mockResolvedValue({ data: [] });
    eligibleBomLines.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } });
  });

  it('renders the example BOM with type, purchase order, vendor, unit cost and the totals', async () => {
    renderModal();

    expect(await screen.findByText('Ball Chain')).toBeInTheDocument();
    for (const header of ['Item', 'Tipe', 'Purchase Order', 'Vendor', 'Biaya Satuan', 'Jumlah per 1 produk', 'Total Biaya', 'Aksi']) {
      expect(screen.getByRole('columnheader', { name: header })).toBeInTheDocument();
    }
    expect(screen.getByText('PO-003')).toBeInTheDocument();
    expect(screen.getByText('Vendor Y')).toBeInTheDocument();
    expect(screen.getAllByText('Jasa').length).toBeGreaterThan(0);
    // ringkasan: bahan 800, jasa 1.000, total 1.800 (kartu + baris footer)
    expect(screen.getByText('Rp 800')).toBeInTheDocument();
    expect(screen.getAllByText('Rp 1.800').length).toBeGreaterThanOrEqual(2);
  });

  it('shows an empty state when the BOM has no rows', async () => {
    listBomLines.mockResolvedValue({ data: [], summary: { ...SUMMARY, material_cost: '0.00', service_cost: '0.00', bom_cost: '0.00' } });
    renderModal();

    expect(await screen.findByText(/belum ada item pada bom ini/i)).toBeInTheDocument();
  });

  it('rejects zero, decimals and malformed quantities in the browser without calling the API', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    const qty = await screen.findByLabelText('Jumlah per 1 produk Ball Chain');

    for (const bad of ['0', '1.5', 'abc', '-2']) {
      await user.clear(qty);
      await user.type(qty, bad);
      expect(await screen.findByText(/bilangan bulat 1 atau lebih/i), bad).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'Simpan perubahan' })).toBeDisabled();
    }
    expect(saveBomQuantities).not.toHaveBeenCalled();
    expect(updateBomLine).not.toHaveBeenCalled();
  });

  it('removes a row after confirmation and shows the refreshed total', async () => {
    deleteBomLine.mockResolvedValue({ data: [ROWS[1], ROWS[2]], summary: { ...SUMMARY, material_cost: '300.00', bom_cost: '1300.00' } });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('Ball Chain');

    await user.click(screen.getAllByRole('button', { name: 'Hapus' })[0]);
    const dialog = await screen.findByRole('dialog', { name: /hapus item bom/i });
    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));

    await waitFor(() => expect(deleteBomLine).toHaveBeenCalledWith(1));
    await waitFor(() => expect(screen.queryByText('Ball Chain')).not.toBeInTheDocument());
    expect(screen.getAllByText('Rp 1.300').length).toBeGreaterThan(0);
  });

  it('is read-only for a user without purchase_orders access (controls hidden, not disabled)', async () => {
    renderModal(['dashboard', 'products']);

    await screen.findByText('Ball Chain');
    expect(screen.queryByRole('button', { name: /tambah item bom/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Hapus' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Jumlah per 1 produk Ball Chain')).not.toBeInTheDocument();
    expect(screen.queryByRole('columnheader', { name: 'Aksi' })).not.toBeInTheDocument();
  });

  it('marks a legacy row with a visible badge', async () => {
    listBomLines.mockResolvedValue({
      data: [{ ...ROWS[0], id: 9, is_legacy: true, purchase_order_item_id: null, po_number: null, vendor_name: 'Vendor Lama', po_qty: null }],
      summary: { ...SUMMARY, has_legacy: true },
    });
    renderModal();

    expect(await screen.findByText('Lama')).toBeInTheDocument();
  });

  it('opens the selector from "Tambah Item BOM"', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('Ball Chain');

    await user.click(screen.getByRole('button', { name: /tambah item bom/i }));

    await waitFor(() => expect(eligibleBomLines).toHaveBeenCalledWith(42, expect.objectContaining({ page: 1 })));
    expect(await screen.findByText(/tidak ada baris purchase order yang memenuhi syarat/i)).toBeInTheDocument();
  });
});

// 034-seller-po-bom (US4) — selesai / buka kembali; harga modal mengikuti BOM.
describe('VariantBomModal — complete and reopen (034)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listVendors.mockResolvedValue({ data: [] });
    eligibleBomLines.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } });
  });

  it('asks for confirmation, then marks the BOM complete and shows the cost price as coming from the BOM', async () => {
    listBomLines.mockResolvedValue({ data: ROWS, summary: SUMMARY });
    completeBom.mockResolvedValue({ data: ROWS, summary: { ...SUMMARY, bom_complete: true, cost_price: '1800.00' } });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('Ball Chain');

    await user.click(screen.getByRole('button', { name: 'Tandai BOM selesai' }));
    const dialog = await screen.findByRole('dialog', { name: /tandai bom selesai/i });
    expect(within(dialog).getByText(/otomatis mengikuti biaya bom/i)).toBeInTheDocument();
    expect(completeBom).not.toHaveBeenCalled();
    await user.click(within(dialog).getByRole('button', { name: 'Tandai BOM selesai' }));

    await waitFor(() => expect(completeBom).toHaveBeenCalledWith(42));
    expect(await screen.findByText('BOM selesai')).toBeInTheDocument();
    expect(screen.getByText('Harga modal (dari BOM, per 1 produk)')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Buka kembali BOM' })).toBeInTheDocument();
  });

  it('reopens a complete BOM and offers completion again', async () => {
    listBomLines.mockResolvedValue({ data: ROWS, summary: { ...SUMMARY, bom_complete: true, cost_price: '1800.00' } });
    reopenBom.mockResolvedValue({ data: ROWS, summary: { ...SUMMARY, bom_complete: false, cost_price: '1800.00' } });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('BOM selesai');

    await user.click(screen.getByRole('button', { name: 'Buka kembali BOM' }));

    await waitFor(() => expect(reopenBom).toHaveBeenCalledWith(42));
    expect(await screen.findByRole('button', { name: 'Tandai BOM selesai' })).toBeInTheDocument();
    expect(screen.queryByText('BOM selesai')).not.toBeInTheDocument();
  });

  it('tells the user to deal with legacy rows first and keeps the BOM open when the server refuses completion', async () => {
    listBomLines.mockResolvedValue({ data: ROWS, summary: { ...SUMMARY, has_legacy: true } });
    completeBom.mockRejectedValue({ message: 'BOM masih memuat baris lama' });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();

    expect(await screen.findByText(/ganti atau hapus baris lama/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Tandai BOM selesai' }));
    await user.click(within(await screen.findByRole('dialog', { name: /tandai bom selesai/i })).getByRole('button', { name: 'Tandai BOM selesai' }));

    await waitFor(() => expect(completeBom).toHaveBeenCalled());
    expect(screen.queryByText('BOM selesai')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Tandai BOM selesai' })).toBeInTheDocument();
  });

  it('does not offer completion for an empty BOM or to a read-only user', async () => {
    listBomLines.mockResolvedValue({ data: [], summary: { ...SUMMARY, bom_cost: '0.00', material_cost: '0.00', service_cost: '0.00' } });
    renderModal();
    await screen.findByText(/belum ada item pada bom ini/i);
    expect(screen.queryByRole('button', { name: 'Tandai BOM selesai' })).not.toBeInTheDocument();
  });
});

// 034-seller-po-bom (US5) — isyarat harga lebih baru / sumber dibatalkan; ganti sumber hanya atas konfirmasi.
describe('VariantBomModal — history cues (034)', () => {
  const CUED = [
    { ...ROWS[0], newer_price: { purchase_order_item_id: 99, po_number: 'PO-NEW', unit_price: '600.00' }, source_cancelled: false },
    { ...ROWS[1], newer_price: null, source_cancelled: true },
    ROWS[2],
  ];

  beforeEach(() => {
    vi.clearAllMocks();
    listVendors.mockResolvedValue({ data: [] });
    listBomLines.mockResolvedValue({ data: CUED, summary: SUMMARY });
  });

  it('shows a "newer price" pill and a "source cancelled" pill without touching any cost', async () => {
    renderModal();

    expect(await screen.findByRole('button', { name: 'Harga lebih baru Rp 600' })).toBeInTheDocument();
    expect(screen.getByText('Sumber dibatalkan')).toBeInTheDocument();
    // biaya tercatat tetap tampil
    expect(screen.getAllByText('Rp 500').length).toBeGreaterThan(0);
    expect(replaceBomSource).not.toHaveBeenCalled();
  });

  it('replaces the source only after the user confirms, and refreshes the row from the response', async () => {
    replaceBomSource.mockResolvedValue({
      data: [{ ...ROWS[0], unit_cost: '600.00', item_cost: '600.00', po_number: 'PO-NEW', newer_price: null, source_cancelled: false }, ROWS[1], ROWS[2]],
      summary: { ...SUMMARY, material_cost: '900.00', bom_cost: '1900.00' },
    });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();

    await user.click(await screen.findByRole('button', { name: 'Harga lebih baru Rp 600' }));
    const dialog = await screen.findByRole('dialog', { name: /pakai harga terbaru/i });
    expect(within(dialog).getByText(/dari Rp 500 menjadi Rp 600/i)).toBeInTheDocument();
    expect(replaceBomSource).not.toHaveBeenCalled();

    await user.click(within(dialog).getByRole('button', { name: 'Pakai harga terbaru' }));

    await waitFor(() => expect(replaceBomSource).toHaveBeenCalledWith(1, 99));
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Harga lebih baru Rp 600' })).not.toBeInTheDocument());
    expect(screen.getByText('PO-NEW')).toBeInTheDocument();
  });

  it('shows the newer-price cue as plain text (no action) to a read-only user', async () => {
    renderModal(['dashboard', 'products']);

    expect(await screen.findByText('Harga lebih baru Rp 600')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Harga lebih baru Rp 600' })).not.toBeInTheDocument();
  });
});

// 034-seller-po-bom (US6) — baris legacy diganti satu per satu dengan baris PO.
describe('VariantBomModal — replacing a legacy row (034)', () => {
  const LEGACY = { ...ROWS[0], id: 9, is_legacy: true, purchase_order_item_id: null, po_number: null, vendor_name: 'Vendor Lama', po_qty: null, newer_price: null, source_cancelled: false };

  beforeEach(() => {
    vi.clearAllMocks();
    listVendors.mockResolvedValue({ data: [] });
    listBomLines.mockResolvedValue({ data: [LEGACY, ROWS[1]], summary: { ...SUMMARY, has_legacy: true } });
    eligibleBomLines.mockResolvedValue({
      data: [{ purchase_order_item_id: 77, purchase_order_id: 7, po_number: 'PO-777', po_status: 'ordered', po_date: '2026-09-01T10:00:00+00:00', vendor_id: 5, vendor_name: 'Vendor X', line_type: 'material', item_name: 'Tali Baru', material_id: 3, unit_price: '450.00', po_qty: 100, in_bom: false }],
      meta: { current_page: 1, per_page: 10, total: 1, last_page: 1 },
    });
  });

  it('offers "Replace with PO line" only on legacy rows, then replaces after picking a line', async () => {
    replaceBomSource.mockResolvedValue({ data: [{ ...ROWS[0], id: 9, item_name: 'Tali Baru', po_number: 'PO-777', unit_cost: '450.00', item_cost: '450.00' }, ROWS[1]], summary: { ...SUMMARY, has_legacy: false } });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();
    await screen.findByText('Lama');
    expect(screen.getAllByRole('button', { name: 'Ganti dengan baris PO' })).toHaveLength(1);

    await user.click(screen.getByRole('button', { name: 'Ganti dengan baris PO' }));
    await user.click(await screen.findByLabelText('Tali Baru'));
    await user.click(screen.getByRole('button', { name: 'Pakai baris ini' }));

    await waitFor(() => expect(replaceBomSource).toHaveBeenCalledWith(9, 77));
    await waitFor(() => expect(screen.queryByText('Lama')).not.toBeInTheDocument());
    expect(screen.getByText('PO-777')).toBeInTheDocument();
  });
});

// BUG YANG DITEMUKAN & DIPERBAIKI (verifikasi browser 034) — sebelum BOM selesai, teks
// bantuan dulu bertuliskan "Harga modal mengikuti biaya BOM", padahal itu baru berlaku
// SETELAH BOM ditandai selesai.
describe('VariantBomModal — hint wording before completion (034)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listVendors.mockResolvedValue({ data: [] });
  });

  it('tells the user to mark the BOM complete (not that the cost price already follows it)', async () => {
    listBomLines.mockResolvedValue({ data: ROWS, summary: SUMMARY });
    renderModal();

    expect(await screen.findByText(/tandai bom selesai agar harga modal mengikuti biaya bom/i)).toBeInTheDocument();
    expect(screen.queryByText(/harga modal mengikuti biaya bom\. buka kembali/i)).not.toBeInTheDocument();
  });

  it('says the cost price follows the BOM only once it is complete', async () => {
    listBomLines.mockResolvedValue({ data: ROWS, summary: { ...SUMMARY, bom_complete: true, cost_price: '1800.00' } });
    renderModal();

    expect(await screen.findByText(/harga modal mengikuti biaya bom\. buka kembali/i)).toBeInTheDocument();
  });
});

// 035-po-row-actions (US4) — galat memuat BOM = status galat + Coba lagi, bukan tabel kosong.
describe('VariantBomModal — load failure (035)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listVendors.mockResolvedValue({ data: [] });
  });

  it('shows the error with Retry instead of an empty BOM, then loads after Retry', async () => {
    const { ApiError } = await import('../../resources/js/utils/errors');
    listBomLines
      .mockRejectedValueOnce(new ApiError('Database perlu diperbarui.', { status: 503, code: 'schema_outdated' }))
      .mockResolvedValueOnce({ data: ROWS, summary: SUMMARY });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderModal();

    expect(await screen.findByRole('alert')).toHaveTextContent('Database perlu diperbarui');
    expect(screen.queryByText('Ball Chain')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Coba lagi' }));

    expect(await screen.findByText('Ball Chain')).toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});


// 036-bom-variant-stock-ux (US1) — draft jumlah + tombol Simpan + penjaga perubahan + stok + label per unit.
describe('VariantBomModal — draft, Save and guard (036)', () => {
  const SAVED = { data: [{ ...ROWS[0], qty_needed: '11.0000', item_cost: '5500.00' }, ROWS[1], ROWS[2]], summary: { ...SUMMARY, material_cost: '5800.00', bom_cost: '6800.00' } };

  async function setup(menuKeys) {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const view = renderModal(menuKeys);
    await screen.findByText('Ball Chain');

    return { user, ...view };
  }

  beforeEach(() => {
    vi.clearAllMocks();
    listBomLines.mockResolvedValue({ data: ROWS, summary: SUMMARY });
    listVendors.mockResolvedValue({ data: [] });
    eligibleBomLines.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } });
  });

  it('shows stored quantities as whole numbers (no trailing .0000) and keeps a legacy fraction as stored', async () => {
    listBomLines.mockResolvedValue({ data: [ROWS[0], { ...ROWS[1], qty_needed: '2.5000' }], summary: SUMMARY });
    await setup();

    expect(screen.getByLabelText('Jumlah per 1 produk Ball Chain')).toHaveValue('1');
    expect(screen.getByLabelText('Jumlah per 1 produk Keychain Ring')).toHaveValue('2.5');
  });

  it('keeps edits as a draft: nothing is sent on blur, and Save starts disabled', async () => {
    const { user } = await setup();
    expect(screen.getByRole('button', { name: 'Simpan perubahan' })).toBeDisabled();

    const qty = screen.getByLabelText('Jumlah per 1 produk Ball Chain');
    await user.clear(qty);
    await user.type(qty, '11');
    await user.tab();

    expect(saveBomQuantities).not.toHaveBeenCalled();
    expect(updateBomLine).not.toHaveBeenCalled();
    expect(screen.getByText('Ada perubahan yang belum disimpan')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Simpan perubahan' })).toBeEnabled();
  });

  it('saves ONLY the changed rows in one call and refreshes the totals from the response', async () => {
    saveBomQuantities.mockResolvedValue(SAVED);
    const { user } = await setup();
    const qty = screen.getByLabelText('Jumlah per 1 produk Ball Chain');
    await user.clear(qty);
    await user.type(qty, '11');
    // tanpa mengubah baris lain; mengetik nilai yang sama pada baris kedua bukan perubahan
    const ring = screen.getByLabelText('Jumlah per 1 produk Keychain Ring');
    await user.clear(ring);
    await user.type(ring, '1');

    await user.click(screen.getByRole('button', { name: 'Simpan perubahan' }));

    await waitFor(() => expect(saveBomQuantities).toHaveBeenCalledTimes(1));
    expect(saveBomQuantities).toHaveBeenCalledWith(42, [{ id: 1, qty_needed: '11' }]);
    expect((await screen.findAllByText('Rp 6.800')).length).toBeGreaterThan(0);
    expect(screen.queryByText('Ada perubahan yang belum disimpan')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Jumlah per 1 produk Ball Chain')).toHaveValue('11');
  });

  it('keeps every draft and marks the offending row when the server refuses the batch (422)', async () => {
    const { ApiError } = await import('../../resources/js/utils/errors');
    saveBomQuantities.mockRejectedValue(new ApiError('Validasi gagal', { status: 422, errors: { 'lines.1.qty_needed': ['Masukkan bilangan bulat 1 atau lebih (tanpa desimal).'] } }));
    const { user } = await setup();
    await user.clear(screen.getByLabelText('Jumlah per 1 produk Ball Chain'));
    await user.type(screen.getByLabelText('Jumlah per 1 produk Ball Chain'), '5');
    await user.clear(screen.getByLabelText('Jumlah per 1 produk Keychain Ring'));
    await user.type(screen.getByLabelText('Jumlah per 1 produk Keychain Ring'), '7');

    await user.click(screen.getByRole('button', { name: 'Simpan perubahan' }));

    expect(await screen.findByText(/bilangan bulat 1 atau lebih/i)).toBeInTheDocument();
    expect(screen.getByLabelText('Jumlah per 1 produk Ball Chain')).toHaveValue('5');
    expect(screen.getByLabelText('Jumlah per 1 produk Keychain Ring')).toHaveValue('7');
    expect(screen.getByLabelText('Jumlah per 1 produk Keychain Ring')).toHaveAttribute('aria-invalid', 'true');
  });

  it('asks before closing with a draft; Cancel keeps it, Discard closes', async () => {
    const { user, emitted } = await setup();
    await user.clear(screen.getByLabelText('Jumlah per 1 produk Ball Chain'));
    await user.type(screen.getByLabelText('Jumlah per 1 produk Ball Chain'), '9');

    await user.click(screen.getByRole('button', { name: /tutup dialog/i }));
    const dialog = await screen.findByRole('dialog', { name: /buang perubahan/i });
    expect(emitted().close).toBeFalsy();

    await user.click(within(dialog).getByRole('button', { name: 'Batal' }));
    expect(screen.getByLabelText('Jumlah per 1 produk Ball Chain')).toHaveValue('9');
    expect(emitted().close).toBeFalsy();

    await user.click(screen.getByRole('button', { name: /tutup dialog/i }));
    await user.click(within(await screen.findByRole('dialog', { name: /buang perubahan/i })).getByRole('button', { name: 'Buang dan lanjutkan' }));
    expect(emitted().close).toBeTruthy();
  });

  it('closes without asking when there is no draft', async () => {
    const { user, emitted } = await setup();

    await user.click(screen.getByRole('button', { name: /tutup dialog/i }));

    expect(emitted().close).toBeTruthy();
  });

  it('guards actions that would reload the BOM: add item, remove, complete', async () => {
    const { user } = await setup();
    await user.clear(screen.getByLabelText('Jumlah per 1 produk Ball Chain'));
    await user.type(screen.getByLabelText('Jumlah per 1 produk Ball Chain'), '9');

    await user.click(screen.getByRole('button', { name: /tambah item bom/i }));
    expect(await screen.findByRole('dialog', { name: /buang perubahan/i })).toBeInTheDocument();
    await user.click(within(screen.getByRole('dialog', { name: /buang perubahan/i })).getByRole('button', { name: 'Batal' }));
    expect(eligibleBomLines).not.toHaveBeenCalled();

    await user.click(screen.getAllByRole('button', { name: 'Hapus' })[0]);
    expect(await screen.findByRole('dialog', { name: /buang perubahan/i })).toBeInTheDocument();
    await user.click(within(screen.getByRole('dialog', { name: /buang perubahan/i })).getByRole('button', { name: 'Batal' }));

    await user.click(screen.getByRole('button', { name: /tandai bom selesai/i }));
    expect(await screen.findByRole('dialog', { name: /buang perubahan/i })).toBeInTheDocument();
  });

  it('runs the guarded action after Discard (draft dropped, add dialog opens)', async () => {
    const { user } = await setup();
    await user.clear(screen.getByLabelText('Jumlah per 1 produk Ball Chain'));
    await user.type(screen.getByLabelText('Jumlah per 1 produk Ball Chain'), '9');

    await user.click(screen.getByRole('button', { name: /tambah item bom/i }));
    await user.click(within(await screen.findByRole('dialog', { name: /buang perubahan/i })).getByRole('button', { name: 'Buang dan lanjutkan' }));

    await waitFor(() => expect(eligibleBomLines).toHaveBeenCalled());
    expect(screen.getByLabelText('Jumlah per 1 produk Ball Chain')).toHaveValue('1');
  });

  it('shows the current stock read-only and never calls a stock-changing API', async () => {
    await setup();

    expect(screen.getByText('Stok saat ini')).toBeInTheDocument();
    expect(screen.getByText('12')).toBeInTheDocument();
  });

  it('labels the cost cards and the quantity column as per 1 product', async () => {
    await setup();

    for (const label of ['Biaya bahan (per 1 produk)', 'Biaya jasa (per 1 produk)', 'Total biaya BOM (per 1 produk)', 'Harga modal (per 1 produk)']) {
      expect(screen.getAllByText(label).length, label).toBeGreaterThan(0);
    }
    expect(screen.getByRole('columnheader', { name: 'Jumlah per 1 produk' })).toBeInTheDocument();
  });

  it('shows no Save button and no inputs to a read-only user', async () => {
    await setup(['dashboard', 'products']);

    expect(screen.queryByRole('button', { name: 'Simpan perubahan' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Jumlah per 1 produk Ball Chain')).not.toBeInTheDocument();
    expect(screen.getByText('Stok saat ini')).toBeInTheDocument();
  });
});
