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
        meta: { menuKey: 'dashboard' },
      },
      {
        path: 'pos',
        name: 'pos',
        component: () => import('../views/PosView.vue'),
        meta: { menuKey: 'pos' },
      },
      {
        path: 'session',
        name: 'session',
        component: () => import('../views/SessionView.vue'),
        meta: { menuKey: 'session' },
      },
      {
        path: 'events',
        name: 'events',
        component: () => import('../views/EventsView.vue'),
        meta: { menuKey: 'events' },
      },
      {
        path: 'products',
        name: 'products',
        component: () => import('../views/ProductsView.vue'),
        meta: { titleKey: 'nav.products_page_title', subtitleKey: 'nav.products_subtitle', menuKey: 'products' },
      },
      {
        path: 'artists',
        name: 'artists',
        component: () => import('../views/ArtistsView.vue'),
        meta: { menuKey: 'artists' },
      },
      {
        path: 'categories',
        name: 'categories',
        component: () => import('../views/CategoriesView.vue'),
        meta: { menuKey: 'categories' },
      },
      {
        path: 'stock',
        name: 'stock',
        component: () => import('../views/StockView.vue'),
        meta: { titleKey: 'nav.stock_page_title', subtitleKey: 'nav.stock_subtitle', menuKey: 'stock' },
      },
      {
        path: 'vendors',
        name: 'vendors',
        component: () => import('../views/VendorsView.vue'),
        meta: { menuKey: 'vendors' },
      },
      {
        path: 'materials',
        name: 'materials',
        component: () => import('../views/MaterialsView.vue'),
        meta: { menuKey: 'materials' },
      },
      {
        path: 'customers',
        name: 'customers',
        component: () => import('../views/CustomersView.vue'),
        meta: { menuKey: 'customers' },
      },
      {
        path: 'preorders',
        name: 'preorders',
        component: () => import('../views/PreordersView.vue'),
        meta: { menuKey: 'preorders' },
      },
      {
        path: 'sales',
        name: 'sales',
        component: () => import('../views/SalesView.vue'),
        meta: { menuKey: 'sales' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: () => import('../views/ReportsView.vue'),
        meta: {
          // Tab "Penjualan" dipindah ke halaman/menu 'sales' tersendiri —
          // ketiga tab yang tersisa di halaman ini (Rekap Artist, Modal &
          // Untung, Modal Artist) semuanya owner/admin-only (PRD 7.13),
          // jadi rutenya sendiri digerbang di sini, bukan cuma tab-nya,
          // supaya kasir/inventory tidak pernah sampai ke halaman kosong.
          menuKey: 'reports',
        },
      },
      {
        path: 'users',
        name: 'users',
        component: () => import('../views/UsersView.vue'),
        meta: { menuKey: 'users' },
      },
      {
        path: 'roles',
        name: 'roles',
        component: () => import('../views/RolesView.vue'),
        meta: { menuKey: 'roles' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: () => import('../views/SettingsView.vue'),
        meta: { menuKey: 'settings' },
      },
      {
        // 005-ux-enhancements-dashboard (US3) — swa-layanan, sengaja
        // TANPA menuKey supaya setiap peran (termasuk kasir/inventory
        // tanpa akses menu 'users') bisa mengubah profilnya sendiri.
        path: 'profile',
        name: 'profile',
        component: () => import('../views/ProfileView.vue'),
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
  // raw 403 — redirect somewhere useful instead. Cosmetic only, exactly
  // like AppSidebar.vue's nav filter — the API is the real gate.
  if (to.meta.menuKey && !auth.canAccessMenu(to.meta.menuKey)) {
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
