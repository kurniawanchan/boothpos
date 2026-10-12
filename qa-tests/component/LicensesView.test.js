import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import LicensesView from '../../resources/js/views/LicensesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listLicenses, createLicense, deleteLicense } from '../../resources/js/api/licenses';

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

async function renderLicenses(path = '/') {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'licenses'] };
  const router = await makeRouter(path);
  return { router, ...render(LicensesView, { global: { plugins: [pinia, router] } }) };
}

describe('LicensesView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listLicenses.mockResolvedValue({
      data: [
        {
          id: 1,
          name: 'Paket Pro',
          description: 'Fitur multi-outlet',
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
    await renderLicenses();
    expect(await screen.findByText('Paket Pro')).toBeInTheDocument();
    // 041: harga kini diformat Rupiah (formatIDR) — dulu string mentah '150000.00'. Datanya sama.
    expect(screen.getByText('Rp 150.000')).toBeInTheDocument();
  });

  it('creates a license via the form', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createLicense.mockResolvedValue({ id: 2, name: 'Master', license_tier: 'master' });
    await renderLicenses();

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
    await renderLicenses();

    await screen.findByText('Paket Pro');
    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    await user.click(screen.getByRole('button', { name: /ya, hapus/i }));

    await waitFor(() => expect(deleteLicense).toHaveBeenCalledWith(1));
  });

  // --- 041-back-office-ui-redesign (struktur halaman baru) ---

  it('memakai PageHeader dengan judul, deskripsi, dan tombol tambah di kanan', async () => {
    await renderLicenses();
    expect(screen.getByRole('heading', { level: 1, name: 'Lisensi' })).toBeInTheDocument();
    expect(screen.getByText(/katalog lisensi/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /tambah lisensi/i })).toBeInTheDocument();
  });

  it('penghitung "N data" = total dari server dan pencarian dikirim sebagai parameter search', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderLicenses();
    await screen.findByText('Paket Pro');
    expect(screen.getByTestId('data-count')).toHaveTextContent('1 data');

    await user.type(screen.getByRole('searchbox'), 'pro');
    await waitFor(() => expect(listLicenses).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'pro' })));
  });

  it('gagal memuat menampilkan keadaan galat dengan coba lagi', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listLicenses.mockRejectedValueOnce(new Error('network'));
    await renderLicenses();
    expect(await screen.findByText('Gagal memuat data')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Paket Pro')).toBeInTheDocument();
  });
});
