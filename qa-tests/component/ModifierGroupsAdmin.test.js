import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import ModifierGroupsAdmin from '../../resources/js/components/restaurant/ModifierGroupsAdmin.vue';
import { listModifierGroups, createModifierGroup, updateModifierGroup, deleteModifierGroup, setModifierGroupAttachments } from '../../resources/js/api/modifierGroups';
import { listProducts } from '../../resources/js/api/products';
import { listCategories } from '../../resources/js/api/categories';

vi.mock('../../resources/js/api/modifierGroups', () => ({
  listModifierGroups: vi.fn(), createModifierGroup: vi.fn(), updateModifierGroup: vi.fn(), deleteModifierGroup: vi.fn(), setModifierGroupAttachments: vi.fn(),
}));
vi.mock('../../resources/js/api/products', () => ({ listProducts: vi.fn().mockResolvedValue({ data: [{ id: 5, name: 'Es Kopi' }] }) }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn().mockResolvedValue({ data: [{ id: 3, name: 'Minuman' }] }) }));
vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn().mockResolvedValue({ data: [{ id: 1, name: 'Cabe', unit: 'pcs' }], meta: { last_page: 1 } }) }));

const group = (over) => ({
  id: 1, name: 'Size', selection_type: 'single', is_required: true, min_select: 1, max_select: 1, display_order: 0, is_active: true,
  options: [
    { id: 10, name: 'Regular', price_delta: '0.00', display_order: 0, is_active: true },
    { id: 11, name: 'Large', price_delta: '5000.00', display_order: 1, is_active: true },
    { id: 12, name: 'Lama', price_delta: '0.00', display_order: 2, is_active: false },
  ],
  product_ids: [5], category_ids: [], ...over,
});

function renderAdmin() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(ModifierGroupsAdmin, { global: { plugins: [pinia] } });
}

