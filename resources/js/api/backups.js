import client from './client';

// Cadangan & pemulihan database (Pengaturan). Owner/admin saja di server.
// Kata konfirmasi pemulihan ("RESTORE") juga ditegakkan server — dialog di
// frontend hanya membantu pengguna, bukan penjaga.
const CONFIRM_WORD = 'RESTORE';

export function listBackups() {
  return client.get('/backups').then((r) => r.data);
}

export function createBackup() {
  return client.post('/backups').then((r) => r.data);
}

/** Berkas .sql mentah (Blob). */
export function downloadBackup(id) {
  return client.get(`/backups/${id}/download`, { responseType: 'blob' }).then((r) => r.data);
}

/** Hapus satu cadangan lokal (folder + isinya). Salinan eksternal tidak tersentuh. */
export function deleteBackup(id) {
  return client.delete(`/backups/${id}`).then((r) => r.data);
}

export function restoreBackup(id) {
  return client.post(`/backups/${id}/restore`, { confirm: CONFIRM_WORD }).then((r) => r.data);
}

export function restoreFromUpload(file) {
  const form = new FormData();
  form.append('file', file);
  form.append('confirm', CONFIRM_WORD);

  return client.post('/backups/restore-upload', form, { headers: { 'Content-Type': 'multipart/form-data' } }).then((r) => r.data);
}
