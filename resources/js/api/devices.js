import client from './client';

/**
 * 079 — manajemen terminal dari web. `GET /devices` berhalaman bila `page`/`per_page` dikirim (`{data, meta}`); tanpa
 * keduanya bentuk lama `{data}` tetap. Halaman Terminal punya filter Outlet sendiri (`outlet_id[]`), jadi daftar diminta
 * TANPA header bilah atas (`skipOutletHeader`, pola 070/071); server tetap memotong dengan outlet yang boleh dilihat.
 */
export function listDevices(params = {}, options = {}) {
  return client.get('/devices', { params, ...(options.skipOutletHeader ? { skipOutletHeader: true } : {}) }).then((r) => r.data);
}

/**
 * Outlet terminal baru dipilih lewat header `X-Outlet-Id` (kontrak 048: request hanya menyempitkan, body tidak membawa outlet;
 * server tetap menolak 403 bila bukan outlet pengguna). Tanpa `outletId` (instalasi satu outlet) server memakai satu-satunya
 * outlet yang berlaku. `skipOutletHeader` mencegah header bilah atas menimpa pilihan form.
 */
export function createDevice(payload, outletId = null) {
  const headers = outletId != null ? { 'X-Outlet-Id': String(outletId) } : {};
  return client.post('/devices/pairings', payload, { skipOutletHeader: true, headers }).then((r) => r.data);
}

/** `name` dan/atau `outlet_id` (pindah outlet). */
export function updateDevice(id, payload) {
  return client.patch(`/devices/${id}`, payload, { skipOutletHeader: true }).then((r) => r.data);
}

/** Nonaktifkan (cabut): baris tetap ada untuk jejak audit. */
export function deactivateDevice(id) {
  return client.delete(`/devices/${id}`, { skipOutletHeader: true });
}

export function reactivateDevice(id) {
  return client.post(`/devices/${id}/reactivate`, null, { skipOutletHeader: true }).then((r) => r.data);
}

/** Hapus permanen — hanya terminal yang belum pernah dipakai (409 bila punya order/shift). */
export function deleteDevicePermanently(id) {
  return client.delete(`/devices/${id}/permanent`, { skipOutletHeader: true });
}

/**
 * Aksi massal halaman Terminal: `action` = activate | deactivate | delete, `ids` paling banyak satu halaman (100).
 * Selalu 200 dengan hasil per terminal `{data: [{id, name, status: changed|skipped|refused|not_found, reason}], summary}`;
 * satu terminal yang ditolak tidak menggagalkan yang lain. Outlet tidak dikirim: server membatasi ke outlet pengguna.
 */
export function bulkDevices(action, ids) {
  return client.post('/devices/bulk', { action, ids }, { skipOutletHeader: true }).then((r) => r.data);
}
