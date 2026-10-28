import client from './client';

/** 045 Stage 2 — outlet yang boleh dilihat pengguna ini: { outlets, selected, can_see_all, multi }. */
// Tanpa header X-Outlet-Id: pilihan tersimpan bisa sudah dicabut (basis data direset, mode berganti, outlet dinonaktifkan),
// dan server menolak header itu dengan 403 sebelum cakupan sempat dibaca, sehingga store tak pernah memulihkan diri.
export function getScope() {
  return client.get('/me/scope', { skipOutletHeader: true }).then((r) => r.data);
}
