import client from './client';
import { saveBlob } from '../utils/saveBlob';

/**
 * 042-dashboard-revamp — klien untuk GET /dashboard/* (contracts/api-dashboard.md). Semua rute dijaga menu
 * `dashboard` di server; klien ini tidak menebak izin. Parameter `undefined`/kosong tidak dikirim (axios membuang
 * `undefined`), sehingga pemanggil boleh meneruskan seluruh keadaan URL apa adanya.
 */

/** Buang nilai kosong/array kosong supaya query string tetap bersih dan cocok dengan validasi server (422 untuk '' pada enum). */
function clean(params = {}) {
  const out = {};
  Object.entries(params).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return;
    if (Array.isArray(v) && v.length === 0) return;
    out[k] = v;
  });
  return out;
}

/** period = today | 7d | 30d, outlet_id opsional (Stage B). */
export function summary(params) {
  return client.get('/dashboard/summary', { params: clean(params) }).then((r) => r.data);
}

export function attention(params) {
  return client.get('/dashboard/attention', { params: clean(params) }).then((r) => r.data);
}

/** tab, q, type[], method[], pstat[], from, to, outlet_id, page, per_page, sort. */
export function orders(params) {
  return client.get('/dashboard/orders', { params: clean(params) }).then((r) => r.data);
}

/**
 * CSV diunduh lewat axios sebagai blob (bukan tautan langsung): autentikasi memakai header Bearer, yang tidak
 * ikut terkirim pada navigasi biasa — karena itu tidak ada "exportOrdersUrl". Mengembalikan blob agar bisa diuji.
 */
export function exportOrdersBlob(params) {
  return client.get('/dashboard/orders/export', { params: clean(params), responseType: 'blob' }).then((r) => r.data);
}

export async function downloadOrdersCsv(params) {
  const blob = await exportOrdersBlob(params);
  saveBlob(blob, `order-terbaru-${new Date().toISOString().slice(0, 10)}.csv`);
}

export function exportDashboardBlob(params) {
  return client.get('/dashboard/export', { params: clean(params), responseType: 'blob' }).then((r) => r.data);
}

/** Ekspor ringkasan Dashboard (empat angka + tren + item perlu tindakan) untuk periode terpilih. */
export async function exportDashboard(params) {
  const blob = await exportDashboardBlob(params);
  saveBlob(blob, `dashboard-${params?.period ?? 'today'}-${new Date().toISOString().slice(0, 10)}.csv`);
}
