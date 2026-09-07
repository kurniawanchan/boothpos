import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SettingsPaymentView from '../../resources/js/views/SettingsPaymentView.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { getPaymentSettings, updatePaymentSettings } from '../../resources/js/api/settings';

// 019-billing-system (T069) — mirrors SettingsView.test.js's mocking
// convention for resources/js/api/settings.js.
vi.mock('../../resources/js/api/settings', () => ({
  getPaymentSettings: vi.fn(),
  updatePaymentSettings: vi.fn(),
}));

function renderView() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, role: 'Owner', name: 'Owner', menu_keys: ['dashboard', 'settings'] };
  return render(SettingsPaymentView, { global: { plugins: [pinia] } });
}

describe('SettingsPaymentView (US4)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    getPaymentSettings.mockResolvedValue({
      bank_name: 'BCA',
      account_number: '1234567890',
      account_holder: 'PT Booth POS',
      instructions: 'Transfer lalu konfirmasi via WhatsApp.',
    });
  });

  it('loads and renders the current payment settings via GET /settings/payment', async () => {
    renderView();

    expect(await screen.findByDisplayValue('BCA')).toBeInTheDocument();
    expect(screen.getByDisplayValue('1234567890')).toBeInTheDocument();
    expect(screen.getByDisplayValue('PT Booth POS')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Transfer lalu konfirmasi via WhatsApp.')).toBeInTheDocument();
  });

  it('saves the form via PUT /settings/payment', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    updatePaymentSettings.mockResolvedValue({ bank_name: 'BCA' });
    renderView();

    await screen.findByDisplayValue('BCA');
    await user.clear(screen.getByLabelText(/nama bank/i));
    await user.type(screen.getByLabelText(/nama bank/i), 'Mandiri');
    await user.click(screen.getByRole('button', { name: /^simpan$/i }));

    await waitFor(() => expect(updatePaymentSettings).toHaveBeenCalled());
    const payload = updatePaymentSettings.mock.calls[0][0];
    expect(payload.bank_name).toBe('Mandiri');
  });
});
