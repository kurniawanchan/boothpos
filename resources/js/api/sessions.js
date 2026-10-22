import client from './client';

/** Resolves null (not an error) when there is no open session — a 404 here is expected. */
export function currentSession() {
  return client
    .get('/sessions/current')
    .then((r) => r.data)
    .catch((err) => {
      if (err.status === 404) return null;
      throw err;
    });
}

export function openSession(payload) {
  return client.post('/sessions', payload).then((r) => r.data);
}

export function closeSession(id, payload) {
  return client.post(`/sessions/${id}/close`, payload).then((r) => r.data);
}

export function sessionSummary(id) {
  return client.get(`/sessions/${id}/summary`).then((r) => r.data);
}

/**
 * 065 — daftar shift (GET /sessions). Owner/admin: semua shift (aplikasi dan web); peran lain: hanya miliknya
 * (ditegakkan server). `status` = open | closed | all, `source` = app | web.
 */
export function listSessions(params = {}) {
  return client.get('/sessions', { params: cleanParams(params) }).then((r) => r.data);
}

/** 065 — kas masuk/keluar lintas shift (GET /cash-movements); `totals` mencakup seluruh hasil yang disaring. */
export function listCashMovements(params = {}) {
  return client.get('/cash-movements', { params: cleanParams(params) }).then((r) => r.data);
}

/** 065 — kas masuk/keluar satu shift (rincian shift). */
export function sessionCashMovements(id) {
  return client.get(`/sessions/${id}/cash-movements`).then((r) => r.data.data ?? []);
}

/** Parameter kosong tidak dikirim (server menolak '' pada enum/tanggal dengan 422). */
function cleanParams(params) {
  const out = {};
  Object.entries(params).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return;
    out[k] = v;
  });
  return out;
}
