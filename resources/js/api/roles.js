import client from './client';

export function listRoles(params = {}) {
  return client.get('/roles', { params }).then((r) => r.data);
}

export function getRole(id) {
  return client.get(`/roles/${id}`).then((r) => r.data);
}

export function createRole(payload) {
  return client.post('/roles', payload).then((r) => r.data);
}

export function updateRole(id, payload) {
  return client.put(`/roles/${id}`, payload).then((r) => r.data);
}

export function deleteRole(id) {
  return client.delete(`/roles/${id}`);
}

// Registry tunggal App\Support\MenuKeys — dikonsumsi RoleMenuPicker.vue
// supaya checkbox layar peran tidak pernah di-hardcode terpisah dari
// backend (lihat contracts/api.md, GET /menu-keys).
export function listMenuKeys() {
  return client.get('/menu-keys').then((r) => r.data);
}

// 043 — katalog izin tingkat-aksi (App\Support\PermissionCatalog), { data: [{ key, label }] }. Dipakai blok
// "Izin aksi" layar Peran supaya daftar tidak di-hardcode terpisah dari server.
export function listPermissionCatalog() {
  return client.get('/permission-catalog').then((r) => r.data);
}
