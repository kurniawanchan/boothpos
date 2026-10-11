import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createPinia, setActivePinia } from 'pinia';
import ReleaseBanner from '../../resources/js/components/product/ReleaseBanner.vue';
import { useAuthStore } from '../../resources/js/stores/auth';
import { useSettingsStore } from '../../resources/js/stores/settings';

const UNRELEASED = { count: 4, summary: ['Harga Es Kopi diubah', 'Teh Tarik diterbitkan', 'Kopi Hitam dihentikan'], active_version: 3 };

function renderBanner({ permissions = ['menu.release.publish'], enabled = true, unreleased = UNRELEASED } = {}) {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, name: 'Owner', permissions };
  useSettingsStore().menuReleaseEnabled = enabled;
  const handlers = { onRelease: vi.fn(), onApprovals: vi.fn() };
  const view = render(ReleaseBanner, { props: { unreleased, ...handlers }, global: { plugins: [pinia] } });
  return { ...view, ...handlers };
}
async function userEv() {
  const { default: userEvent } = await import('@testing-library/user-event');
  return userEvent.setup();
}

// 043 (T066) — banner rilis di daftar Produk.
describe('ReleaseBanner (043)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows the count, a short summary and a release button for a publisher when release is on', async () => {
    const user = await userEv();
    const { onRelease } = renderBanner();
    expect(screen.getByTestId('release-banner')).toHaveTextContent('4 perubahan belum dirilis');
    expect(screen.getByText('Harga Es Kopi diubah')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Rilis perubahan' }));
    expect(onRelease).toHaveBeenCalled();
  }, 30000);

  it('renders nothing at all when release mode is off', () => {
    renderBanner({ enabled: false, permissions: ['menu.release.publish', 'menu.price.approve'] });
    expect(screen.queryByTestId('release-banner')).not.toBeInTheDocument();
  }, 30000);

  it('hides the release button (not disabled) from a role without menu.release.publish, and the banner altogether without any action', () => {
    renderBanner({ permissions: [] });
    expect(screen.queryByTestId('release-banner')).not.toBeInTheDocument();
  }, 30000);

  it('offers the approvals link only to approvers, and never a release button without the publish permission', async () => {
    const user = await userEv();
    const { onApprovals } = renderBanner({ permissions: ['menu.price.approve'] });
    expect(screen.queryByRole('button', { name: 'Rilis perubahan' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Persetujuan harga' }));
    expect(onApprovals).toHaveBeenCalled();
  }, 30000);

  it('hides the banner from a publisher when nothing is unreleased', () => {
    renderBanner({ unreleased: { count: 0, summary: [], active_version: 3 } });
    expect(screen.queryByTestId('release-banner')).not.toBeInTheDocument();
  }, 30000);

  it('tolerates a missing summary payload (null while loading)', () => {
    renderBanner({ unreleased: null, permissions: ['menu.release.publish'] });
    expect(screen.queryByTestId('release-banner')).not.toBeInTheDocument();
  }, 30000);
});
