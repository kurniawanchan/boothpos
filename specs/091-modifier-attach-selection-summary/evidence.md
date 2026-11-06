# Bukti: 091 (2026-11-06)

## Terverifikasi
- `qa-tests/component/ModifierGroupsAdmin.test.js`: 20 tes lulus (11 lama + 9 baru: "Tidak ada" kosong, simpan kosong tetap `{product_ids: [], category_ids: []}`, nama produk/kategori di bawah isian, tanpa daftar untuk 1 pilihan, mengikuti centang/lepas, id tanpa padanan, payload simpan identik, batas tinggi + gulir untuk 40 produk). Tes "Tidak ada" ditulis dulu dan gagal ("Semua") sebelum perbaikan; tes nama gagal sebelum elemen ada.
- Seluruh Vitest di kontainer `node`: 250 berkas, 2107 lulus, 2 dilewati, 0 gagal (termasuk tes `BaseMultiSelect`: bawaan "Semua" tanpa `allLabel` tidak berubah).
- Tanpa perubahan server/API/OpenAPI; payload simpan tidak berubah.

## Bug sesudah dirilis ke pengguna (2026-11-06): "N dipilih" tak sesuai nama
- Laporan: "7 selected" tetapi hanya 4–6 nama; "2 selected" tanpa nama terlihat.
- Akar masalah (dari data dev, bukan tebakan): dialog memuat produk dengan `per_page: 100` (= batas server) dan TIDAK membaca halaman berikutnya; katalog dev punya 212 produk. Produk yang hilang dari nama semuanya di urutan alfabet akhir (Susu Jahe, Susu Putih, Milo, Nutrisari, Mie Level, Pangsit Chilli Oil), sedangkan yang tampil di urutan awal (Chocolatos, Dancow, Drink). Hitungan "N" datang dari id tersimpan di grup (benar: 7), nama dari daftar yang terpotong.
- Sudah ada SEBELUM fitur 091 (daftar pilihan produk pun terpotong, jadi produk > 100 tak bisa dilampirkan lewat dialog); fitur 091 hanya membuatnya terlihat.
- Perbaikan: `fetchAllPages` di `ModifierGroupsAdmin.vue` memuat semua halaman untuk produk, kategori, dan bahan (satu pembaca, menggantikan loop bahan yang ada). Tes regresi: "loads every page of products and categories…" (gagal dulu, lulus sekarang). Seluruh Vitest: 250 berkas, 2108 lulus, 2 dilewati, 0 gagal.
- Belum diverifikasi di peramban: bahwa grup "Suhu Minuman (+1000/+2000)" dan "Level Pedas" kini menampilkan 7/7/2 nama. Id tersimpan di dev DB: +1000 = 688, 689, 698, 753, 1157, 1158, 1171; +2000 = 694, 695, 732, 754, 1163, 1164, 1691; Level Pedas (live) = 725, 735.

## Belum diverifikasi
- **T012 peramban**: server MCP peramban (playwright, chrome-devtools) gagal tersambung di sesi ini, jadi tampilan nyata (tata letak dialog, jarak `-mt-2.5`, gulir daftar 120px) BELUM dilihat. Cek manual: `localhost:8010/dining-setup` → Modifiers → Lampirkan "Suhu Minuman", dan satu layar lain pemakai `BaseMultiSelect` (filter tetap "Semua"). Tes yang memeriksa kelas CSS bukan bukti visual.
- Perubahan baru-baru ini pada `node_modules` kontainer `node` (qrcode) tidak berkaitan dengan fitur ini.

## Temuan
- `BaseMultiSelect` menampilkan `allLabel` (bawaan "Semua") saat kosong; `placeholder` hanya untuk id basi. Dialog Attach sebelumnya memberi `placeholder` "Tidak ada" yang tak pernah tampil.
- Pemicu `BaseMultiSelect` tidak dikaitkan ke labelnya (nama aksesibelnya = teks tampil), sehingga tes mencari tombol lewat teks. Tidak diubah (di luar cakupan).
