import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ProductsView from '../../resources/js/views/ProductsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { i18n } from '../../resources/js/i18n';
import { listProducts, createProduct, uploadProductImage } from '../../resources/js/api/products';
import { listArtists } from '../../resources/js/api/artists';
import { listCategories } from '../../resources/js/api/categories';

vi.mock('../../resources/js/api/products', () => ({
  listProducts: vi.fn(),
  getProduct: vi.fn(),
  createProduct: vi.fn(),
  updateProduct: vi.fn(),
  deleteProduct: vi.fn(),
  addVariant: vi.fn(),
  updateVariant: vi.fn(),
  uploadProductImage: vi.fn(),
}));
vi.mock('../../resources/js/api/artists', () => ({ listArtists: vi.fn() }));
vi.mock('../../resources/js/api/categories', () => ({ listCategories: vi.fn() }));
vi.mock('../../resources/js/api/masterData', () => ({ exportMasterData: vi.fn(), importMasterData: vi.fn(), downloadImportTemplate: vi.fn() }));

function imageFile(name = 'produk.png', type = 'image/png') {
  return new File(['fake-bytes'], name, { type });
}

function renderProducts() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'products'] };
  return render(ProductsView, { global: { plugins: [pinia] } });
}

describe('ProductsView — product image upload', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listProducts.mockResolvedValue({ data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } });
    listArtists.mockResolvedValue({ data: [{ id: 1, name: 'Artist A', code: 'ART' }] });
    listCategories.mockResolvedValue({ data: [{ id: 1, name: 'Kategori A', code: 'KA' }] });
    createProduct.mockResolvedValue({ id: 99, name: 'Produk Baru' });
  });

  it('rejects a non-image file client-side without calling the upload API', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();

    await user.click(await screen.findByRole('button', { name: /produk baru/i }));
    const input = screen.getByLabelText(/gambar produk/i);
    await fireEvent.change(input, { target: { files: [new File(['x'], 'not-image.txt', { type: 'text/plain' })] } });

    expect(screen.getByText(/harus berupa gambar/i)).toBeInTheDocument();
  });

  it('includes the selected image in an upload call after the product is created', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();

    await user.click(await screen.findByRole('button', { name: /produk baru/i }));
    await user.type(screen.getByLabelText(/nama produk/i), 'Produk Baru');

    const input = screen.getByLabelText(/gambar produk/i);
    const file = imageFile();
    await fireEvent.change(input, { target: { files: [file] } });

    await user.click(screen.getByRole('button', { name: /simpan produk/i }));

    await waitFor(() => expect(createProduct).toHaveBeenCalled());
    await waitFor(() => expect(uploadProductImage).toHaveBeenCalledWith(99, file));
  });

  // 002-language-toggle FR-011 — switching locale mid-edit must not wipe
  // out values the user already typed into an open form.
  it('preserves in-progress form input when the language is switched mid-edit', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderProducts();
    i18n.global.locale.value = 'id';

    await user.click(await screen.findByRole('button', { name: /produk baru/i }));
    const nameInput = screen.getByLabelText(/nama produk/i);
    await user.type(nameInput, 'Kaos Polos Hitam');
    expect(nameInput).toHaveValue('Kaos Polos Hitam');

    // Simulate the user switching the app language while the form is open.
    i18n.global.locale.value = 'en';
    await Promise.resolve();

    // The label re-renders in the new language...
    expect(screen.getByLabelText(/product name/i)).toBeInTheDocument();
    // ...but the value the user already typed survives untouched.
    expect(screen.getByLabelText(/product name/i)).toHaveValue('Kaos Polos Hitam');
  });
});
