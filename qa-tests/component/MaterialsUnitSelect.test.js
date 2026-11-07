import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import MaterialsTable from '../../resources/js/components/masterData/MaterialsTable.vue';
import MaterialsView from '../../resources/js/views/MaterialsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listMaterials, createMaterial, updateMaterial } from '../../resources/js/api/materials';

vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn(), createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn() }));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn().mockResolvedValue({ data: [] }) }));

// Satuan bahan dipilih dari daftar (bukan diketik); satuan di luar daftar lewat "Lainnya…", dan satuan bahan lama tetap utuh.
const META = { current_page: 1, per_page: 25, total: 1, last_page: 1 };
const ROW = (unit) => ({ id: 5, code: 'TLR', name: 'Telur', unit, kind: 'ingredient', standard_cost: null, is_active: true, vendor_price_count: 0 });

async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

async function openCreate() {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'materials'] };
  render(MaterialsView, { global: { plugins: [pinia] } });
  const user = await userEv();
  await user.click(await screen.findByRole('button', { name: /tambah bahan/i }));
  const dialog = await screen.findByRole('dialog', { name: /bahan baru/i });
  return { user, dialog };
}

async function openEdit(unit) {
  listMaterials.mockResolvedValue({ data: [ROW(unit)], meta: META });
  const pinia = createPinia();
  setActivePinia(pinia);
  render(MaterialsTable, { global: { plugins: [pinia] } });
  const user = await userEv();
  await screen.findByText('Telur');
  await user.click(screen.getByRole('button', { name: 'Edit' }));
  const dialog = await screen.findByRole('dialog', { name: /ubah bahan/i });
  return { user, dialog };
}

describe('MaterialsTable — unit is a picker', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listMaterials.mockResolvedValue({ data: [], meta: { ...META, total: 0 } });
    createMaterial.mockResolvedValue({ id: 9 });
    updateMaterial.mockResolvedValue({ id: 5 });
  });

  it('shows a "Satuan" dropdown with a prompt instead of a free-text box', async () => {
    const { dialog } = await openCreate();

    expect(within(dialog).getByRole('combobox', { name: /satuan/i })).toHaveTextContent(/pilih satuan/i);
    expect(within(dialog).queryByRole('textbox', { name: /satuan/i })).not.toBeInTheDocument();
  });

  it('creates a material with the unit picked from the list', async () => {
    const { user, dialog } = await openCreate();
    await user.type(within(dialog).getByRole('textbox', { name: /kode bahan/i }), 'tlr');
    await user.type(within(dialog).getByRole('textbox', { name: /nama bahan/i }), 'Telur');

    await user.click(within(dialog).getByRole('combobox', { name: /satuan/i }));
    await user.click(await screen.findByRole('option', { name: 'butir' }));
    await user.click(within(dialog).getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(createMaterial).toHaveBeenCalledWith(expect.objectContaining({ code: 'TLR', unit: 'butir' })));
  });

  it('"Lainnya…" reveals a text box and sends what was typed', async () => {
    const { user, dialog } = await openCreate();
    await user.type(within(dialog).getByRole('textbox', { name: /kode bahan/i }), 'tlr');
    await user.type(within(dialog).getByRole('textbox', { name: /nama bahan/i }), 'Telur');

    await user.click(within(dialog).getByRole('combobox', { name: /satuan/i }));
    await user.click(await screen.findByRole('option', { name: /lainnya/i }));
    await user.type(await within(dialog).findByRole('textbox', { name: /satuan lainnya/i }), 'lusin');
    await user.click(within(dialog).getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(createMaterial).toHaveBeenCalledWith(expect.objectContaining({ unit: 'lusin' })));
  });

  it('editing a material with a standard unit preselects it and shows no text box', async () => {
    const { dialog } = await openEdit('ml');

    expect(within(dialog).getByRole('combobox', { name: /satuan/i })).toHaveTextContent('ml');
    expect(within(dialog).queryByRole('textbox', { name: /satuan lainnya/i })).not.toBeInTheDocument();
  });

  it('a legacy material with a custom unit keeps it (shown under "Lainnya…") and saves it unchanged', async () => {
    const { user, dialog } = await openEdit('lusin');

    expect(within(dialog).getByRole('combobox', { name: /satuan/i })).toHaveTextContent(/lainnya/i);
    expect(within(dialog).getByRole('textbox', { name: /satuan lainnya/i })).toHaveValue('lusin');
    await user.click(within(dialog).getByRole('button', { name: /^simpan$/i }));
    await waitFor(() => expect(updateMaterial).toHaveBeenCalledWith(5, expect.objectContaining({ unit: 'lusin' })));
  });

  it('switching from a custom unit to a listed one drops the text box', async () => {
    const { user, dialog } = await openEdit('lusin');

    await user.click(within(dialog).getByRole('combobox', { name: /satuan/i }));
    await user.click(await screen.findByRole('option', { name: 'pcs' }));

    expect(within(dialog).queryByRole('textbox', { name: /satuan lainnya/i })).not.toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: /^simpan$/i }));
    await waitFor(() => expect(updateMaterial).toHaveBeenCalledWith(5, expect.objectContaining({ unit: 'pcs' })));
  });
});
