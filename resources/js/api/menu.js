import client from './client';

/**
 * 043-products-page-redesign — perubahan harga massal, perubahan menu, rilis, persetujuan, dan polling
 * ketersediaan (kontrak api-menu-release.md). Rute rilis/persetujuan menjawab 409 `release_disabled` bila
 * pengaturan rilis menu mati; UI menyembunyikan kendalinya lebih dulu lewat `settings.menuReleaseEnabled`.
 */

// --- ubah harga massal -------------------------------------------------------------------------------
/** POST /price-changes/preview — dihitung server, tidak ada yang disimpan. */
export function previewPriceChange(payload) {
  return client.post('/price-changes/preview', payload).then((r) => r.data);
}

/** POST /price-changes — hitung ulang di server lalu terapkan (atau ajukan) semua-atau-tidak-sama-sekali. */
export function applyPriceChange(payload) {
  return client.post('/price-changes', payload).then((r) => r.data);
}

// --- perubahan & rilis -------------------------------------------------------------------------------
export function listMenuChanges(params = {}) {
  return client.get('/menu/changes', { params }).then((r) => r.data);
}

export function cancelMenuChange(id) {
  return client.post(`/menu/changes/${id}/cancel`).then((r) => r.data);
}

export function listMenuReleases(params = {}) {
  return client.get('/menu/releases', { params }).then((r) => r.data);
}

export function getMenuRelease(id) {
  return client.get(`/menu/releases/${id}`).then((r) => r.data);
}

/** POST /menu/releases — { publish_at?, note?, change_ids? }. */
export function createMenuRelease(payload) {
  return client.post('/menu/releases', payload).then((r) => r.data);
}

export function cancelMenuRelease(id) {
  return client.post(`/menu/releases/${id}/cancel`).then((r) => r.data);
}

// --- persetujuan harga -------------------------------------------------------------------------------
export function listApprovals(params = {}) {
  return client.get('/approvals', { params }).then((r) => r.data);
}

export function approveApproval(id) {
  return client.post(`/approvals/${id}/approve`).then((r) => r.data);
}

/** POST /approvals/{id}/reject — { reason_code_id, note? }. */
export function rejectApproval(id, payload) {
  return client.post(`/approvals/${id}/reject`, payload).then((r) => r.data);
}

// --- polling ketersediaan ----------------------------------------------------------------------------
/**
 * GET /menu/availability?since=<etag> — 304 bila etag sama. Axios menganggap 304 sebagai galat, jadi status itu
 * diterima di sini dan dipetakan ke { notModified: true } supaya pemanggil (POS/lantai) tak perlu menangkapnya.
 */
export function pollAvailability(since = null, { pos = false } = {}) {
  return client
    .get('/menu/availability', { params: { ...(since ? { since } : {}), ...(pos ? { pos: 1 } : {}) }, validateStatus: (s) => (s >= 200 && s < 300) || s === 304 })
    .then((r) => (r.status === 304 ? { notModified: true } : r.data));
}
