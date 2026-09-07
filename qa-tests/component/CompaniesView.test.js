import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
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

function renderCompanies() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'companies'] };
  return render(CompaniesView, { global: { plugins: [pinia] } });
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
    renderCompanies();
    expect(await screen.findByText('Toko Merch A')).toBeInTheDocument();
  });

  it('updates a company via the edit form', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updateCompany.mockResolvedValue({ id: 1, name: 'Toko Merch A Updated' });
    renderCompanies();

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
    renderCompanies();

    await screen.findByText('Toko Merch A');
    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    await user.click(screen.getByRole('button', { name: /ya, hapus/i }));

    await waitFor(() => expect(deleteCompany).toHaveBeenCalledWith(1));
  });
});
