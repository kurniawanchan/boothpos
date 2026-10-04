import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SchemaUpdateBanner from '../../resources/js/components/layout/SchemaUpdateBanner.vue';
import { useSettingsStore } from '../../resources/js/stores/settings';
import { ApiError } from '../../resources/js/utils/errors';
import { featureFlags } from '../../resources/js/api/settings';

vi.mock('../../resources/js/api/settings', () => ({ featureFlags: vi.fn(), updateSettings: vi.fn() }));

// 035-po-row-actions (US4) — banner "database perlu diperbarui" untuk owner/admin.
describe('SchemaUpdateBanner (035)', () => {
  it('explains the situation in plain words, with no technical detail', () => {
    render(SchemaUpdateBanner);

    const alert = screen.getByRole('alert');
    expect(alert).toHaveTextContent('Database perlu diperbarui');
    expect(alert.textContent).not.toMatch(/SQLSTATE|column|table|kolom|tabel|migrat/i);
  });
});

describe('settings store — schema_update_required (035)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setActivePinia(createPinia());
  });

  it('is off until the server says otherwise', () => {
    expect(useSettingsStore().schemaUpdateRequired).toBe(false);
  });

  it('mirrors the flag from GET /settings/features', async () => {
    featureFlags.mockResolvedValue({ schema_update_required: true });
    const store = useSettingsStore();

    await store.load();

    expect(store.schemaUpdateRequired).toBe(true);
  });

  it('stays off when the flag is absent (cashiers never receive it)', async () => {
    featureFlags.mockResolvedValue({});
    const store = useSettingsStore();

    await store.load();

    expect(store.schemaUpdateRequired).toBe(false);
  });
});

describe('ApiError.isSchemaOutdated (035)', () => {
  it('is true only for a 503 carrying the schema_outdated code', () => {
    expect(new ApiError('x', { status: 503, code: 'schema_outdated' }).isSchemaOutdated).toBe(true);
    expect(new ApiError('x', { status: 503 }).isSchemaOutdated).toBe(false);
    expect(new ApiError('x', { status: 500, code: 'schema_outdated' }).isSchemaOutdated).toBe(false);
  });
});
