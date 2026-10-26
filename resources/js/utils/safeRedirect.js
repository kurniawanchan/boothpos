// 076-settlement-tab-session-branding (US1/FR-004) — satu-satunya penjaga
// "kembali ke halaman yang diminta setelah login". Nilai `?redirect=` datang
// dari URL, jadi tidak boleh dipercaya (open redirect): hanya path internal
// yang dikenal router yang diterima, selain itu null → pemanggil memakai
// dashboard.
const BLOCKED_ROUTE_NAMES = new Set(['login', 'activate']);

export function safeRedirect(target, router) {
  if (typeof target !== 'string') return null;
  // Harus path internal tunggal: "/x" saja — bukan "//host", "/\host", skema, atau path relatif.
  if (!/^\/(?![/\\])/.test(target)) return null;

  const resolved = router.resolve(target);
  // Rute catch-all tidak bernama; /login dan /activate tidak boleh jadi tujuan balik.
  if (!resolved?.name || BLOCKED_ROUTE_NAMES.has(resolved.name)) return null;

  return target;
}
