import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { createPinia, setActivePinia } from 'pinia';
import BusinessDaySection from '../../resources/js/views/settings/BusinessDaySection.vue';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { useToastStore } from '../../resources/js/stores/toast';
import { listSettings, updateSettings, featureFlags } from '../../resources/js/api/settings';

vi.mock('../../resources/js/api/settings', () => ({
  featureFlags: vi.fn(),
  listSettings: vi.fn(),
  updateSettings: vi.fn(),
}));

function renderSection() {
  const pinia = createPinia();
  setActivePinia(pinia);
  return render(BusinessDaySection, { global: { plugins: [pinia] } });
}

const saved = (key) => updateSettings.mock.calls[0][0].find((s) => s.key === key);

describe('BusinessDaySection (042, T015)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    updateSettings.mockResolvedValue({});
    listSettings.mockResolvedValue({
      data: [
        { key: 'store_timezone', value: 'Asia/Makassar', type: 'string', group: 'business_day' },
        { key: 'business_day_cutoff', value: '04:00', type: 'string', group: 'business_day' },
        { key: 'cash_difference_tolerance', value: '7500.00', type: 'decimal', group: 'business_day' },
        { key: 'device_offline_after_minutes', value: 10, type: 'integer', group: 'business_day' },
      ],
    });
  });

  it('loads the four stored values into the form', async () => {
    renderSection();
    expect(await screen.findByDisplayValue('04:00')).toBeInTheDocument();
    expect(screen.getByDisplayValue('7500.00')).toBeInTheDocument();
    expect(screen.getByDisplayValue('10')).toBeInTheDocument();
    expect(screen.getByRole('combobox')).toHaveTextContent('Asia/Makassar');
  });

  it('falls back to the server defaults for keys that were never saved', async () => {
    listSettings.mockResolvedValue({ data: [] });
    renderSection();
    expect(await screen.findByDisplayValue('00:00')).toBeInTheDocument();
    expect(screen.getByDisplayValue('5000')).toBeInTheDocument();
    expect(screen.getByDisplayValue('5')).toBeInTheDocument();
    expect(screen.getByRole('combobox')).toHaveTextContent('Asia/Jakarta');
  });

  it('keeps a stored timezone that is not in the common list', async () => {
    listSettings.mockResolvedValue({ data: [{ key: 'store_timezone', value: 'America/Bogota' }] });
    renderSection();
    await screen.findByDisplayValue('00:00');
    expect(screen.getByRole('combobox')).toHaveTextContent('America/Bogota');
  });

  it('offers the Indonesian zones and UTC', async () => {
    renderSection();
    await screen.findByDisplayValue('04:00');
    await userEvent.setup().click(screen.getByRole('combobox'));
    for (const z of ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura', 'UTC']) {
      expect(screen.getByRole('option', { name: z })).toBeInTheDocument();
    }
  });

  it('saves all four keys in one bulk call with the right types and group, and updates the store', async () => {
    const user = userEvent.setup();
    renderSection();
    const cutoff = await screen.findByDisplayValue('04:00');
    await user.clear(cutoff);
    await user.type(cutoff, '03:30');
    const tolerance = screen.getByDisplayValue('7500.00');
    await user.clear(tolerance);
    await user.type(tolerance, '10000');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    await waitFor(() => expect(updateSettings).toHaveBeenCalledTimes(1));
    expect(updateSettings.mock.calls[0][0]).toHaveLength(4);
    expect(saved('store_timezone')).toEqual({ key: 'store_timezone', value: 'Asia/Makassar', type: 'string', group: 'business_day' });
    expect(saved('business_day_cutoff')).toEqual({ key: 'business_day_cutoff', value: '03:30', type: 'string', group: 'business_day' });
    expect(saved('cash_difference_tolerance')).toEqual({ key: 'cash_difference_tolerance', value: '10000', type: 'decimal', group: 'business_day' });
    expect(saved('device_offline_after_minutes')).toEqual({ key: 'device_offline_after_minutes', value: 10, type: 'integer', group: 'business_day' });

    const store = useSettingsStore();
    expect(store.storeTimezone).toBe('Asia/Makassar');
    expect(store.businessDayCutoff).toBe('03:30');
    expect(useToastStore().items.some((i) => i.variant === 'success')).toBe(true);
  });

  it('refuses invalid values on the client without calling the API', async () => {
    const user = userEvent.setup();
    renderSection();
    const offline = await screen.findByDisplayValue('10');
    await user.clear(offline);
    await user.type(offline, '2000');
    const tolerance = screen.getByDisplayValue('7500.00');
    await user.clear(tolerance);
    await user.type(tolerance, '-5');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));

    expect(await screen.findByText('Isi bilangan bulat 1 sampai 1440 menit.')).toBeInTheDocument();
    expect(screen.getByText('Toleransi harus berupa angka 0 atau lebih.')).toBeInTheDocument();
    expect(updateSettings).not.toHaveBeenCalled();
  });

  it('maps a server validation error back to its field', async () => {
    const user = userEvent.setup();
    updateSettings.mockRejectedValue({ isValidation: true, message: 'x', errors: { 'settings.1.value': ['Jam batas hari harus berformat HH:MM (00:00–23:59).'] } });
    renderSection();
    await screen.findByDisplayValue('04:00');
    await user.click(screen.getByRole('button', { name: 'Simpan' }));
    expect(await screen.findByText('Jam batas hari harus berformat HH:MM (00:00–23:59).')).toBeInTheDocument();
  });

  it('shows an error with Retry when the settings cannot be loaded', async () => {
    listSettings.mockRejectedValueOnce(new Error('down'));
    const user = userEvent.setup();
    renderSection();
    expect(await screen.findByRole('alert')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /coba lagi/i }));
    expect(await screen.findByDisplayValue('04:00')).toBeInTheDocument();
  });

  it('the settings store reads timezone and cut-off from /settings/features, with defaults', async () => {
    setActivePinia(createPinia());
    const store = useSettingsStore();
    expect(store.storeTimezone).toBe('Asia/Jakarta');
    expect(store.businessDayCutoff).toBe('00:00');
    featureFlags.mockResolvedValue({ multi_outlet_enabled: false, outlet_count: 0, outlet_limit_reached: false, store_timezone: 'Asia/Jayapura', business_day_cutoff: '05:00' });
    await store.load();
    expect(store.storeTimezone).toBe('Asia/Jayapura');
    expect(store.businessDayCutoff).toBe('05:00');
    featureFlags.mockResolvedValue({ multi_outlet_enabled: false, outlet_count: 0, outlet_limit_reached: false });
    await store.load();
    expect(store.storeTimezone).toBe('Asia/Jakarta');
  });
});
