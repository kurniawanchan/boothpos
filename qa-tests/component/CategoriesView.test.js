import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import CategoriesView from '../../resources/js/views/CategoriesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listCategories, createCategory, deleteCategory } from '../../resources/js/api/categories';

vi.mock('../../resources/js/api/categories', () => ({
  listCategories: vi.fn(), createCategory: vi.fn(), updateCategory: vi.fn(), deleteCategory: vi.fn(), uploadCategoryImage: vi.fn(),
}));
vi.mock('../../resources/js/api/stations', () => ({ listStations: vi.fn().mockResolvedValue({ data: [] }) }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

const ROWS = [
  { id: 1, code: 'KY', name: 'Keychain', parent_id: null, product_count: 4, display_order: 0, is_active: true },
  { id: 2, code: 'ST', name: 'Stiker', parent_id: 1, product_count: 0, display_order: 1, is_active: false },
];

function renderCategories(menus = ['dashboard', 'categories']) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: menus };
  return render(CategoriesView, { global: { plugins: [pinia] } });
}

// 041 US2 — halaman Kategori memakai kit halaman; perilaku CRUD tetap.
describe('CategoriesView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listCategories.mockResolvedValue({ data: ROWS, meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 } });
  });

  it('renders the PageHeader title/subtitle and lists categories with parent name, product count and status', async () => {
    renderCategories();
    await screen.findByText('Stiker');

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Kategori');
    expect(screen.getByText('Hierarki kategori untuk filter di kasir')).toBeInTheDocument();
    const stikerRow = screen.getByText('Stiker').closest('tr');
    // nama induk berasal dari muatan kedua (semua kategori), jadi tunggu sampai tiba
    await waitFor(() => expect(within(stikerRow).getByText('Keychain')).toBeInTheDocument());
    expect(within(stikerRow).getByText('Nonaktif')).toBeInTheDocument();
    expect(screen.getByText('4')).toBeInTheDocument();
  });

  it('shows the server total as "N data"', async () => {
    renderCategories();
    await screen.findByText('Stiker');
    expect(screen.getByTestId('data-count')).toHaveTextContent('2 data');
  });

  it('puts the primary action in the header and hides all management controls without the categories menu', async () => {
    renderCategories(['dashboard']);
    await screen.findByText('Stiker');

    expect(screen.queryByRole('button', { name: /kategori baru/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ekspor/i })).not.toBeInTheDocument();
    expect(screen.queryByText('Edit')).not.toBeInTheDocument();
  });

  it('searches through the filter bar and resets it', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderCategories();
    await screen.findByText('Stiker');

    await user.type(screen.getByRole('searchbox'), 'sti');
    await waitFor(() => expect(listCategories).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'sti' })));

    await user.click(screen.getByRole('button', { name: /atur ulang filter/i }));
    await waitFor(() => expect(listCategories).toHaveBeenLastCalledWith(expect.not.objectContaining({ search: expect.anything() })));
    expect(screen.getByRole('searchbox')).toHaveValue('');
  });

  it('shows an error state with retry when loading fails', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listCategories.mockRejectedValueOnce(new Error('network'));
    renderCategories();

    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Gagal memuat data');
    await user.click(within(alert).getByRole('button'));
    expect(await screen.findByText('Stiker')).toBeInTheDocument();
  });

  it('creates a category from the header action, upper-casing the code', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createCategory.mockResolvedValue({ id: 9 });
    renderCategories();
    await screen.findByText('Stiker');

    await user.click(screen.getByRole('button', { name: /kategori baru/i }));
    await user.type(await screen.findByLabelText(/kode/i), 'ab');
    await user.type(screen.getByLabelText(/nama kategori/i), 'Poster');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(createCategory).toHaveBeenCalledWith(expect.objectContaining({ code: 'AB', name: 'Poster' })));
  });

  it('deactivates a category after confirmation', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deleteCategory.mockResolvedValue({});
    renderCategories();
    await screen.findByText('Stiker');

    await user.click(within(screen.getByText('Stiker').closest('tr')).getByText('Hapus'));
    await user.click(await screen.findByRole('button', { name: /ya, nonaktifkan/i }));

    await waitFor(() => expect(deleteCategory).toHaveBeenCalledWith(2));
  });
});
