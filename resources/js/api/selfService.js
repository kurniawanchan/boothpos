import axios from 'axios';
import { normalizeAxiosError } from '../utils/errors';

// 090 — klien HALAMAN PUBLIK pesan mandiri. Sengaja instance axios TERPISAH dari `api/client.js`:
//  - tidak mengirim token/Authorization atau X-Outlet-Id (pelanggan tidak punya akun; outlet datang dari token QR);
//  - tidak ada pengalihan ke /login pada 401 dan tidak ada toast global untuk 409/403 — halaman ini menampilkan
//    galatnya sendiri (item tidak tersedia, QR tidak sah, dll.) di tempat yang tepat.
const ss = axios.create({ baseURL: '/api/v1/self-service' });

ss.interceptors.response.use(
  (response) => response,
  (error) => Promise.reject(normalizeAxiosError(error)),
);

/** Konteks outlet/meja/pajak/QRIS. Token QR dari path halaman. */
export async function getContext(token) {
  return (await ss.get(`/${token}/context`)).data.data;
}

/** Menu outlet QR; `params`: { q, category_id }. */
export async function getMenu(token, params = {}) {
  return (await ss.get(`/${token}/menu`, { params })).data.data;
}

/** Unggah bukti bayar → { proof_token }. `via`: 'webcam' (kamera) | 'upload' (galeri). */
export async function uploadProof(token, file, via) {
  const body = new FormData();
  body.append('file', file);
  body.append('captured_via', via);

  return (await ss.post(`/${token}/proofs`, body)).data;
}

/** Kirim pesanan → data ringkas (receipt_token, reference_no, status, total, …). */
export async function submitOrder(token, payload) {
  return (await ss.post(`/${token}/orders`, payload)).data.data;
}

/** Struk pelanggan (data daftar-putih + status terbaru) lewat token struk acak, bukan id. */
export async function getReceipt(receiptToken) {
  return (await ss.get(`/receipts/${receiptToken}`)).data.data;
}
