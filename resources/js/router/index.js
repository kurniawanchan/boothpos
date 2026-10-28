import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { useLicenseStore } from '../stores/license';

const routes = [
  {
    // 018-license-activation — satu-satunya rute yang dikecualikan dari
    // gerbang lisensi itu sendiri (lihat beforeEach di bawah). Sengaja
    // TIDAK memakai meta.public (yang berarti sesuatu berbeda: "boleh
    // diakses tanpa login") — rute ini boleh diakses tanpa AKTIVASI,
    // bukan tanpa login, dua konsep yang berbeda.
    path: '/activate',
    name: 'activate',
    component: () => import('../views/LicenseLockView.vue'),
    meta: { licenseExempt: true },
  },
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
        // 072 — POS mengisi tinggi layar: area utama jadi kolom flex agar kolom produk bisa menggulir sendiri.
        meta: { menuKey: 'pos', fillHeight: true },
      },
      {
        path: 'session',
        name: 'session',
        component: () => import('../views/SessionView.vue'),
        meta: { menuKey: 'session' },
      },
      // 028-restaurant-dine-in-ordering — layar restoran. Setiap rute digerbang
      // menu-key yang sama dengan yang diperiksa server di endpoint-nya.
      {
        path: 'floor',
        name: 'floor',
        component: () => import('../views/FloorView.vue'),
        meta: { titleKey: 'nav.floor', subtitleKey: 'nav.floor_subtitle', menuKey: 'floor' },
      },
      {
        path: 'floor/tabs/:id',
        name: 'floor-tab',
        component: () => import('../views/TabView.vue'),
        meta: { titleKey: 'nav.floor', subtitleKey: 'nav.floor_subtitle', menuKey: 'floor' },
      },
      {
        path: 'kitchen',
        name: 'kitchen',
        component: () => import('../views/KitchenView.vue'),
        meta: { titleKey: 'nav.kitchen', subtitleKey: 'nav.kitchen_subtitle', menuKey: 'kitchen' },
      },
      {
        path: 'dining-setup',
        name: 'dining-setup',
        component: () => import('../views/DiningSetupView.vue'),
        meta: { titleKey: 'nav.dining_setup', subtitleKey: 'nav.dining_setup_subtitle', menuKey: 'dining_setup' },
      },
      {
        path: 'products',
        name: 'products',
        component: () => import('../views/ProductsView.vue'),
        meta: { titleKey: 'nav.products_page_title', subtitleKey: 'nav.products_subtitle', menuKey: 'products' },
      },
      // 043 — halaman satu item (bagian-bagiannya datang di tugas berikutnya). Digerbang menu `products` yang sama
      // dengan daftarnya; judul diambil PageHeader milik halaman, bukan meta.
      {
        path: 'products/:id(\\d+)',
        name: 'product-detail',
        component: () => import('../views/ProductDetailView.vue'),
        meta: { titleKey: 'nav.products', menuKey: 'products' },
      },
      // 045 — URL lama /artists diarahkan ke /outlets (bookmark dan tautan lama tetap berfungsi).
      { path: 'artists', redirect: '/outlets' },
      // 046 — modul Event dan Pre-order dihapus; alamat lama (termasuk ?event_id= / ?preorder_id= dan anak rutenya)
      // menuju layar bawaan tanpa membawa query lama, bukan halaman galat.
      { path: 'events/:pathMatch(.*)*', redirect: () => ({ name: 'dashboard', query: {}, hash: '' }) },
      { path: 'preorders/:pathMatch(.*)*', redirect: () => ({ name: 'dashboard', query: {}, hash: '' }) },
      {
        path: 'outlets',
        name: 'outlets',
        component: () => import('../views/OutletsView.vue'),
        // Kunci judul tetap nav.artists (nilainya sudah "Outlet"); nav.<nama rute> tidak ada untuk rute ini.
        meta: { titleKey: 'nav.artists', menuKey: 'outlets' },
      },
      // 079 — manajemen terminal; digerbang menu `settings` (owner/admin), sama seperti rute server `/devices` yang
      // hanya melayani owner/admin. Menu `outlets` tidak cukup: peran lain boleh memegangnya.
      {
        path: 'terminals',
        name: 'terminals',
        component: () => import('../views/TerminalsView.vue'),
        meta: { titleKey: 'nav.terminals', subtitleKey: 'nav.terminals_subtitle', menuKey: 'settings' },
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
        // 045 Stage 6 — transfer stok antar outlet; izin aksi dan penugasan outlet ditegakkan server.
        path: 'stock/transfers',
        name: 'stock-transfers',
        component: () => import('../views/stock/TransfersView.vue'),
        meta: { titleKey: 'transfers.title', subtitleKey: 'transfers.subtitle', menuKey: 'stock' },
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
        path: 'purchase-orders',
        name: 'purchase-orders',
        component: () => import('../views/PurchaseOrdersView.vue'),
        meta: { titleKey: 'nav.purchase_orders', subtitleKey: 'nav.purchase_orders_subtitle', menuKey: 'purchase_orders' },
      },
      {
        path: 'companies',
        name: 'companies',
        component: () => import('../views/CompaniesView.vue'),
        meta: { menuKey: 'companies' },
      },
      {
        path: 'licenses',
        name: 'licenses',
        component: () => import('../views/LicensesView.vue'),
        meta: { menuKey: 'licenses' },
      },
      {
        path: 'invoices',
        name: 'invoices',
        component: () => import('../views/InvoicesView.vue'),
        meta: { menuKey: 'invoices' },
      },
      {
        path: 'business-types',
        name: 'business-types',
        component: () => import('../views/BusinessTypesView.vue'),
        // BUG YANG DITEMUKAN & DIPERBAIKI (017-company-onboarding) —
        // terverifikasi lewat browser sungguhan: AppShell's default
        // fallback title key adalah `nav.${route.name}`, dan nama route
        // ini mengandung tanda hubung ('business-types'), sama seperti
        // 'purchase-orders' yang karena itu SUDAH memakai titleKey
        // eksplisit (underscore) alih-alih mengandalkan fallback.
        meta: { titleKey: 'nav.business_types', subtitleKey: 'nav.business_types_subtitle', menuKey: 'companies' },
      },
      {
        path: 'customers',
        name: 'customers',
        component: () => import('../views/CustomersView.vue'),
        meta: { menuKey: 'customers' },
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
          // ketiga tab yang tersisa di halaman ini (Rekap Outlet, Modal &
          // Untung, Modal Outlet) semuanya owner/admin-only (PRD 7.13),
          // jadi rutenya sendiri digerbang di sini, bukan cuma tab-nya,
          // supaya kasir/inventory tidak pernah sampai ke halaman kosong.
          menuKey: 'reports',
        },
      },
      {
        path: 'activity-log',
        name: 'activity-log',
        component: () => import('../views/ActivityLogView.vue'),
        meta: { titleKey: 'nav.activity_log', subtitleKey: 'nav.activity_log_subtitle', menuKey: 'reports' },
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
        // 019-billing-system (T070, R7') — submenu tersendiri, digerbang
        // menu key 'settings' yang sudah ada (bukan permission baru).
        path: 'settings/payment',
        name: 'settings-payment',
        component: () => import('../views/SettingsPaymentView.vue'),
        meta: { titleKey: 'nav.settings_payment', menuKey: 'settings' },
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
  const license = useLicenseStore();

  // 018-license-activation — dicek LEBIH DULU, sebelum pemeriksaan auth
  // apa pun di bawah (research.md R4): instalasi yang belum teraktivasi
  // mengunci SEMUA rute, termasuk /login, tidak terkecuali (FR-002).
  // Ini murni cermin UX — penegakan sungguhan ada di
  // EnsureInstallationIsActivated di server (Constitution IV); guard ini
  // hanya mencegah layar asli sempat ter-render sebelum API menolaknya.
  if (!license.ready) {
    await license.restore();
  }

  if (!license.activated) {
    if (to.name !== 'activate') {
      return { name: 'activate' };
    }
    return true;
  }

  if (to.name === 'activate') {
    return { name: 'dashboard' };
  }

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
  // like RailNav.vue's nav filter — the API is the real gate.
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
