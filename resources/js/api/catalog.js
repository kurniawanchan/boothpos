import client from './client';

/**
 * GET /catalog — katalog varian aktif per penjual (049). Params sama dengan daftar produk:
 * search, artist_id[], category_id[]. Dipakai membangun PDF di browser; berisi identitas toko,
 * dan per varian: kode, harga jual, gambar, serta pola barcode Code 128 dari server.
 */
export function getCatalog(params = {}) {
  return client.get('/catalog', { params }).then((r) => r.data);
}

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

/**
 * GET /catalog/export — katalog Excel (store, grup penjual, gambar + barcode tertanam). Params sama dengan
 * getCatalog. Nama berkas mengikuti kontrak server (katalog-produk.xlsx). Dialog sudah memuat katalog JSON
 * lebih dulu, jadi cakupan kosong/terlalu besar tidak pernah sampai ke tombol ini; bila tetap gagal (mis. data
 * berubah di antaranya) pemanggil menampilkan pesan umum — badan galat berupa Blob tidak dibaca di sini.
 */
export async function downloadCatalogExcel(params = {}) {
  const response = await client.get('/catalog/export', { params, responseType: 'blob' });
  downloadBlob(response.data, 'katalog-produk.xlsx');
}
