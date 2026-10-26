import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/vue';
import { createRouter, createMemoryHistory } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import LoginView from '../../resources/js/views/LoginView.vue';

vi.mock('../../resources/js/api/auth', () => ({
  login: vi.fn().mockResolvedValue({ token: 't', user: { id: 1, username: 'owner', role: 'owner' } }),
  logout: vi.fn(),
  me: vi.fn(),
}));

// 009-ui-ux-refinements US1 — the "Instalasi lokal · {host}" line, the
// "· Instalasi lokal" badge, and the three metric badges ("< 30 dtk",
// "< 15 mnt", "0 transaksi hilang") were removed from LoginView.vue.
// This test locks that removal in so it can't silently regress.
function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: LoginView },
      { path: '/dashboard', name: 'dashboard', component: { template: '<div>Beranda</div>' } },
      { path: '/sales', name: 'sales', component: { template: '<div>Penjualan</div>' } },
    ],
  });
}

async function renderLogin(url = '/login') {
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = makeRouter();
  router.push(url);
  await router.isReady();
  const view = render(LoginView, { global: { plugins: [pinia, router] } });
  return { ...view, router };
}

async function submitLogin() {
  await fireEvent.update(screen.getByLabelText(/username/i), 'owner');
  await fireEvent.update(screen.getByLabelText(/kata sandi/i), 'password123');
  await fireEvent.click(screen.getByRole('button', { name: /masuk/i }));
}

describe('LoginView — no local-install marketing copy (009-ui-ux-refinements US1)', () => {
  it('does not render local-install / metric badge copy anywhere on the page', async () => {
    await renderLogin();
    const text = document.body.textContent;

    expect(text).not.toMatch(/Instalasi lokal/i);
    expect(text).not.toMatch(/127\.0\.0\.1/);
    expect(text).not.toMatch(/<\s*30 dtk/i);
    expect(text).not.toMatch(/<\s*15 mnt/i);
    expect(text).not.toMatch(/0 transaksi hilang/i);
  });

  it('still renders the core login form', async () => {
    await renderLogin();
    expect(screen.getByLabelText(/username/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/kata sandi/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /masuk/i })).toBeInTheDocument();
  });
});

// 076-settlement-tab-session-branding (US1/FR-004) — kembali ke halaman yang diminta.
describe('LoginView — returns to the requested page after login', () => {
  it('goes to the validated ?redirect= target', async () => {
    const { router } = await renderLogin('/login?redirect=%2Fsales%3Fcashier%3D2');
    await submitLogin();
    await waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/sales?cashier=2'));
  });

  it.each([
    ['external URL', 'https%3A%2F%2Fevil.example'],
    ['protocol-relative URL', '%2F%2Fevil.example'],
    ['the login page', '%2Flogin'],
    ['an unknown page', '%2Fno-such-page'],
  ])('falls back to the dashboard for %s', async (_label, encoded) => {
    const { router } = await renderLogin(`/login?redirect=${encoded}`);
    await submitLogin();
    await waitFor(() => expect(router.currentRoute.value.name).toBe('dashboard'));
  });

  it('goes to the dashboard when there is no redirect', async () => {
    const { router } = await renderLogin('/login');
    await submitLogin();
    await waitFor(() => expect(router.currentRoute.value.name).toBe('dashboard'));
  });
});
