import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import EventsView from '../../resources/js/views/EventsView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { listEvents, deleteEvent } from '../../resources/js/api/events';
import { customHeaderCount } from '../../resources/js/composables/usePageHeader';

/**
 * 041-back-office-ui-redesign (US2) — EventsView memakai PageHeader + baris filter + penghitung "N data" +
 * keadaan galat DataTable. Perilaku lama (filter status, aksi per baris, izin) harus utuh.
 */
vi.mock('../../resources/js/api/events', () => ({
  listEvents: vi.fn(),
  createEvent: vi.fn(),
  updateEvent: vi.fn(),
  updateEventStatus: vi.fn(),
  deleteEvent: vi.fn(),
}));

const EVENT = { id: 1, name: 'Pameran Kopi', location: 'JCC', start_date: '2026-10-01', end_date: '2026-10-02', event_cost: '1000000.00', status: 'draft' };

function renderEvents({ role = 'Owner', menuKeys = ['dashboard', 'settings'] } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role, name: role, menu_keys: menuKeys };
  return render(EventsView, { global: { plugins: [pinia] } });
}

describe('EventsView — struktur halaman (041)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    customHeaderCount.value = 0;
    listEvents.mockResolvedValue({ data: [EVENT], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } });
  });

  it('memakai PageHeader dengan judul, deskripsi, dan tombol utama; tidak ada h1 kedua', async () => {
    renderEvents();
    expect(await screen.findByText('Pameran Kopi')).toBeInTheDocument();
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('heading', { level: 1, name: 'Event' })).toBeInTheDocument();
    expect(screen.getByText('Wadah seluruh transaksi dan rekap hasil')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /event baru/i })).toBeInTheDocument();
    expect(customHeaderCount.value).toBe(1);
  });

  it('penghitung "N data" mengikuti total dari server', async () => {
    listEvents.mockResolvedValue({ data: [EVENT], meta: { current_page: 1, per_page: 25, total: 1284, last_page: 52 } });
    renderEvents();
    await screen.findByText('Pameran Kopi');
    expect(screen.getByTestId('data-count')).toHaveTextContent('1.284 data');
  });

  it('menyembunyikan (bukan menonaktifkan) tombol Event baru dan aksi baris bagi peran tanpa menu pengaturan', async () => {
    renderEvents({ role: 'Cashier', menuKeys: ['dashboard'] });
    await screen.findByText('Pameran Kopi');
    expect(screen.queryByRole('button', { name: /event baru/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ubah' })).not.toBeInTheDocument();
  });

  it('hanya owner/admin melihat Hapus, dan hapus memanggil API', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    deleteEvent.mockResolvedValue({});
    renderEvents();
    await screen.findByText('Pameran Kopi');
    await user.click(screen.getByRole('button', { name: 'Hapus' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));
    await waitFor(() => expect(deleteEvent).toHaveBeenCalledWith(1));
  });

  it('menyaring daftar menurut status lewat listEvents', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderEvents();
    await screen.findByText('Pameran Kopi');
    await user.click(screen.getByText('Semua status'));
    await user.click(await screen.findByRole('option', { name: 'Aktif' }));
    await waitFor(() => expect(listEvents).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'active', page: 1 })));
  });

  it('galat memuat menampilkan keadaan galat dengan tombol coba lagi yang memuat ulang', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    listEvents.mockRejectedValueOnce(new Error('Jaringan putus'));
    renderEvents();
    expect(await screen.findByRole('alert')).toHaveTextContent('Jaringan putus');
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByText('Pameran Kopi')).toBeInTheDocument();
    expect(listEvents).toHaveBeenCalledTimes(2);
  });
});
