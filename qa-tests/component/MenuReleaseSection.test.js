import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import MenuReleaseSection from '../../resources/js/views/settings/MenuReleaseSection.vue';
import { listSettings, updateSettings } from '../../resources/js/api/settings';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { ApiError } from '../../resources/js/utils/errors';

vi.mock('../../resources/js/api/settings', () => ({ listSettings: vi.fn(), updateSettings: vi.fn(), featureFlags: vi.fn() }));

function renderSection() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const view = render(MenuReleaseSection, { global: { plugins: [pinia] } });
  return { ...view, store: useSettingsStore() };
}
async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

// 043 (T066) — Pengaturan → Rilis menu.
describe('MenuReleaseSection (043)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listSettings.mockResolvedValue({ data: [] });
    updateSettings.mockResolvedValue({});
  });

  it('shows the server defaults when nothing was ever saved: release off, rounding Rp 500, no threshold field', async () => {
    renderSection();
    const toggle = await screen.findByRole('switch', { name: /Aktifkan rilis menu/ });
    expect(toggle).not.toBeChecked();
    expect(screen.queryByLabelText(/Ambang persetujuan harga/)).not.toBeInTheDocument();
  }, 30000);

  it('reads the saved values (string booleans included) into the form', async () => {
    listSettings.mockResolvedValue({ data: [
      { key: 'menu_release_enabled', value: 'true' },
      { key: 'price_approval_threshold_pct', value: '20' },
      { key: 'price_rounding_default', value: 'none' },
    ] });
    renderSection();
    expect(await screen.findByRole('switch', { name: /Aktifkan rilis menu/ })).toBeChecked();
    expect(screen.getByLabelText(/Ambang persetujuan harga/)).toHaveValue(20);
  }, 30000);

  it('saves the three keys in ONE bulk request and updates the store flag', async () => {
    const user = await userEv();
    const { store } = renderSection();
    await user.click(await screen.findByRole('switch', { name: /Aktifkan rilis menu/ }));
    const threshold = screen.getByLabelText(/Ambang persetujuan harga/);
    await user.clear(threshold);
    await user.type(threshold, '25');
    await user.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));

    await waitFor(() => expect(updateSettings).toHaveBeenCalledTimes(1));
    expect(updateSettings).toHaveBeenCalledWith([
      { key: 'menu_release_enabled', value: true, type: 'boolean', group: 'menu' },
      { key: 'price_approval_threshold_pct', value: '25', type: 'decimal', group: 'menu' },
      { key: 'price_rounding_default', value: '500', type: 'string', group: 'menu' },
    ]);
    expect(store.menuReleaseEnabled).toBe(true);
  }, 30000);

  it('rejects a threshold outside 0–100 on the client without calling the server', async () => {
    const user = await userEv();
    renderSection();
    await user.click(await screen.findByRole('switch', { name: /Aktifkan rilis menu/ }));
    const threshold = screen.getByLabelText(/Ambang persetujuan harga/);
    await user.clear(threshold);
    await user.type(threshold, '150');
    await user.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));
    expect(await screen.findByText('Isi angka 0 sampai 100.')).toBeInTheDocument();
    expect(updateSettings).not.toHaveBeenCalled();
  }, 30000);

  it('maps a server field error back onto the threshold field and leaves the store flag untouched', async () => {
    updateSettings.mockRejectedValue(new ApiError('Gagal', { status: 422, errors: { 'settings.1.value': ['Ambang tidak valid dari server.'] } }));
    const user = await userEv();
    const { store } = renderSection();
    await user.click(await screen.findByRole('switch', { name: /Aktifkan rilis menu/ }));
    await user.click(screen.getByRole('button', { name: 'Simpan pengaturan' }));
    expect(await screen.findByText('Ambang tidak valid dari server.')).toBeInTheDocument();
    expect(store.menuReleaseEnabled).toBe(false);
  }, 30000);

  it('shows an error state with Retry when the settings fail to load', async () => {
    listSettings.mockRejectedValueOnce(new ApiError('boom', { status: 500 }));
    const user = await userEv();
    renderSection();
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Coba lagi' }));
    expect(await screen.findByRole('switch', { name: /Aktifkan rilis menu/ })).toBeInTheDocument();
  }, 30000);
});
