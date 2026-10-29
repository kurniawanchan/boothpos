import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import TaxSection from '../../resources/js/views/settings/TaxSection.vue';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { updateSettings } from '../../resources/js/api/settings';

vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn(),
  updateSettings: vi.fn(),
}));

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => toast }));

function setup(preset = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  Object.assign(useSettingsStore(), preset);
  const view = render(TaxSection, { global: { plugins: [pinia] } });

  return { ...view, settings: useSettingsStore(), user: userEvent.setup() };
}

// 083 / US1 — bagian Pajak di Pengaturan: tarif, label, tampil/sembunyi.
describe('TaxSection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    updateSettings.mockResolvedValue({});
  });

  it('memuat nilai yang tersimpan', () => {
    setup({ taxRate: 10, taxLabel: 'PPN', taxShow: false });

    expect(screen.getByTestId('tax-rate')).toHaveValue(10);
    expect(screen.getByTestId('tax-label')).toHaveValue('PPN');
    expect(screen.getByTestId('tax-show')).not.toBeChecked();
  });

  it('menyimpan tiga kunci bertipe benar lalu memperbarui store', async () => {
    const { user, settings } = setup({ taxRate: 0, taxLabel: '', taxShow: true });

    await user.clear(screen.getByTestId('tax-rate'));
    await user.type(screen.getByTestId('tax-rate'), '11.5');
    await user.type(screen.getByTestId('tax-label'), 'PPN');
    await user.click(screen.getByTestId('tax-show'));
    await user.click(screen.getByTestId('tax-save'));

    await waitFor(() => expect(updateSettings).toHaveBeenCalledTimes(1));
    expect(updateSettings).toHaveBeenCalledWith([
      { key: 'tax_rate', value: '11.5', type: 'decimal', group: 'pos' },
      { key: 'tax_label', value: 'PPN', type: 'string', group: 'pos' },
      { key: 'tax_show', value: false, type: 'boolean', group: 'pos' },
    ]);
    expect(settings.taxRate).toBe(11.5);
    expect(settings.taxLabel).toBe('PPN');
    expect(settings.taxShow).toBe(false);
    expect(toast.success).toHaveBeenCalled();
  });

  it('galat server ditampilkan dan store tidak berubah', async () => {
    updateSettings.mockRejectedValue(new Error('Tarif pajak harus berupa angka 0–100 dengan maksimal 2 desimal.'));
    const { user, settings } = setup({ taxRate: 10, taxLabel: 'PPN', taxShow: true });

    await user.clear(screen.getByTestId('tax-rate'));
    await user.type(screen.getByTestId('tax-rate'), '101');
    await user.click(screen.getByTestId('tax-save'));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith(expect.stringContaining('Tarif pajak')));
    expect(settings.taxRate).toBe(10);
    expect(toast.success).not.toHaveBeenCalled();
  });

  it('label kosong dikirim sebagai null (kembali ke bawaan server)', async () => {
    const { user } = setup({ taxRate: 10, taxLabel: 'PPN', taxShow: true });

    await user.clear(screen.getByTestId('tax-label'));
    await user.click(screen.getByTestId('tax-save'));

    await waitFor(() => expect(updateSettings).toHaveBeenCalled());
    expect(updateSettings.mock.calls[0][0][1]).toEqual({ key: 'tax_label', value: null, type: 'string', group: 'pos' });
  });

  it('menjelaskan bahwa pajak tetap ditagih saat disembunyikan', () => {
    setup();

    expect(screen.getByText(/pajak tetap ditagih dan ikut dalam total/i)).toBeInTheDocument();
  });
});
