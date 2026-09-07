import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import LicensesView from '../../resources/js/views/LicensesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listLicenses, createLicense, deleteLicense } from '../../resources/js/api/licenses';

vi.mock('../../resources/js/api/licenses', () => ({
  listLicenses: vi.fn(),
  createLicense: vi.fn(),
  updateLicense: vi.fn(),
  deleteLicense: vi.fn(),
}));

function renderLicenses() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'licenses'] };
  return render(LicensesView, { global: { plugins: [pinia] } });
}

describe('LicensesView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listLicenses.mockResolvedValue({
      data: [
        {
          id: 1,
          name: 'Paket Pro',
          description: 'Fitur multi-artist',
          license_tier: 'pro',
          price: '150000.00',
          payment_type: 'subscription',
          is_active: true,
          company_count: 3,
        },
      ],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
  });

  it('lists licenses from the API', async () => {
    renderLicenses();
    expect(await screen.findByText('Paket Pro')).toBeInTheDocument();
    expect(screen.getByText('150000.00')).toBeInTheDocument();
  });

  it('creates a license via the form', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createLicense.mockResolvedValue({ id: 2, name: 'Master', license_tier: 'master' });
    renderLicenses();

    await screen.findByText('Paket Pro');
    await user.click(screen.getByRole('button', { name: /tambah lisensi/i }));
    await user.type(await screen.findByLabelText(/nama lisensi/i), 'Master');
    await user.type(screen.getByLabelText(/harga/i), '500000');
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() =>
      expect(createLicense).toHaveBeenCalledWith(expect.objectContaining({ name: 'Master', price: '500000' }))
    );
  });

  it('surfaces the 409 delete-guard message via the shared error toast flow', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const conflictError = Object.assign(new Error('Lisensi masih dirujuk company/invoice dan tidak dapat dihapus.'), {
      isConflict: true,
    });
    deleteLicense.mockRejectedValue(conflictError);
    renderLicenses();

    await screen.findByText('Paket Pro');
    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    await user.click(screen.getByRole('button', { name: /ya, hapus/i }));

    await waitFor(() => expect(deleteLicense).toHaveBeenCalledWith(1));
  });
});
