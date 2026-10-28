import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SystemModeSection from '../../resources/js/views/settings/SystemModeSection.vue';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { useOutletStore } from '../../resources/js/stores/outlet';
import { useAuthStore } from '../../resources/js/stores/auth';

// Outlet DEMO dan LIVE adalah baris berbeda. Sebelum perbaikan ini, beralih mode tidak memuat ulang cakupan outlet,
// sehingga bilah atas dan pilihan outlet di formulir (mis. Terminal) tetap menampilkan outlet mode sebelumnya.
describe('SystemModeSection — beralih mode memuat ulang outlet', () => {
  it('clears the old outlet selection and reloads scope and store identity after switching', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    const settings = useSettingsStore();
    const outlet = useOutletStore();
    const auth = useAuthStore();
    auth.user = { id: 7 };
    settings.systemMode = 'live';
    outlet.selected = 9;
    settings.setSystemMode = vi.fn().mockResolvedValue();
    settings.load = vi.fn().mockResolvedValue();
    outlet.load = vi.fn().mockResolvedValue();

    render(SystemModeSection, { global: { plugins: [pinia] } });
    await fireEvent.click(screen.getByRole('button', { name: /demo/i }));
    await fireEvent.click(await screen.findByRole('button', { name: /lanjut/i }));

    await waitFor(() => expect(outlet.load).toHaveBeenCalledWith(7));
    expect(settings.setSystemMode).toHaveBeenCalledWith('demo');
    expect(settings.load).toHaveBeenCalled();
    expect(outlet.selected).toBeNull();
  });
});
