/**
 * 041-back-office-ui-redesign — satu-satunya definisi modul/grup navigasi. Sebelumnya larik NAV_DEFS
 * tertanam di AppSidebar.vue; kini rel navigasi (RailNav.vue), pencarian, dan tes membacanya dari sini.
 *
 * `menuKey` sama persis dengan app/Support/MenuKeys.php: visibilitas diputuskan lewat
 * auth.canAccessMenu() (cermin User::canAccessMenu() di server), BUKAN daftar peran. Ini hanya
 * kemudahan tampilan — gerbang sebenarnya tetap di API (Konstitusi IV). Fungsi di sini murni (tanpa
 * store/router) supaya mudah diuji.
 *
 * Label menyimpan KUNCI terjemahan (`nav.*`, `frame.*`), bukan teks.
 */

/** @typedef {{name:string,label:string,icon:string,menuKey:string,badgeKey?:string}} NavLeaf */
/** @typedef {{key:string,label:string,icon:string,children:Array<{name:string,label:string,menuKey:string}>}} NavGroup */

/** @type {Array<{key:string,label:string,items:Array<NavLeaf|NavGroup>}>} */
export const NAV_SECTIONS = [
  {
    key: 'overview',
    label: 'frame.group_overview',
    items: [{ name: 'dashboard', label: 'nav.dashboard', icon: 'ph-house', menuKey: 'dashboard' }],
  },
  {
    key: 'operations',
    label: 'frame.group_operations',
    items: [
      { name: 'pos', label: 'nav.pos', icon: 'ph-shopping-cart-simple', menuKey: 'pos', badgeKey: 'cart' },
      { name: 'session', label: 'nav.session', icon: 'ph-cash-register', menuKey: 'session' },
      { name: 'sales', label: 'nav.sales', icon: 'ph-receipt', menuKey: 'sales' },
      // 028 — grup Restoran; muncul hanya bila minimal satu anaknya boleh diakses peran ini.
      {
        key: 'restaurant-group',
        label: 'nav.restaurant_group',
        icon: 'ph-fork-knife',
        children: [
          { name: 'floor', label: 'nav.floor', menuKey: 'floor' },
          { name: 'kitchen', label: 'nav.kitchen', menuKey: 'kitchen' },
          { name: 'dining-setup', label: 'nav.dining_setup', menuKey: 'dining_setup' },
        ],
      },
      { name: 'preorders', label: 'nav.preorders', icon: 'ph-clock-countdown', menuKey: 'preorders', badgeKey: 'preorders' },
      { name: 'events', label: 'nav.events', icon: 'ph-calendar-dots', menuKey: 'events' },
    ],
  },
  {
    key: 'inventory',
    label: 'frame.group_inventory',
    items: [
      {
        key: 'inventaris-group',
        label: 'nav.inventaris_group',
        icon: 'ph-cube',
        children: [
          { name: 'categories', label: 'nav.categories', menuKey: 'categories' },
          { name: 'products', label: 'nav.products', menuKey: 'products' },
          { name: 'stock', label: 'nav.stock', menuKey: 'stock' },
        ],
      },
      {
        key: 'purchase-group',
        label: 'nav.purchase_group',
        icon: 'ph-shopping-bag-open',
        children: [
          { name: 'vendors', label: 'nav.vendors', menuKey: 'vendors' },
          { name: 'materials', label: 'nav.materials', menuKey: 'materials' },
          { name: 'purchase-orders', label: 'nav.purchase_orders', menuKey: 'purchase_orders' },
        ],
      },
    ],
  },
  {
    key: 'people',
    label: 'frame.group_people',
    items: [
      { name: 'customers', label: 'nav.customers', icon: 'ph-address-book', menuKey: 'customers' },
      { name: 'outlets', label: 'nav.artists', icon: 'ph-users-three', menuKey: 'outlets' },
    ],
  },
  {
    key: 'insight',
    label: 'frame.group_insight',
    items: [
      { name: 'reports', label: 'nav.reports', icon: 'ph-chart-bar', menuKey: 'reports' },
      // Log aktivitas memakai menu key 'reports' (tidak ada key sendiri) — sama seperti sebelumnya.
      { name: 'activity-log', label: 'nav.activity_log', icon: 'ph-clock-clockwise', menuKey: 'reports' },
    ],
  },
  {
    key: 'config',
    label: 'frame.group_config',
    items: [
      // 019 — Companies/Business Types/Licenses/Invoices: entitas administratif sisi-penyedia.
      {
        key: 'admin-group',
        label: 'nav.admin_group',
        icon: 'ph-buildings',
        children: [
          { name: 'companies', label: 'nav.companies', menuKey: 'companies' },
          { name: 'business-types', label: 'nav.business_types', menuKey: 'companies' },
          { name: 'licenses', label: 'nav.licenses', menuKey: 'licenses' },
          { name: 'invoices', label: 'nav.invoices', menuKey: 'invoices' },
        ],
      },
      {
        key: 'settings-group',
        label: 'nav.settings_group',
        icon: 'ph-gear-six',
        children: [
          { name: 'settings', label: 'nav.settings', menuKey: 'settings' },
          { name: 'settings-payment', label: 'nav.settings_payment', menuKey: 'settings' },
          { name: 'users', label: 'nav.users', menuKey: 'users' },
          { name: 'roles', label: 'nav.roles', menuKey: 'roles' },
        ],
      },
    ],
  },
];

export const isGroup = (item) => Array.isArray(item.children);

/**
 * Bagian/grup/item yang boleh dilihat. Sebuah grup tampil hanya bila minimal satu anaknya boleh
 * diakses (konvensi "sembunyikan sepenuhnya" yang sama dengan gerbang peran lain), dan bagian tanpa
 * item yang tampil dibuang sehingga tidak ada judul kelompok yang kosong.
 *
 * @param {(menuKey: string) => boolean} canAccess
 * @param {Record<string, number>} [badges] hitungan lencana per `badgeKey`
 * @param {Record<string, boolean>} [dots] titik status per nama item/kunci grup (data dari /attention, US4)
 */
export function visibleSections(canAccess, badges = {}, dots = {}) {
  return NAV_SECTIONS.map((section) => {
    const items = section.items
      .map((item) => {
        if (isGroup(item)) {
          const children = item.children.filter((c) => canAccess(c.menuKey));
          return children.length ? { ...item, children, dot: Boolean(dots[item.key]) } : null;
        }
        if (!canAccess(item.menuKey)) return null;
        return { ...item, badge: item.badgeKey ? badges[item.badgeKey] ?? 0 : 0, dot: Boolean(dots[item.name]) };
      })
      .filter(Boolean);
    return items.length ? { ...section, items } : null;
  }).filter(Boolean);
}

/** Apakah item (atau salah satu anak grup) adalah rute yang sedang aktif? */
export function isActive(item, routeName) {
  return isGroup(item) ? item.children.some((c) => c.name === routeName) : item.name === routeName;
}
