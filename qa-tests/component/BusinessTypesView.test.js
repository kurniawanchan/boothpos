import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import { createRouter, createMemoryHistory } from 'vue-router';
import BusinessTypesView from '../../resources/js/views/BusinessTypesView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listBusinessTypes, createBusinessType, deleteBusinessType } from '../../resources/js/api/businessTypes';

// 041-back-office-ui-redesign — halaman Jenis Bisnis dimigrasi ke page kit; sebelumnya tidak punya tes.
// Perilaku yang dijaga: daftar, tambah, hapus (409 dari guard "masih dirujuk company"), plus struktur baru.

vi.mock('../../resources/js/api/businessTypes', () => ({
  listBusinessTypes: vi.fn(),
  createBusinessType: vi.fn(),
  updateBusinessType: vi.fn(),
  deleteBusinessType: vi.fn(),
}));

async function renderBusinessTypes() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'companies'] };
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] });
  await router.push('/');
  await router.isReady();
  return render(BusinessTypesView, { global: { plugins: [pinia, router] } });
}

describe('BusinessTypesView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listBusinessTypes.mockResolvedValue({
      data: [{ id: 1, code: 'RTL', name: 'Retail', company_count: 2, is_active: true }],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    });
  });

  it('memakai PageHeader (judul, deskripsi, tombol tambah) dan penghitung "N data"', async () => {
    await renderBusinessTypes();
    expect(screen.getByRole('heading', { level: 1, name: 'Jenis Bisnis' })).toBeInTheDocument();
    expect(screen.getByText(/jenis bisnis yang bisa dipilih saat onboarding/i)).toBeInTheDocument();
    expect(await screen.findByText('Retail')).toBeInTheDocument();
    expect(screen.getByTestId('data-count')).toHaveTextContent('1 data');
    expect(screen.getByRole('button', { name: /tambah jenis bisnis/i })).toBeInTheDocument();
  });

  it('menambah jenis bisnis (kode di-uppercase) lalu memuat ulang daftar', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    createBusinessType.mockResolvedValue({ id: 2 });
    await renderBusinessTypes();
    await screen.findByText('Retail');

    await user.click(screen.getByRole('button', { name: /tambah jenis bisnis/i }));
    await user.type(await screen.findByLabelText(/^kode/i), 'fnb');
    await user.type(screen.getByLabelText(/nama jenis bisnis/i), 'Food & Beverage');
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(createBusinessType).toHaveBeenCalledWith(expect.objectContaining({ code: 'FNB', name: 'Food & Beverage' })));
    await waitFor(() => expect(listBusinessTypes).toHaveBeenCalledTimes(2));
  });

  it('hapus meminta konfirmasi dan memanggil API', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deleteBusinessType.mockResolvedValue({});
    await renderBusinessTypes();
    await screen.findByText('Retail');

    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    await user.click(await screen.findByRole('button', { name: /ya, hapus/i }));
    await waitFor(() => expect(deleteBusinessType).toHaveBeenCalledWith(1));
  });

  it('pencarian dikirim sebagai parameter search; gagal memuat menampilkan coba lagi', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await renderBusinessTypes();
    await screen.findByText('Retail');
    await user.type(screen.getByRole('searchbox'), 'ret');
    await waitFor(() => expect(listBusinessTypes).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'ret' })));

    listBusinessTypes.mockRejectedValueOnce(new Error('network'));
    await user.type(screen.getByRole('searchbox'), 'a');
    expect(await screen.findByText('Gagal memuat data')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Retail')).toBeInTheDocument();
  });
});
