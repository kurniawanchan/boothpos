import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import LanguageSwitcher from '../../resources/js/components/layout/LanguageSwitcher.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { i18n } from '../../resources/js/i18n';
import * as authApi from '../../resources/js/api/auth';

vi.mock('../../resources/js/api/auth', () => ({
  updateLanguage: vi.fn(),
}));

function renderSwitcher(language = 'en') {
  const pinia = createPinia();
  setActivePinia(pinia);
  const auth = useAuthStore();
  auth.user = { id: 1, name: 'Owner', username: 'owner', role: 'Owner', menu_keys: [], language };
  return { auth, ...render(LanguageSwitcher, { global: { plugins: [pinia] } }) };
}

describe('LanguageSwitcher', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    i18n.global.locale.value = 'en';
  });

  it('marks the current language as pressed', () => {
    renderSwitcher('en');
    expect(screen.getByRole('button', { name: 'EN' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('button', { name: 'ID' })).toHaveAttribute('aria-pressed', 'false');
  });

  it('calls the API and updates the store + i18n locale when switching', async () => {
    authApi.updateLanguage.mockResolvedValue({
      id: 1, name: 'Owner', username: 'owner', role: 'Owner', menu_keys: [], language: 'id',
    });
    const { auth } = renderSwitcher('en');

    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'ID' }));

    expect(authApi.updateLanguage).toHaveBeenCalledWith('id');
    expect(auth.user.language).toBe('id');
    expect(i18n.global.locale.value).toBe('id');
  });

  it('does nothing when clicking the already-active language', async () => {
    renderSwitcher('en');
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'EN' }));

    expect(authApi.updateLanguage).not.toHaveBeenCalled();
  });
});
