import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createRouter, createMemoryHistory } from 'vue-router';
import { createPinia, setActivePinia } from 'pinia';
import LoginView from '../../resources/js/views/LoginView.vue';

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
    ],
  });
}

async function renderLogin() {
  const pinia = createPinia();
  setActivePinia(pinia);
  const router = makeRouter();
  router.push('/login');
  await router.isReady();
  return render(LoginView, { global: { plugins: [pinia, router] } });
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
