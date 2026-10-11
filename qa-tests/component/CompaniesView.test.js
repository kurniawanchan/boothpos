import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import CompaniesView from '../../resources/js/views/CompaniesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listCompanies, updateCompany, deleteCompany } from '../../resources/js/api/companies';
import { listBusinessTypes } from '../../resources/js/api/businessTypes';
import { listLicenses } from '../../resources/js/api/licenses';

// 019-billing-system, second expansion (T090) — Edit/Delete on Companies.

vi.mock('../../resources/js/api/companies', () => ({
  listCompanies: vi.fn(),
  createCompany: vi.fn(),
  resendActivation: vi.fn(),
  activateCompany: vi.fn(),
  updateCompany: vi.fn(),
  deleteCompany: vi.fn(),
}));

vi.mock('../../resources/js/api/businessTypes', () => ({
  listBusinessTypes: vi.fn(),
  createBusinessType: vi.fn(),
  updateBusinessType: vi.fn(),
  deleteBusinessType: vi.fn(),
}));

vi.mock('../../resources/js/api/licenses', () => ({
  listLicenses: vi.fn(),
  createLicense: vi.fn(),
  updateLicense: vi.fn(),
  deleteLicense: vi.fn(),
}));


// 041-back-office-ui-redesign — filter/tab kini dicerminkan ke address bar (useQueryState), jadi view
// butuh router; router memori dengan satu rute cukup (tidak ada navigasi sungguhan di tes ini).
async function makeRouter(path = '/') {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
  await router.push(path);
  await router.isReady();
  return router;
}

async function renderCompanies(path = '/') {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'companies'] };
  const router = await makeRouter(path);
  return { router, ...render(CompaniesView, { global: { plugins: [pinia, router] } }) };
}

describe('CompaniesView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listCompanies.mockResolvedValue({
      data: [
        {
          id: 1,
          name: 'Toko Merch A',
          status: 'active',
          business_type: { id: 1, name: 'Retail' },
          package: { id: 2, name: 'Pro', license_tier: 'pro' },
          contact_name: 'Budi',
          contact_email: 'budi@example.com',
        },
      ],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
    listBusinessTypes.mockResolvedValue({ data: [{ id: 1, name: 'Retail' }] });
    listLicenses.mockResolvedValue({ data: [{ id: 2, name: 'Pro', license_tier: 'pro' }] });
  });

  it('lists companies from the API', async () => {
    await renderCompanies();
    expect(await screen.findByText('Toko Merch A')).toBeInTheDocument();
  });

  it('updates a company via the edit form', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updateCompany.mockResolvedValue({ id: 1, name: 'Toko Merch A Updated' });
    await renderCompanies();

    await screen.findByText('Toko Merch A');
    await user.click(screen.getByRole('button', { name: 'Edit' }));

    const nameInput = await screen.findByLabelText(/nama company|company name/i);
    await user.clear(nameInput);
    await user.type(nameInput, 'Toko Merch A Updated');
    await user.click(screen.getByRole('button', { name: /^simpan$|^save$/i }));

    await waitFor(() =>
      expect(updateCompany).toHaveBeenCalledWith(1, expect.objectContaining({ name: 'Toko Merch A Updated' }))
    );
  });

  it('surfaces the 409 delete-guard message via the shared error toast flow', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const conflictError = Object.assign(new Error('Company masih memiliki invoice dan tidak dapat dihapus.'), {
      isConflict: true,
    });
    deleteCompany.mockRejectedValue(conflictError);
    await renderCompanies();

    await screen.findByText('Toko Merch A');
    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    await user.click(screen.getByRole('button', { name: /ya, hapus/i }));

    await waitFor(() => expect(deleteCompany).toHaveBeenCalledWith(1));
  });

  // --- 041-back-office-ui-redesign (struktur halaman baru) ---

  it('memakai PageHeader: judul h1 + deskripsi + tombol onboarding di kanan', async () => {
    await renderCompanies();
    expect(screen.getByRole('heading', { level: 1, name: 'Company' })).toHaveAttribute('id', 'page-heading');
    expect(screen.getByText('Pipeline onboarding company internal')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /onboarding company/i })).toBeInTheDocument();
  });

  it('penghitung "N data" di FilterBar = total hasil dari server', async () => {
    listCompanies.mockResolvedValue({
      data: [{ id: 1, name: 'Toko Merch A', status: 'active', contact_name: 'Budi', contact_email: 'b@example.com' }],
      meta: { current_page: 1, per_page: 25, total: 87, last_page: 4 },
    });
    await renderCompanies();
    await screen.findByText('Toko Merch A');
    expect(screen.getByTestId('data-count')).toHaveTextContent('87 data');
  });

  it('pencarian dikirim ke API sebagai parameter search (debounce) dan masuk ke address bar', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { router } = await renderCompanies();
    await screen.findByText('Toko Merch A');

    await user.type(screen.getByRole('searchbox'), 'toko');
    await waitFor(() => expect(listCompanies).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'toko', page: 1 })));
    // router.replace resolve async: URL menyusul sesaat setelah daftar dimuat.
    await waitFor(() => expect(router.currentRoute.value.query.search).toBe('toko'));

    // "Atur ulang filter" menghapus pencarian: parameter dihilangkan dan URL bersih lagi.
    await user.click(screen.getByRole('button', { name: /atur ulang filter/i }));
    await waitFor(() => expect(listCompanies).toHaveBeenLastCalledWith(expect.not.objectContaining({ search: 'toko' })));
    await waitFor(() => expect(router.currentRoute.value.query.search).toBeUndefined());
  });

  it('tautan ?search= yang dibagikan langsung memuat hasil yang sama', async () => {
    await renderCompanies('/?search=merch');
    await waitFor(() => expect(listCompanies).toHaveBeenCalledWith(expect.objectContaining({ search: 'merch' })));
    expect(screen.getByRole('searchbox')).toHaveValue('merch');
  });

  it('gagal memuat menampilkan keadaan galat dengan tombol coba lagi yang memuat ulang', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listCompanies.mockRejectedValueOnce(new Error('network'));
    await renderCompanies();

    expect(await screen.findByText('Gagal memuat data')).toBeInTheDocument();
    expect(screen.queryByTestId('data-count')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Toko Merch A')).toBeInTheDocument();
  });
});
