import { resolveAppName } from './appName';

/**
 * Judul tab browser: "{nama toko} – {nama aplikasi}". Nama toko kosong berarti hanya nama aplikasi, dan nama
 * toko yang sama dengan nama aplikasi (mis. keduanya "Warkop WJM") ditulis sekali saja, supaya tab tidak
 * terbaca "Warkop WJM – Warkop WJM". Satu tempat untuk aturan ini; composable hanya memasangnya ke dokumen.
 */
export function buildDocumentTitle(storeName, appName) {
  const app = resolveAppName(appName);
  const store = String(storeName ?? '').trim();

  if (!store || store.toLowerCase() === app.toLowerCase()) return app;

  return `${store} – ${app}`;
}
