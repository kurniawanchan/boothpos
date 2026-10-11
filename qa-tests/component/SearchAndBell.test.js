import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, waitFor, within } from '@testing-library/vue';
import { createRouter, createMemoryHistory } from 'vue-router';
import GlobalSearch from '../../resources/js/components/layout/GlobalSearch.vue';
import NotificationBell from '../../resources/js/components/layout/NotificationBell.vue';
import { i18n } from '../../resources/js/i18n';

const t = (key, args) => i18n.global.t(key, args);

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/sales', name: 'sales', component: { template: '<div />' } },
      { path: '/orders/12', component: { template: '<div />' } },
    ],
  });
}
async function mountWith(component, props) {
  const router = makeRouter();
  router.push('/');
  await router.isReady();
  return { ...render(component, { props, global: { plugins: [router] } }), router };
}

describe('GlobalSearch', () => {
  beforeEach(() => vi.useFakeTimers({ shouldAdvanceTime: true }));
  afterEach(() => vi.useRealTimers());

  it('tidak dirender tanpa fungsi pencarian (tidak ada kendali tak berfungsi)', async () => {
    const { container } = await mountWith(GlobalSearch, {});
    expect(container.querySelector('button')).toBeNull();
  });

  it('terbuka lewat tombol dan lewat Ctrl+K, dan Escape menutup', async () => {
    await mountWith(GlobalSearch, { search: vi.fn() });
    await fireEvent.click(screen.getByRole('button', { name: t('frame.search_label') }));
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    await fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('dialog')).toBeNull();
    await fireEvent.keyDown(document, { key: 'k', ctrlKey: true });
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('meminta minimal 2 huruf sebelum mencari', async () => {
    const search = vi.fn();
    await mountWith(GlobalSearch, { search, debounceMs: 0 });
    await fireEvent.click(screen.getByRole('button', { name: t('frame.search_label') }));
    await fireEvent.update(screen.getByRole('searchbox'), 'a');
    expect(screen.getByText(t('frame.search_minimum'))).toBeInTheDocument();
    expect(search).not.toHaveBeenCalled();
  });

  it('menampilkan hasil per kelompok dan berpindah halaman saat dipilih', async () => {
    const search = vi.fn().mockResolvedValue({
      groups: [{ type: 'orders', label: 'Order', items: [{ id: 12, title: 'WJM1-T01-A000012', subtitle: 'Meja 4', to: '/orders/12' }] }],
    });
    const { router } = await mountWith(GlobalSearch, { search, debounceMs: 0 });
    await fireEvent.click(screen.getByRole('button', { name: t('frame.search_label') }));
    await fireEvent.update(screen.getByRole('searchbox'), 'meja 4');
    await vi.runAllTimersAsync();
    const hit = await screen.findByRole('button', { name: /WJM1-T01-A000012/ });
    expect(search).toHaveBeenCalledWith('meja 4');
    expect(screen.getByText('Order')).toBeInTheDocument();
    await fireEvent.click(hit);
    await waitFor(() => expect(router.currentRoute.value.path).toBe('/orders/12'));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('menampilkan pesan kosong dan pesan gagal', async () => {
    const search = vi.fn().mockResolvedValueOnce({ groups: [] }).mockRejectedValueOnce(new Error('boom'));
    await mountWith(GlobalSearch, { search, debounceMs: 0 });
    await fireEvent.click(screen.getByRole('button', { name: t('frame.search_label') }));
    await fireEvent.update(screen.getByRole('searchbox'), 'zzz');
    await vi.runAllTimersAsync();
    expect(await screen.findByText(t('frame.search_empty', { q: 'zzz' }))).toBeInTheDocument();
    await fireEvent.update(screen.getByRole('searchbox'), 'zzzz');
    await vi.runAllTimersAsync();
    expect(await screen.findByText(t('page.load_failed'))).toBeInTheDocument();
  });

  it('membuang balasan yang datang terlambat (permintaan lama tidak menimpa yang baru)', async () => {
    let resolveSlow;
    const slow = new Promise((r) => (resolveSlow = r));
    const search = vi
      .fn()
      .mockImplementationOnce(() => slow)
      .mockResolvedValueOnce({ groups: [{ type: 'a', label: 'Baru', items: [{ id: 2, title: 'Hasil baru', to: '/sales' }] }] });
    await mountWith(GlobalSearch, { search, debounceMs: 0 });
    await fireEvent.click(screen.getByRole('button', { name: t('frame.search_label') }));
    await fireEvent.update(screen.getByRole('searchbox'), 'meja 4');
    await vi.runAllTimersAsync();
    await fireEvent.update(screen.getByRole('searchbox'), 'meja 41');
    await vi.runAllTimersAsync();
    expect(await screen.findByText('Hasil baru')).toBeInTheDocument();
    resolveSlow({ groups: [{ type: 'a', label: 'Lama', items: [{ id: 1, title: 'Hasil lama', to: '/sales' }] }] });
    await vi.runAllTimersAsync();
    expect(screen.queryByText('Hasil lama')).toBeNull();
    expect(screen.getByText('Hasil baru')).toBeInTheDocument();
  });
});

describe('NotificationBell', () => {
  it('tidak dirender bila sumber datanya belum ada (items null)', async () => {
    const { container } = await mountWith(NotificationBell, { items: null });
    expect(container.querySelector('button')).toBeNull();
  });

  it('menampilkan jumlah total dan daftar, dan menutup saat memilih satu', async () => {
    await mountWith(NotificationBell, {
      items: [
        { id: 'qris', label: 'QRIS menunggu verifikasi', count: 4, to: '/sales' },
        { id: 'stock', label: 'Stok menipis', count: 3, to: '/sales' },
      ],
    });
    const bell = screen.getByRole('button', { name: new RegExp(t('frame.notifications')) });
    expect(bell).toHaveAccessibleName(`${t('frame.notifications')}: ${t('frame.notifications_count', { count: 7 })}`);
    await fireEvent.click(bell);
    const list = screen.getByRole('list');
    expect(within(list).getByText('QRIS menunggu verifikasi')).toBeInTheDocument();
    await fireEvent.click(within(list).getByRole('link', { name: /QRIS/ }));
    expect(screen.queryByRole('list')).toBeNull();
  });

  it('daftar kosong menampilkan "tidak ada yang perlu ditindaklanjuti" tanpa lencana angka', async () => {
    await mountWith(NotificationBell, { items: [] });
    const bell = screen.getByRole('button', { name: t('frame.notifications') });
    await fireEvent.click(bell);
    expect(screen.getByText(t('frame.notifications_empty'))).toBeInTheDocument();
  });

  it('Escape menutup daftar', async () => {
    await mountWith(NotificationBell, { items: [{ id: 'a', label: 'A', count: 1, to: '/sales' }] });
    await fireEvent.click(screen.getByRole('button', { name: new RegExp(t('frame.notifications')) }));
    await fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('list')).toBeNull();
  });
});