describe('ModifierGroupsAdmin', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listModifierGroups.mockResolvedValue({ data: [group()] });
    // clearAllMocks tidak mengembalikan implementasi: tes 091 menimpa daftar ini, jadi pulihkan bawaannya.
    listProducts.mockResolvedValue({ data: [{ id: 5, name: 'Es Kopi' }] });
    listCategories.mockResolvedValue({ data: [{ id: 3, name: 'Minuman' }] });
  });

  it('lists groups with their rule, active options and attachment counts', async () => {
    renderAdmin();

    expect(await screen.findByText('Size')).toBeInTheDocument();
    expect(screen.getByText(/pilih satu · wajib · 1/i)).toBeInTheDocument();
    expect(screen.getByText(/regular, large \(\+/i)).toBeInTheDocument();
    expect(screen.queryByText(/lama/i)).toBeNull(); // opsi nonaktif tidak ditampilkan
    expect(screen.getByText('1 produk · 0 kategori')).toBeInTheDocument();
  });

  it('creates a single-choice group without sending min/max, with ordered options', async () => {
    createModifierGroup.mockResolvedValue(group());
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Size');

    await user.click(screen.getByRole('button', { name: /grup modifier baru/i }));
    await user.type(await screen.findByLabelText(/nama grup/i), 'Sugar');
    const rows = screen.getAllByTestId('option-row');
    await user.type(within(rows[0]).getByLabelText('Nama opsi'), 'Normal');
    await user.click(screen.getByRole('button', { name: 'Tambah opsi' }));
    const second = screen.getAllByTestId('option-row')[1];
    await user.type(within(second).getByLabelText('Nama opsi'), 'Less');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(createModifierGroup).toHaveBeenCalledTimes(1));
    const body = createModifierGroup.mock.calls[0][0];
    expect(body).toMatchObject({ name: 'Sugar', selection_type: 'single', is_required: false });
    expect(body).not.toHaveProperty('min_select');
    expect(body).not.toHaveProperty('max_select');
    expect(body.options.map((o) => o.name)).toEqual(['Normal', 'Less']);
    expect(body.options.map((o) => o.display_order)).toEqual([0, 1]);
  });

  it('keeps option ids when editing, so the server updates them instead of recreating', async () => {
    updateModifierGroup.mockResolvedValue(group());
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Size');

    await user.click(screen.getByRole('button', { name: 'Edit' }));
    await screen.findByLabelText(/nama grup/i);
    expect(screen.getAllByTestId('option-row')).toHaveLength(2); // yang nonaktif tidak ikut
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updateModifierGroup).toHaveBeenCalledTimes(1));
    const [id, body] = updateModifierGroup.mock.calls[0];
    expect(id).toBe(1);
    expect(body.options.map((o) => o.id)).toEqual([10, 11]);
  });

  // 2026-10-31 — grup jamak "tiap pilihan = 1 porsi".
  it('sends the portion flag for a multiple-choice group it was switched on for', async () => {
    updateModifierGroup.mockResolvedValue(group());
    listModifierGroups.mockResolvedValue({ data: [group({ selection_type: 'multiple', min_select: 1, max_select: 2, portion_per_option: true })] });
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Size');

    await user.click(screen.getByRole('button', { name: 'Edit' }));
    const box = await screen.findByTestId('mod-portion-per-option');
    expect(box).toBeChecked();
    await user.click(box);
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updateModifierGroup).toHaveBeenCalledTimes(1));
    expect(updateModifierGroup.mock.calls[0][1].portion_per_option).toBe(false);
  });

  it('shows the server validation message next to the field', async () => {
    const { ApiError } = await import('../../resources/js/utils/errors');
    createModifierGroup.mockRejectedValue(new ApiError('x', { status: 422, errors: { name: ['Nama grup sudah dipakai.'] } }));
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Size');

    await user.click(screen.getByRole('button', { name: /grup modifier baru/i }));
    await user.type(await screen.findByLabelText(/nama grup/i), 'Size');
    await user.type(screen.getAllByTestId('option-row')[0].querySelector('input'), 'A');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByText('Nama grup sudah dipakai.')).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument(); // form tetap terbuka agar bisa diperbaiki
  });

  it('saves attachments as a full replacement of products and categories', async () => {
    setModifierGroupAttachments.mockResolvedValue(group());
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Size');

    await user.click(screen.getByRole('button', { name: 'Lampirkan' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(setModifierGroupAttachments).toHaveBeenCalledWith(1, { product_ids: [5], category_ids: [] }));
  });

  // 091 — kosong di dialog Attach berarti "tidak melampirkan apa pun", bukan "Semua".
  it('shows "Tidak ada" (not "Semua") for empty category and product selections', async () => {
    listModifierGroups.mockResolvedValue({ data: [group({ product_ids: [], category_ids: [] })] });
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Size');

    await user.click(screen.getByRole('button', { name: 'Lampirkan' }));
    const dialog = await screen.findByRole('dialog');

    expect(within(dialog).getAllByText('Tidak ada')).toHaveLength(2);
    expect(within(dialog).queryByText(/^semua$/i)).toBeNull();

    // Baris pertama daftar juga "Tidak ada" (memilihnya mengosongkan pilihan).
    await user.click(within(dialog).getAllByRole('button', { name: 'Tidak ada' })[1]); // pemicu kedua = Produk
    expect(within(await screen.findByRole('listbox')).getAllByRole('option')[0]).toHaveTextContent('Tidak ada');
  });

  it('saves an empty attachment set unchanged when nothing is selected', async () => {
    listModifierGroups.mockResolvedValue({ data: [group({ product_ids: [], category_ids: [] })] });
    setModifierGroupAttachments.mockResolvedValue(group());
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Size');

    await user.click(screen.getByRole('button', { name: 'Lampirkan' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(setModifierGroupAttachments).toHaveBeenCalledWith(1, { product_ids: [], category_ids: [] }));
  });

  // 091 — nama pilihan tampil DI BAWAH isian bila ≥ 2 dipilih.
  describe('attach dialog selection names', () => {
    const catalog = () => {
      listProducts.mockResolvedValue({ data: [{ id: 5, name: 'Es Kopi' }, { id: 6, name: 'Kopi Susu' }, { id: 7, name: 'Teh Tarik' }] });
      listCategories.mockResolvedValue({ data: [{ id: 3, name: 'Minuman' }, { id: 4, name: 'Makanan' }] });
    };
    async function openAttach(over) {
      catalog();
      listModifierGroups.mockResolvedValue({ data: [group(over)] });
      const user = userEvent.setup();
      renderAdmin();
      await screen.findByText('Size');
      await user.click(screen.getByRole('button', { name: 'Lampirkan' }));
      const dialog = await screen.findByRole('dialog');
      return { user, dialog };
    }

    it('lists every selected product name below the field, in catalog order', async () => {
      const { dialog } = await openAttach({ product_ids: [7, 5], category_ids: [] });

      expect(within(dialog).getByTestId('attach-products-names')).toHaveTextContent('Es Kopi, Teh Tarik');
      expect(within(dialog).queryByTestId('attach-categories-names')).toBeNull();
    });

    it('lists selected category names below the categories field', async () => {
      const { dialog } = await openAttach({ product_ids: [], category_ids: [3, 4] });

      expect(within(dialog).getByTestId('attach-categories-names')).toHaveTextContent('Minuman, Makanan');
    });

    it('adds no extra list for exactly one selection (the field already shows the name)', async () => {
      const { dialog } = await openAttach({ product_ids: [6], category_ids: [3] });

      expect(within(dialog).queryByTestId('attach-products-names')).toBeNull();
      expect(within(dialog).queryByTestId('attach-categories-names')).toBeNull();
    });

    it('follows checking and unchecking, and returns to "Tidak ada" when emptied', async () => {
      const { user, dialog } = await openAttach({ product_ids: [5], category_ids: [] });
      expect(within(dialog).queryByTestId('attach-products-names')).toBeNull();

      await user.click(within(dialog).getAllByRole('button', { name: /Es Kopi|Tidak ada/ }).at(-1));
      await user.click(within(await screen.findByRole('listbox')).getByRole('option', { name: /Teh Tarik/ }));
      expect(within(dialog).getByTestId('attach-products-names')).toHaveTextContent('Es Kopi, Teh Tarik');

      await user.click(within(screen.getByRole('listbox')).getByRole('option', { name: /Teh Tarik/ }));
      expect(within(dialog).queryByTestId('attach-products-names')).toBeNull();
    });

    it('skips ids that have no matching name and never prints "undefined"', async () => {
      const { dialog } = await openAttach({ product_ids: [5, 6, 999], category_ids: [] });

      const names = within(dialog).getByTestId('attach-products-names');
      expect(names).toHaveTextContent('Es Kopi, Kopi Susu');
      expect(names).not.toHaveTextContent(/undefined/);
      expect(within(dialog).getByText('3 dipilih')).toBeInTheDocument(); // hitungan tetap dari pilihan sebenarnya
    });

    it('keeps the names list height-limited and scrollable for large selections, with Save/Cancel still present', async () => {
      const many = Array.from({ length: 40 }, (_, i) => ({ id: 100 + i, name: `Produk ${i + 1}` }));
      listProducts.mockResolvedValue({ data: many });
      listCategories.mockResolvedValue({ data: [] });
      listModifierGroups.mockResolvedValue({ data: [group({ product_ids: many.map((m) => m.id), category_ids: [] })] });
      const user = userEvent.setup();
      renderAdmin();
      await screen.findByText('Size');
      await user.click(screen.getByRole('button', { name: 'Lampirkan' }));
      const dialog = await screen.findByRole('dialog');

      const names = within(dialog).getByTestId('attach-products-names');
      expect(names).toHaveTextContent('Produk 40');
      expect(names.className).toMatch(/max-h-/);
      expect(names.className).toMatch(/overflow-y-auto/);
      expect(within(dialog).getByRole('button', { name: 'Simpan' })).toBeInTheDocument();
      expect(within(dialog).getByRole('button', { name: 'Batal' })).toBeInTheDocument();
    });

    // BUG YANG DITEMUKAN (091): dialog hanya memuat halaman pertama (per_page 100 = batas server), jadi produk di urutan
    // alfabet akhir tak punya nama ("7 dipilih" tetapi hanya 4 nama) dan juga tak bisa dipilih dari daftar.
    it('loads every page of products and categories, so ids beyond the first 100 resolve to names', async () => {
      const page1 = Array.from({ length: 100 }, (_, i) => ({ id: 1000 + i, name: `A-${i}` }));
      listProducts.mockImplementation(async ({ page = 1 } = {}) => (page === 1
        ? { data: page1, meta: { current_page: 1, last_page: 2 } }
        : { data: [{ id: 5, name: 'Susu Jahe' }, { id: 6, name: 'Milo' }], meta: { current_page: 2, last_page: 2 } }));
      listCategories.mockImplementation(async ({ page = 1 } = {}) => (page === 1
        ? { data: [{ id: 3, name: 'Minuman' }], meta: { current_page: 1, last_page: 2 } }
        : { data: [{ id: 4, name: 'Makanan' }], meta: { current_page: 2, last_page: 2 } }));
      listModifierGroups.mockResolvedValue({ data: [group({ product_ids: [5, 6, 1000], category_ids: [3, 4] })] });
      const user = userEvent.setup();
      renderAdmin();
      await screen.findByText('Size');
      await user.click(screen.getByRole('button', { name: 'Lampirkan' }));
      const dialog = await screen.findByRole('dialog');

      // Tiga id terpilih = tiga nama (tidak ada yang hilang); urutan mengikuti daftar sumber.
      expect(within(dialog).getByTestId('attach-products-names')).toHaveTextContent('A-0, Susu Jahe, Milo');
      expect(within(dialog).getByTestId('attach-categories-names')).toHaveTextContent('Minuman, Makanan');
      expect(listProducts).toHaveBeenCalledWith(expect.objectContaining({ page: 2 }));
    });

    it('saves the same payload as before for the same selection', async () => {
      setModifierGroupAttachments.mockResolvedValue(group());
      const { user, dialog } = await openAttach({ product_ids: [5, 6], category_ids: [3, 4] });
      await user.click(within(dialog).getByRole('button', { name: 'Simpan' }));

      await waitFor(() => expect(setModifierGroupAttachments).toHaveBeenCalledWith(1, { product_ids: [5, 6], category_ids: [3, 4] }));
    });
  });

  it('deletes a group after confirmation', async () => {
    deleteModifierGroup.mockResolvedValue();
    const user = userEvent.setup();
    renderAdmin();
    await screen.findByText('Size');

    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/hapus grup size/i)).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));

    await waitFor(() => expect(deleteModifierGroup).toHaveBeenCalledWith(1));
  });

  // 089 — satu bahan boleh dipakai di BANYAK pilihan; hanya kembar di dalam satu pilihan yang ditolak.
  describe('materials across options and quantity limits (089)', () => {
    const cabe = (qty) => ({ material_id: 1, name: 'Cabe', unit: 'pcs', quantity: qty, code: 'M1', material_active: true });
    const pedas = (optionMaterials) => group({
      name: 'Level Pedas',
      options: [
        { id: 10, name: '1', price_delta: '0.00', min_qty: 1, max_qty: 1, display_order: 0, is_active: true, materials: optionMaterials[0] },
        { id: 11, name: '2', price_delta: '0.00', min_qty: 1, max_qty: 1, display_order: 1, is_active: true, materials: optionMaterials[1] },
        { id: 12, name: '4', price_delta: '0.00', min_qty: 1, max_qty: 1, display_order: 2, is_active: true, materials: optionMaterials[2] },
      ],
    });

    async function openEdit() {
      const user = userEvent.setup();
      renderAdmin();
      await screen.findByText('Level Pedas');
      await user.click(screen.getByRole('button', { name: 'Edit' }));
      await screen.findAllByTestId('option-row');
      return user;
    }

    it('saves when the same material is used by several options (no cross-option duplicate error)', async () => {
      listModifierGroups.mockResolvedValue({ data: [pedas([[cabe('1.0000')], [cabe('2.0000')], [cabe('4.0000')]])] });
      updateModifierGroup.mockResolvedValue(group());
      const user = await openEdit();

      await user.click(screen.getByRole('button', { name: 'Simpan' }));

      await waitFor(() => expect(updateModifierGroup).toHaveBeenCalledTimes(1));
      expect(screen.queryByText(/tidak boleh dipilih dua kali/i)).toBeNull();
    });

    it('blocks a duplicate INSIDE one option and shows the error on that option only', async () => {
      listModifierGroups.mockResolvedValue({ data: [pedas([[cabe('1.0000')], [cabe('2.0000'), cabe('3.0000')], [cabe('4.0000')]])] });
      const user = await openEdit();

      await user.click(screen.getByRole('button', { name: 'Simpan' }));

      expect(updateModifierGroup).not.toHaveBeenCalled();
      const errors = await screen.findAllByText(/tidak boleh dipilih dua kali/i);
      expect(errors).toHaveLength(1);
      const rows = screen.getAllByTestId('option-row');
      expect(within(rows[1]).getByText(/tidak boleh dipilih dua kali/i)).toBeInTheDocument();
      expect(within(rows[0]).queryByText(/tidak boleh dipilih dua kali/i)).toBeNull();
      expect(within(rows[2]).queryByText(/tidak boleh dipilih dua kali/i)).toBeNull();
    });

    it('shows each option\'s min/max quantity (empty maximum for "no limit") and sends them on save', async () => {
      listModifierGroups.mockResolvedValue({ data: [pedas([[], [], []])].map((g) => ({ ...g, options: g.options.map((o, i) => (i === 1 ? { ...o, min_qty: 2, max_qty: null } : o)) })) });
      updateModifierGroup.mockResolvedValue(group());
      const user = await openEdit();
      const rows = screen.getAllByTestId('option-row');
      expect(within(rows[0]).getByLabelText('Jumlah minimum')).toHaveValue(1);
      expect(within(rows[0]).getByLabelText('Jumlah maksimum')).toHaveValue(1);
      expect(within(rows[1]).getByLabelText('Jumlah minimum')).toHaveValue(2);
      expect(within(rows[1]).getByLabelText('Jumlah maksimum')).toHaveValue(null);

      const max = within(rows[2]).getByLabelText('Jumlah maksimum');
      await user.clear(max);
      await user.type(max, '3');
      await user.click(screen.getByRole('button', { name: 'Simpan' }));

      await waitFor(() => expect(updateModifierGroup).toHaveBeenCalledTimes(1));
      const options = updateModifierGroup.mock.calls[0][1].options;
      expect(options.map((o) => [o.min_qty, o.max_qty])).toEqual([[1, 1], [2, null], [1, 3]]);
    });

    it('refuses a minimum above the maximum before calling the API', async () => {
      listModifierGroups.mockResolvedValue({ data: [pedas([[], [], []])] });
      const user = await openEdit();
      const rows = screen.getAllByTestId('option-row');
      const min = within(rows[0]).getByLabelText('Jumlah minimum');
      await user.clear(min);
      await user.type(min, '5');
      await user.click(screen.getByRole('button', { name: 'Simpan' }));

      expect(updateModifierGroup).not.toHaveBeenCalled();
      expect(await screen.findByText(/jumlah minimum tidak boleh lebih besar dari maksimum/i)).toBeInTheDocument();
    });
  });
});
