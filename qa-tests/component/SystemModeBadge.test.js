import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import SystemModeBadge from '../../resources/js/components/layout/SystemModeBadge.vue';
import { useSettingsStore } from '../../resources/js/stores/settings';

/**
 * 003-seed-demo-live T024 (US2) — badge itself is role-agnostic (FR-005:
 * visible to every role), so this only verifies it reflects the store's
 * `systemMode` reactively. The mode-SWITCH control's owner/admin-only
 * gating is the SAME router-level canAccessMenu('settings') mechanism
 * that already gates the whole /settings page for every other control on
 * it (see router/index.js meta.menuKey) — not a per-component check this
 * badge or the settings toggle re-implements — and is proven server-side
 * by SettingsSystemModeTest::test_cashier_cannot_change_system_mode.
 */
function renderBadge(mode) {
  const pinia = createPinia();
  setActivePinia(pinia);
  const settings = useSettingsStore();
  settings.systemMode = mode;
  return { settings, ...render(SystemModeBadge, { global: { plugins: [pinia] } }) };
}

describe('SystemModeBadge', () => {
  beforeEach(() => {});

  it('shows LIVE styling and label when systemMode is live', () => {
    renderBadge('live');
    expect(screen.getByText('LIVE')).toBeInTheDocument();
  });

  it('shows DEMO styling and label when systemMode is demo', () => {
    renderBadge('demo');
    expect(screen.getByText('DEMO')).toBeInTheDocument();
  });

  it('re-renders when the store value changes reactively', async () => {
    const { settings } = renderBadge('live');
    expect(screen.getByText('LIVE')).toBeInTheDocument();

    settings.systemMode = 'demo';
    await Promise.resolve();

    expect(screen.getByText('DEMO')).toBeInTheDocument();
    expect(screen.queryByText('LIVE')).not.toBeInTheDocument();
  });
});
