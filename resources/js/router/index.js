import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('../views/LoginView.vue'),
    meta: { public: true },
  },
  {
    path: '/',
    component: () => import('../components/layout/AppShell.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: { name: 'dashboard' } },
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('../views/DashboardView.vue'),
        meta: { title: 'Beranda', subtitle: 'Ringkasan performa event yang sedang berjalan' },
      },
      {
        path: 'pos',
        name: 'pos',
        component: () => import('../views/PosView.vue'),
        meta: { title: 'Kasir', subtitle: 'Transaksi terikat otomatis ke event aktif' },
      },
      {
        path: 'session',
        name: 'session',
        component: () => import('../views/SessionView.vue'),
        meta: { title: 'Sesi Kasir', subtitle: 'Buka, pantau, dan tutup sesi beserta selisih kas' },
      },
      {
        path: 'events',
        name: 'events',
        component: () => import('../views/EventsView.vue'),
        meta: { title: 'Event', subtitle: 'Wadah seluruh transaksi dan rekap hasil' },
      },
      {
        path: 'products',
        name: 'products',
        component: () => import('../views/ProductsView.vue'),
        meta: { title: 'Produk & Varian', subtitle: 'Kode produk 12 karakter digenerate otomatis' },
      },
      {
        path: 'artists',
        name: 'artists',
        component: () => import('../views/ArtistsView.vue'),
        meta: { title: 'Artist', subtitle: 'Pemilik merchandise yang dititipkan' },
      },
      {
        path: 'categories',
        name: 'categories',
        component: () => import('../views/CategoriesView.vue'),
        meta: { title: 'Kategori', subtitle: 'Hierarki kategori untuk filter di kasir' },
      },
      {
        path: 'stock',
        name: 'stock',
        component: () => import('../views/StockView.vue'),
        meta: { title: 'Pergerakan Stok', subtitle: 'Seluruh perubahan stok tercatat dan dapat diaudit' },
      },
      {
        path: 'customers',
        name: 'customers',
        component: () => import('../views/CustomersView.vue'),
        meta: { title: 'Pelanggan', subtitle: 'Data kontak dibatasi per peran' },
      },
      {
        path: 'preorders',
        name: 'preorders',
        component: () => import('../views/PreordersView.vue'),
        meta: { title: 'Pre-order', subtitle: 'Pesanan, DP, status, dan pengiriman kurir' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: () => import('../views/ReportsView.vue'),
        meta: { title: 'Laporan', subtitle: 'Penjualan, hasil artist, modal & keuntungan' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('../views/SettingsView.vue'),
        meta: {
          title: 'Pengaturan',
          subtitle: 'Lisensi, kanal pembayaran, identitas toko, cadangan',
          roles: ['owner', 'admin'],
        },
      },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 };
  },
});

router.beforeEach(async (to) => {
  const auth = useAuthStore();

  // Vue Router can resolve its first navigation before main.js's own
  // `auth.restore()` call finishes (it's triggered by `app.use(router)`
  // itself, ahead of the explicit await in main.js) — without this, a
  // hard refresh on any protected route would bounce a logged-in cashier
  // to /login even though their sessionStorage token is still valid.
  // `restore()` is cached, so this is a no-op once boot has settled it.
  if (!auth.ready) {
    await auth.restore();
  }

  if (to.meta.public) {
    if (auth.isAuthenticated && to.name === 'login') {
      return { name: 'dashboard' };
    }
    return true;
  }

  if (!auth.isAuthenticated) {
    return { name: 'login' };
  }

  // Screens we can predict will 403 for the current role are hidden from
  // nav entirely AND guarded here, so a typed-in URL doesn't crash into a
  // raw 403 — redirect somewhere useful instead.
  if (to.meta.roles && !to.meta.roles.includes(auth.role)) {
    return { name: 'dashboard' };
  }

  return true;
});

// a11y: move focus to the page heading on every route change so screen
// readers announce the new context (accessibility.md — focus management).
router.afterEach(() => {
  requestAnimationFrame(() => {
    document.getElementById('page-heading')?.focus();
  });
});

export default router;
