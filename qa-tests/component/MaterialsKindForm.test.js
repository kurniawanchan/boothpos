import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import MaterialsTable from '../../resources/js/components/masterData/MaterialsTable.vue';
import MaterialsView from '../../resources/js/views/MaterialsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listMaterials, createMaterial, updateMaterial } from '../../resources/js/api/materials';

vi.mock('../../resources/js/api/materials', () => ({ listMaterials: vi.fn(), createMaterial: vi.fn(), updateMaterial: vi.fn(), deleteMaterial: vi.fn() }));
vi.mock('../../resources/js/api/vendors', () => ({ listVendors: vi.fn().mockResolvedValue({ data: [] }) }));

// 043 (T035) — bahan baku punya jenis (Bahan baku / Setengah jadi / Kemasan) dan biaya standar untuk setengah jadi.
const META = { current_page: 1, per_page: 25, total: 1, last_page: 1 };
const ROW = { id: 5, code: 'SJ-01', name: 'Sirup gula', unit: 'ml', kind: 'semi_finished', standard_cost: '12.5000', is_active: true, vendor_price_count: 0 };

function mount(props = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(MaterialsTable, { props, global: { plugins: [pinia] } });
}
async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

describe('MaterialsTable — kind and standard cost (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listMaterials.mockResolvedValue({ data: [ROW], meta: META });
    createMaterial.mockResolvedValue({ id: 9 });
    updateMaterial.mockResolvedValue({ id: 5 });
  });

  it('shows a "Jenis" column with the kind label when no kind filter is given', async () => {
    mount();
    await screen.findByText('Sirup gula');
    expect(screen.getByRole('columnheader', { name: 'Jenis' })).toBeInTheDocument();
    expect(screen.getByText('Setengah jadi')).toBeInTheDocument();
  });

  it('filters the list by kind and drops the redundant column', async () => {
    mount({ kind: 'packaging' });
    await screen.findByText('Sirup gula');
    expect(listMaterials).toHaveBeenCalledWith(expect.objectContaining({ kind: 'packaging' }));
    expect(screen.queryByRole('columnheader', { name: 'Jenis' })).not.toBeInTheDocument();
  });

  it('re-queries with the new kind (and page 1) when the kind prop changes', async () => {
    const view = mount({ kind: 'ingredient' });
    await screen.findByText('Sirup gula');
    await view.rerender({ kind: 'semi_finished' });
    await waitFor(() => expect(listMaterials).toHaveBeenLastCalledWith(expect.objectContaining({ kind: 'semi_finished', page: 1 })));
  });

  it('edits a semi-finished material: kind preselected, standard cost shown and sent as a number', async () => {
    const user = await userEv();
    mount();
    await screen.findByText('Sirup gula');
    await user.click(screen.getByRole('button', { name: 'Edit' }));

    const dialog = await screen.findByRole('dialog', { name: /ubah bahan/i });
    expect(within(dialog).getByText('Setengah jadi')).toBeInTheDocument(); // select jenis
    const cost = within(dialog).getByLabelText(/^biaya standar/i);
    expect(cost).toHaveValue(12.5);
    await user.clear(cost);
    await user.type(cost, '15');
    await user.click(within(dialog).getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(updateMaterial).toHaveBeenCalledWith(5, expect.objectContaining({ kind: 'semi_finished', standard_cost: 15 })));
  });

  it('does not show the standard cost field for a raw material, and does not send it', async () => {
    const user = await userEv();
    listMaterials.mockResolvedValue({ data: [{ ...ROW, kind: 'ingredient', standard_cost: null }], meta: META });
    mount();
    await screen.findByText('Sirup gula');
    await user.click(screen.getByRole('button', { name: 'Edit' }));
    const dialog = await screen.findByRole('dialog', { name: /ubah bahan/i });

    expect(within(dialog).queryByLabelText(/^biaya standar/i)).not.toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: /^simpan$/i }));
    await waitFor(() => expect(updateMaterial).toHaveBeenCalled());
    expect(updateMaterial.mock.calls[0][1]).toMatchObject({ kind: 'ingredient' });
    expect(updateMaterial.mock.calls[0][1]).not.toHaveProperty('standard_cost');
  });
});

// Pembuka formulir tambah hidup di induknya (defineExpose) — diuji lewat layar Bahan Baku yang memakainya.
describe('MaterialsView — creating a material with a kind (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listMaterials.mockResolvedValue({ data: [], meta: { ...META, total: 0 } });
    createMaterial.mockResolvedValue({ id: 9 });
  });

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

  it('defaults to "Bahan baku" with no standard cost field', async () => {
    const { dialog } = await openCreate();
    expect(within(dialog).getByRole('combobox', { name: /jenis bahan/i })).toHaveTextContent('Bahan baku');
    expect(within(dialog).queryByLabelText(/^biaya standar/i)).not.toBeInTheDocument();
  });

  it('switching to semi-finished reveals the standard cost, and both are sent on create', async () => {
    const { user, dialog } = await openCreate();
    await user.type(within(dialog).getByLabelText(/^kode bahan/i), 'sj-02');
    await user.type(within(dialog).getByLabelText(/^nama bahan/i), 'Saus');
    await user.type(within(dialog).getByLabelText(/^satuan/i), 'ml');
    await user.click(within(dialog).getByRole('combobox', { name: /jenis bahan/i }));
    await user.click(await screen.findByRole('option', { name: 'Setengah jadi' }));
    await user.type(within(dialog).getByLabelText(/^biaya standar/i), '20');
    await user.click(within(dialog).getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(createMaterial).toHaveBeenCalledWith(expect.objectContaining({ code: 'SJ-02', kind: 'semi_finished', standard_cost: 20 })));
  });
});
